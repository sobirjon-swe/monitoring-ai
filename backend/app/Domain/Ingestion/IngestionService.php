<?php

namespace App\Domain\Ingestion;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class IngestionService
{
    public function __construct(private readonly AccessGateway $access, private readonly TenantContext $context, private readonly IngestionEnvelopeValidator $validator) {}

    public function accept(TrustedActor $actor, string $connectorId, array $envelope, ?string $rawPayload = null): array
    {
        return $this->context->run($actor->tenantId, function () use ($actor, $connectorId, $envelope, $rawPayload): array {
            // Hold the connector binding/status stable until acceptance commits.
            $connector = DB::table('ingestion_connectors')->where('id', $connectorId)->sharedLock()->first() ?? throw new NotFoundHttpException;
            $this->access->authorize($actor, 'ingestion.accept', ['tenant_id' => $actor->tenantId, 'project_id' => $connector->project_id]);
            if ($connector->status !== 'active') {
                throw new ConflictHttpException;
            }
            $valid = $this->validator->validate($envelope);
            $canonical = $this->validator->canonical($valid);
            $rawPayload ??= $canonical;
            if ($this->validator->canonical($this->validator->validateJson($rawPayload)) !== $canonical) {
                throw ValidationException::withMessages(['envelope' => 'Invalid envelope.']);
            }
            // A stable source/object lock spans versions and projects. Hash collisions only serialize extra work.
            $lockKey = json_encode([$actor->tenantId, $connector->source_instance_id, 'ticket', $valid['source_object_id']], JSON_THROW_ON_ERROR);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$lockKey]);
            $history = DB::table('ingestion_receipts')->where('source_instance_id', $connector->source_instance_id)->where('source_object_type', 'ticket')->where('source_object_id', $valid['source_object_id']);
            if ((clone $history)->where('project_id', '<>', $connector->project_id)->exists()) {
                throw new ConflictHttpException;
            }
            $hash = hash('sha256', $canonical);
            $existing = $history->where('source_version', $valid['source_version'])->where('event_kind', $valid['event_kind'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->canonical_hash, $hash)) {
                    throw new ConflictHttpException;
                }

                return ['receipt_id' => $existing->id, 'duplicate' => true];
            }
            $id = (string) Str::uuid();
            DB::table('ingestion_receipts')->insert([
                'id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $connector->project_id, 'connector_id' => $connectorId,
                'source_instance_id' => $connector->source_instance_id, 'source_object_type' => 'ticket', 'accepted_by' => $actor->id,
                'source_object_id' => $valid['source_object_id'], 'source_version' => $valid['source_version'], 'event_kind' => $valid['event_kind'],
                'payload_bytes' => $rawPayload, 'payload_json' => $canonical, 'transport_hash' => hash('sha256', $rawPayload), 'canonical_hash' => $hash,
                'occurred_at' => $this->utc($valid['occurred_at']), 'source_updated_at' => $this->utc($valid['source_updated_at']),
                'received_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP'),
            ]);
            DB::table('ingestion_outbox')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $actor->tenantId, 'project_id' => $connector->project_id, 'receipt_id' => $id]);

            return ['receipt_id' => $id, 'duplicate' => false];
        });
    }

    private function utc(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.uP');
    }
}
