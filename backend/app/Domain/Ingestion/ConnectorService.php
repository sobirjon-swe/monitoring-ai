<?php

namespace App\Domain\Ingestion;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ConnectorService
{
    public function __construct(private readonly AccessGateway $access, private readonly TenantContext $context, private readonly AuditWriter $audit) {}

    public function create(TrustedActor $actor, string $projectId, array $data): object
    {
        $this->authorize($actor, $projectId);

        return $this->context->run($actor->tenantId, function () use ($actor, $projectId, $data): object {
            $source = DB::table('source_instances')->where('id', $data['source_instance_id'])->first() ?? throw new NotFoundHttpException;
            if ($source->kind !== 'manual') {
                throw ValidationException::withMessages(['source_instance_id' => 'A synthetic connector requires a manual source.']);
            }

            return DB::transaction(function () use ($actor, $projectId, $data): object {
                $id = (string) Str::uuid();
                DB::table('ingestion_connectors')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $projectId, 'source_instance_id' => $data['source_instance_id'], 'adapter' => $data['adapter'], 'status' => $data['status'] ?? 'disabled']);
                $this->audit->record($actor->id, ['tenant_id' => $actor->tenantId, 'project_id' => $projectId], 'connector.created', ['type' => 'ingestion_connectors', 'id' => $id], 1, 'approved_change');

                return DB::table('ingestion_connectors')->where('id', $id)->first();
            });
        });
    }

    public function listing(TrustedActor $actor, string $projectId): array
    {
        $this->authorize($actor, $projectId);

        return $this->context->run($actor->tenantId, fn () => DB::table('ingestion_connectors')->where('project_id', $projectId)->orderBy('id')->limit(100)->get()->all());
    }

    public function status(TrustedActor $actor, string $connectorId): array
    {
        return $this->context->run($actor->tenantId, function () use ($actor, $connectorId): array {
            $connector = DB::table('ingestion_connectors')->where('id', $connectorId)->first() ?? throw new NotFoundHttpException;
            $this->authorize($actor, $connector->project_id);
            $counts = DB::table('ingestion_outbox as o')->join('ingestion_receipts as r', function ($join): void {
                $join->on('r.id', '=', 'o.receipt_id')->on('r.tenant_id', '=', 'o.tenant_id')->on('r.project_id', '=', 'o.project_id');
            })->where('r.connector_id', $connectorId)->selectRaw('o.status, count(*) as count')->groupBy('o.status')->pluck('count', 'status')->all();
            $receipts = DB::table('ingestion_receipts')->where('connector_id', $connectorId);
            $lastProcessed = DB::table('ingestion_outbox as o')->join('ingestion_receipts as r', 'r.id', '=', 'o.receipt_id')->where('r.connector_id', $connectorId)->where('o.status', 'completed')->max('o.completed_at');
            $coverage = ['source_freshness_unknown'];
            // Aggregate only the fixed vocabulary; never serialize state or arbitrary stored strings.
            foreach (['identity_unresolved', 'ordering_unknown', 'ordering_conflict'] as $code) {
                if (DB::table('ingestion_projections')->where('connector_id', $connectorId)->whereJsonContains('coverage_flags', $code)->exists()) {
                    $coverage[] = $code;
                }
            }

            return ['connector' => $connector, 'receipt_count' => (clone $receipts)->count(), 'outbox_counts' => $counts,
                'last_received_at' => (clone $receipts)->max('received_at'), 'last_processed_at' => $lastProcessed,
                'pending_count' => (int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0) + (int) ($counts['retry'] ?? 0),
                'dead_letter_count' => (int) ($counts['dead_letter'] ?? 0), 'coverage_reason_codes' => $coverage];
        });
    }

    private function authorize(TrustedActor $actor, string $project): void
    {
        $this->access->authorize($actor, 'connector.manage', ['tenant_id' => $actor->tenantId, 'project_id' => $project]);
    }
}
