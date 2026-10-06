<?php

namespace App\Domain\Ingestion;

use App\Domain\Identity\IdentityMappingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TicketSnapshotProjector
{
    public function __construct(private readonly IdentityMappingService $identities) {}

    public function apply(object $receipt): array
    {
        $tenant = DB::scalar("select nullif(current_setting('app.tenant_id', true), '')");
        if ($tenant === null || DB::transactionLevel() === 0) {
            throw new LogicException('Projection requires an authorized tenant transaction.');
        }
        // Only the persisted immutable receipt is authoritative; caller fields are never projected.
        if (! isset($receipt->id) || ! Str::isUuid($receipt->id)) {
            throw new NotFoundHttpException;
        }
        $r = DB::table('ingestion_receipts')->where('id', $receipt->id)->where('tenant_id', $tenant)->first() ?? throw new NotFoundHttpException;
        $key = json_encode([$tenant, $r->connector_id, $r->source_object_id], JSON_THROW_ON_ERROR);
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        $query = DB::table('ingestion_projections')->where('connector_id', $r->connector_id)->where('source_object_id', $r->source_object_id);
        $current = (clone $query)->lockForUpdate()->first();
        if ($current !== null && $current->current_receipt_id === $r->id) {
            return $this->result(false, 'already_current', json_decode($current->coverage_flags, true));
        }
        if ($current !== null) {
            $flags = json_decode($current->coverage_flags, true);
            if ($r->source_updated_at === null || $current->source_updated_at === null) {
                $reason = 'ordering_unknown';
            } else {
                $incoming = CarbonImmutable::parse($r->source_updated_at);
                $existing = CarbonImmutable::parse($current->source_updated_at);
                if ($incoming->lessThan($existing)) {
                    return $this->result(false, 'older_snapshot', $flags);
                }
                $reason = $incoming->equalTo($existing) ? 'ordering_conflict' : null;
                if ($reason !== null) {
                    $old = DB::table('ingestion_receipts')->where('id', $current->current_receipt_id)->first();
                    if (json_decode($old->payload_json, true)['payload'] === json_decode($r->payload_json, true)['payload']) {
                        return $this->result(false, 'equivalent_snapshot', $flags);
                    }
                }
            }
            if ($reason !== null) {
                $flags = array_values(array_unique([...$flags, $reason]));
                $query->update(['coverage_flags' => json_encode($flags, JSON_THROW_ON_ERROR)]);

                return $this->result(false, $reason, $flags);
            }
        }
        $payload = json_decode($r->payload_json, true, flags: JSON_THROW_ON_ERROR)['payload'];
        $attribution = null;
        if ($payload['external_actor_id'] !== null) {
            $identity = DB::table('identities')->where('source_instance_id', $r->source_instance_id)->where('external_user_id', $payload['external_actor_id'])->first();
            if ($identity !== null) {
                $attribution = $this->identities->attribution($identity->id, $r->occurred_at);
            }
        }
        $flags = ['source_freshness_unknown'];
        if ($attribution === null) {
            $flags[] = 'identity_unresolved';
        }
        // A newer dated snapshot cannot establish where an undated historical receipt belongs.
        if ($r->source_updated_at === null || ($current !== null && in_array('ordering_unknown', json_decode($current->coverage_flags, true), true))) {
            $flags[] = 'ordering_unknown';
        }
        $state = $payload + ['attribution' => $attribution, 'occurred_at' => CarbonImmutable::parse($r->occurred_at)->utc()->format('Y-m-d\TH:i:s.u\Z'), 'source_version' => $r->source_version];
        $values = ['current_receipt_id' => $r->id, 'source_updated_at' => $r->source_updated_at, 'state' => json_encode($state, JSON_THROW_ON_ERROR), 'coverage_flags' => json_encode($flags, JSON_THROW_ON_ERROR)];
        if ($current === null) {
            DB::table('ingestion_projections')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'project_id' => $r->project_id, 'connector_id' => $r->connector_id, 'source_instance_id' => $r->source_instance_id, 'source_object_type' => $r->source_object_type, 'source_object_id' => $r->source_object_id] + $values);
        } else {
            $query->update($values);
        }

        return $this->result(true, $attribution === null ? 'identity_unresolved' : null, $flags);
    }

    private function result(bool $applied, ?string $reason, array $flags): array
    {
        return ['applied' => $applied, 'reason_code' => $reason, 'coverage_flags' => $flags];
    }
}
