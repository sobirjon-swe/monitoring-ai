<?php

namespace App\Domain\Ingestion;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class OutboxProcessor
{
    public function __construct(private readonly TenantContext $context, private readonly AccessGateway $access, private readonly TicketSnapshotProjector $projector) {}

    public function drain(TrustedActor $actor, string $connectorId, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid batch limit.');
        }
        $counts = array_fill_keys(['claimed', 'completed', 'retry', 'dead_letter', 'blocked', 'stale'], 0);
        // Each claim commits before projection begins; a process exit cannot erase the lease.
        for ($i = 0; $i < $limit; $i++) {
            $claim = $this->claimNext($actor, $connectorId);
            if ($claim === null) {
                break;
            }
            $counts['claimed']++;
            $result = $claim->status === 'dead_letter' ? 'dead_letter' : $this->deliver($actor, $connectorId, $claim->id, $claim->lease_token);
            $counts[$result]++;
        }

        return $counts;
    }

    /** Internal local dispatcher boundary; returns lease metadata, never a payload. */
    public function claimNext(TrustedActor $actor, string $connectorId): ?object
    {
        $this->ids($actor, $connectorId);

        return $this->context->run($actor->tenantId, function () use ($actor, $connectorId): ?object {
            $connector = $this->connector($connectorId);
            $this->authorize($actor, $connector->project_id, 'ingestion.process');
            $instant = $this->now();
            $row = DB::table('ingestion_outbox as o')->join('ingestion_receipts as r', 'r.id', '=', 'o.receipt_id')
                ->where('r.connector_id', $connectorId)->where('o.tenant_id', $actor->tenantId)
                ->where(function ($query) use ($instant): void {
                    $query->where(fn ($q) => $q->whereIn('o.status', ['pending', 'retry'])->where('o.available_at', '<=', $instant))
                        ->orWhere(fn ($q) => $q->where('o.status', 'processing')->where('o.lease_until', '<=', $instant));
                })->orderBy('o.available_at')->orderBy('o.id')->lock('FOR UPDATE OF o SKIP LOCKED')
                ->first(['o.id', 'o.status', 'o.attempt_count']);
            if ($row === null) {
                return null;
            }
            if ($row->attempt_count >= 5) {
                $values = ['status' => 'dead_letter', 'lease_token' => null, 'lease_until' => null, 'reason_code' => 'processing_failed'];
            } else {
                $values = ['status' => 'processing', 'attempt_count' => $row->attempt_count + 1, 'lease_token' => (string) Str::uuid(), 'lease_until' => CarbonImmutable::now('UTC')->addSeconds(60)->format('Y-m-d H:i:s.uP'), 'reason_code' => null];
            }
            DB::table('ingestion_outbox')->where('id', $row->id)->update($values);

            return (object) (['id' => $row->id] + $values);
        });
    }

    /** Internal fenced delivery boundary; receipt binding always comes from persisted rows. */
    public function deliver(TrustedActor $actor, string $connectorId, string $outboxId, string $leaseToken): string
    {
        $this->ids($actor, $connectorId);
        if (! Str::isUuid($outboxId) || ! Str::isUuid($leaseToken)) {
            throw new InvalidArgumentException('Invalid lease identifiers.');
        }
        try {
            return $this->context->run($actor->tenantId, function () use ($actor, $connectorId, $outboxId, $leaseToken): string {
                $row = $this->lease($outboxId, $leaseToken)->lockForUpdate()->first();
                if ($row === null) {
                    return 'stale';
                }
                // Authorize metadata before reading source payload; original submitter is separate.
                $receipt = DB::table('ingestion_receipts')->where('id', $row->receipt_id)->first(['id', 'project_id', 'connector_id', 'accepted_by']);
                if ($receipt === null || $receipt->connector_id !== $connectorId) {
                    return 'stale';
                }
                $this->deliveryAuthority($actor, $receipt);
                $result = $this->projector->apply($receipt);
                $this->deliveryAuthority($actor, $receipt);
                $reason = in_array($result['reason_code'], ['identity_unresolved', 'ordering_conflict'], true) ? $result['reason_code'] : null;
                $updated = $this->lease($outboxId, $leaseToken)->update(['status' => 'completed', 'lease_token' => null, 'lease_until' => null, 'completed_at' => $this->now(), 'reason_code' => $reason]);
                if ($updated !== 1) {
                    // Expiry during apply must roll back the projection as well.
                    throw new RuntimeException;
                }

                return 'completed';
            });
        } catch (AccessDeniedHttpException) {
            return $this->fail($actor, $connectorId, $outboxId, $leaseToken, 'access_revoked');
        } catch (ConflictHttpException) {
            return $this->fail($actor, $connectorId, $outboxId, $leaseToken, 'connector_disabled');
        } catch (Throwable) {
            // Never persist, log or print exception text, SQL, bindings, or source content.
            return $this->fail($actor, $connectorId, $outboxId, $leaseToken, 'processing_failed');
        }
    }

    private function fail(TrustedActor $actor, string $connectorId, string $id, string $token, string $reason): string
    {
        return $this->context->run($actor->tenantId, function () use ($connectorId, $id, $token, $reason): string {
            $row = $this->lease($id, $token)->lockForUpdate()->first();
            if ($row === null || ! DB::table('ingestion_receipts')->where('id', $row->receipt_id)->where('connector_id', $connectorId)->exists()) {
                return 'stale';
            }
            $status = $reason !== 'processing_failed' ? 'blocked' : ($row->attempt_count >= 5 ? 'dead_letter' : 'retry');
            $values = ['status' => $status, 'lease_token' => null, 'lease_until' => null, 'reason_code' => $reason];
            if ($status === 'retry') {
                $values['available_at'] = CarbonImmutable::now('UTC')->addSeconds([5, 30, 120, 600][$row->attempt_count - 1])->format('Y-m-d H:i:s.uP');
            }
            $this->lease($id, $token)->update($values);

            return $status;
        });
    }

    private function deliveryAuthority(TrustedActor $actor, object $receipt): void
    {
        $connector = $this->connector($receipt->connector_id);
        $this->authorize($actor, $receipt->project_id, 'ingestion.process');
        $this->authorize(new TrustedActor($receipt->accepted_by, $actor->tenantId), $receipt->project_id, 'ingestion.accept');
        if ($connector->status !== 'active') {
            throw new ConflictHttpException;
        }
    }

    private function connector(string $id): object
    {
        return DB::table('ingestion_connectors')->where('id', $id)->sharedLock()->first() ?? throw new NotFoundHttpException;
    }

    private function authorize(TrustedActor $actor, string $project, string $purpose): void
    {
        $this->access->authorize($actor, $purpose, ['tenant_id' => $actor->tenantId, 'project_id' => $project]);
    }

    private function lease(string $id, string $token): Builder
    {
        return DB::table('ingestion_outbox')->where('id', $id)->where('status', 'processing')->where('lease_token', $token)->where('lease_until', '>', $this->now());
    }

    private function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
    }

    private function ids(TrustedActor $actor, string $connector): void
    {
        if (! Str::isUuid($actor->id) || ! Str::isUuid($actor->tenantId) || ! Str::isUuid($connector)) {
            throw new InvalidArgumentException('Invalid scope identifiers.');
        }
    }
}
