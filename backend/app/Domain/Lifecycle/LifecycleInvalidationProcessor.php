<?php

namespace App\Domain\Lifecycle;

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
use Throwable;

class LifecycleInvalidationProcessor
{
    public function __construct(private readonly TenantContext $context, private readonly AccessGateway $access, private readonly ProjectCoordination $coordination, private readonly ApplicabilityRebuilder $rebuilder) {}

    public function drain(TrustedActor $actor, string $project, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid batch limit.');
        }
        $counts = array_fill_keys(['claimed', 'completed', 'retry', 'dead_letter', 'blocked', 'stale'], 0);
        for ($i = 0; $i < $limit; $i++) {
            $claim = $this->claimNext($actor, $project);
            if ($claim === null) {
                break;
            }
            $counts['claimed']++;
            $result = $claim->status === 'dead_letter' ? 'dead_letter' : $this->deliver($actor, $project, $claim->id, $claim->lease_token);
            $counts[$result]++;
        }

        return $counts;
    }

    /** Claim commits independently before apply; source ingestion outbox is never consumed here. */
    public function claimNext(TrustedActor $actor, string $project): ?object
    {
        $this->ids($actor, $project);

        return $this->context->run($actor->tenantId, function () use ($actor, $project) {
            $this->authorize($actor, $project);
            $instant = $this->now();
            $row = DB::table('lifecycle_outbox')->where('project_id', $project)->where('tenant_id', $actor->tenantId)->where(function ($q) use ($instant) {
                $q->where(fn ($q) => $q->whereIn('status', ['pending', 'retry'])->where('available_at', '<=', $instant))->orWhere(fn ($q) => $q->where('status', 'processing')->where('lease_until', '<=', $instant));
            })->orderBy('available_at')->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
            if (! $row) {
                return null;
            }
            $values = $row->attempt_count >= 5
                ? ['status' => 'dead_letter', 'lease_token' => null, 'lease_until' => null, 'reason_code' => 'processing_failed']
                : ['status' => 'processing', 'attempt_count' => $row->attempt_count + 1, 'lease_token' => (string) Str::uuid(), 'lease_until' => CarbonImmutable::now('UTC')->addSeconds(60)->format('Y-m-d H:i:s.uP'), 'reason_code' => null];
            DB::table('lifecycle_outbox')->where('id', $row->id)->update($values);
            $this->authorize($actor, $project);

            return (object) (['id' => $row->id] + $values);
        });
    }

    public function deliver(TrustedActor $actor, string $project, string $outboxId, string $leaseToken): string
    {
        $this->ids($actor, $project);
        if (! Str::isUuid($outboxId) || ! Str::isUuid($leaseToken)) {
            throw new InvalidArgumentException('Invalid lease identifiers.');
        }
        try {
            return $this->coordination->read($actor, $project, 'lifecycle.process', function () use ($actor, $project, $outboxId, $leaseToken) {
                $metadata = $this->lease($project, $outboxId, $leaseToken)->first();
                if (! $metadata) {
                    return 'stale';
                }
                $change = DB::table('lifecycle_changes')->where('project_id', $project)->where('id', $metadata->change_id)->first() ?? throw new RuntimeException;
                $this->rebuilder->authorizeChange($actor, $change);
                $row = $this->lease($project, $outboxId, $leaseToken)->lockForUpdate()->first();
                if (! $row) {
                    return 'stale';
                }
                $result = $this->rebuilder->apply($actor, $change);
                $reason = in_array('access_revoked', $result['reason_codes'], true) ? 'access_revoked' : ($result['stale'] ? 'input_stale' : null);
                $updated = $this->lease($project, $outboxId, $leaseToken)->update(['status' => 'completed', 'lease_token' => null, 'lease_until' => null, 'completed_at' => $this->now(), 'reason_code' => $reason]);
                if ($updated !== 1) {
                    throw new RuntimeException;
                }

                return 'completed';
            });
        } catch (AccessDeniedHttpException) {
            return $this->fail($actor, $project, $outboxId, $leaseToken, 'access_revoked');
        } catch (Throwable) {
            // Never retain exception text, bindings, source data or a client-selected handler.
            return $this->fail($actor, $project, $outboxId, $leaseToken, 'processing_failed');
        }
    }

    private function fail(TrustedActor $actor, string $project, string $id, string $token, string $reason): string
    {
        return $this->context->run($actor->tenantId, function () use ($project, $id, $token, $reason) {
            $row = $this->lease($project, $id, $token)->lockForUpdate()->first();
            if (! $row) {
                return 'stale';
            }
            $status = $reason === 'access_revoked' ? 'blocked' : ($row->attempt_count >= 5 ? 'dead_letter' : 'retry');
            $values = ['status' => $status, 'lease_token' => null, 'lease_until' => null, 'reason_code' => $reason];
            if ($status === 'retry') {
                $values['available_at'] = CarbonImmutable::now('UTC')->addSeconds([5, 30, 120, 600][$row->attempt_count - 1])->format('Y-m-d H:i:s.uP');
            }
            if ($this->lease($project, $id, $token)->update($values) !== 1) {
                return 'stale';
            }

            return $status;
        });
    }

    private function authorize(TrustedActor $actor, string $project): void
    {
        $this->access->authorize($actor, 'lifecycle.process', ['tenant_id' => $actor->tenantId, 'project_id' => $project]);
    }

    private function lease(string $project, string $id, string $token): Builder
    {
        return DB::table('lifecycle_outbox')->where('project_id', $project)->where('id', $id)->where('status', 'processing')->where('lease_token', $token)->where('lease_until', '>', $this->now());
    }

    private function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
    }

    private function ids(TrustedActor $actor, string $project): void
    {
        if (! Str::isUuid($actor->id) || ! Str::isUuid($actor->tenantId) || ! Str::isUuid($project)) {
            throw new InvalidArgumentException('Invalid scope identifiers.');
        }
    }
}
