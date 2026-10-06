<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Domain\Ingestion\IngestionEnvelopeValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\CompliantValidator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EvidenceService
{
    public function __construct(private readonly ProjectCoordination $coordination, private readonly AccessGateway $access, private readonly LifecycleChangeWriter $changes, private readonly AuditWriter $audit, private readonly IngestionEnvelopeValidator $envelopes) {}

    public function propose(TrustedActor $actor, string $projectId, array $data, int $expectedGeneration): array
    {
        $this->validate($data, false);
        $r = $this->coordination->run($actor, $projectId, 'evidence.review', $expectedGeneration, function () use ($actor, $projectId, $data): array {
            $this->sourceAccess($actor, $projectId);
            $cv = $this->criterion($projectId, $data['criterion_version_id']);
            $receipt = $this->receipt($projectId, $data['receipt_id'], $data['canonical_hash'], $data['locator']);
            $id = (string) Str::uuid();
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $projectId];
            DB::table('evidence_link_proposals')->insert(['id' => $id, 'criterion_id' => $cv->criterion_id, 'criterion_version_id' => $cv->id, 'receipt_id' => $receipt->id, 'connector_id' => $receipt->connector_id, 'source_instance_id' => $receipt->source_instance_id, 'source_object_type' => $receipt->source_object_type, 'source_object_id' => $receipt->source_object_id, 'canonical_hash' => $receipt->canonical_hash, 'locator' => $data['locator'], 'actor_id' => $actor->id] + $scope);
            $this->changes->record($actor, $projectId, 'evidence.review', 'evidence_changed', $id, $id, 'Synthetic evidence proposal');
            $this->audit->record($actor->id, $scope, 'evidence.proposed', ['type' => 'evidence_link_proposals', 'id' => $id], 1, 'approved_change');
            $this->sourceAccess($actor, $projectId);

            return ['id' => $id, 'revision' => 0, 'canonical_hash' => $receipt->canonical_hash, 'state' => 'proposed'];
        });

        return $r['result'] + ['generation' => $r['generation']];
    }

    public function review(TrustedActor $actor, string $projectId, string $link, string $decision, int $expectedRevision, int $expectedGeneration, string $reason): array
    {
        if (! Str::isUuid($link) || ! in_array($decision, ['reviewed', 'rejected', 'revoked'], true) || $expectedRevision < 0 || ! trim($reason) || mb_strlen($reason) > 500 || str_contains($reason, "\0")) {
            $this->invalid();
        }
        $r = $this->coordination->run($actor, $projectId, 'evidence.review', $expectedGeneration, function () use ($actor, $projectId, $link, $decision, $expectedRevision, $reason): array {
            $this->sourceAccess($actor, $projectId);
            $l = $this->link($projectId, $link);
            $this->criterion($projectId, $l->criterion_version_id);
            $this->receipt($projectId, $l->receipt_id, $l->canonical_hash, $l->locator);
            $head = DB::table('evidence_review_heads')->where('project_id', $projectId)->where('link_id', $link)->first();
            if (($head->revision ?? 0) !== $expectedRevision) {
                throw new ConflictHttpException;
            }
            $id = (string) Str::uuid();
            $revision = $expectedRevision + 1;
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $projectId];
            DB::table('evidence_reviews')->insert(['id' => $id, 'link_id' => $link, 'decision' => $decision, 'revision' => $revision, 'actor_id' => $actor->id, 'reason' => $reason] + $scope);
            if ($head) {
                DB::table('evidence_review_heads')->where('project_id', $projectId)->where('link_id', $link)->update(['revision' => $revision, 'current_review_id' => $id]);
            } else {
                DB::table('evidence_review_heads')->insert(['link_id' => $link, 'revision' => $revision, 'current_review_id' => $id] + $scope);
            }
            $this->changes->record($actor, $projectId, 'evidence.review', 'evidence_changed', $link, $id, $reason);
            $this->audit->record($actor->id, $scope, 'evidence.reviewed', ['type' => 'evidence_reviews', 'id' => $id], $revision, 'approved_change');
            $this->sourceAccess($actor, $projectId);

            return ['id' => $id, 'link_id' => $link, 'revision' => $revision, 'state' => $decision];
        });

        return $r['result'] + ['generation' => $r['generation']];
    }

    public function sourceAccess(TrustedActor $actor, string $project): void
    {
        $this->access->authorize($actor, 'ingestion.read', ['tenant_id' => $actor->tenantId, 'project_id' => $project]);
    }

    public function criterion(string $project, string $id): object
    {
        if (! Str::isUuid($id)) {
            $this->invalid();
        }

        return DB::table('criterion_versions')->where('project_id', $project)->where('id', $id)->first() ?? throw new NotFoundHttpException;
    }

    public function link(string $project, string $id): object
    {
        if (! Str::isUuid($id)) {
            $this->invalid();
        }

        return DB::table('evidence_link_proposals')->where('project_id', $project)->where('id', $id)->first() ?? throw new NotFoundHttpException;
    }

    public function receipt(string $project, string $id, string $hash, string $locator): object
    {
        $r = DB::table('ingestion_receipts')->where('project_id', $project)->where('id', $id)->first() ?? throw new NotFoundHttpException;
        $connector = DB::table('ingestion_connectors')->where('project_id', $project)->where('id', $r->connector_id)->where('source_instance_id', $r->source_instance_id)->sharedLock()->first();
        if (! $connector || $connector->adapter !== 'synthetic-v1' || $connector->status !== 'active' || ! hash_equals($r->canonical_hash, $hash) || ! in_array($locator, ['payload.title', 'payload.status', 'payload.external_ticket_id'], true)) {
            $this->invalid();
        }
        $e = $this->envelopes->validateJson($r->payload_bytes);
        $canonical = $this->envelopes->canonical($e);
        $stored = $this->envelopes->canonical($this->envelopes->validate(json_decode($r->payload_json, true, 16, JSON_THROW_ON_ERROR)));
        if (! hash_equals($r->transport_hash, hash('sha256', $r->payload_bytes)) || ! hash_equals($hash, hash('sha256', $canonical)) || $stored !== $canonical || $e['source_object_id'] !== $r->source_object_id || $e['source_version'] !== $r->source_version || $e['event_kind'] !== $r->event_kind || ! is_string($e['payload'][substr($locator, 8)] ?? null)) {
            $this->invalid();
        }

        return $r;
    }

    public function validate(array $data, bool $qa): void
    {
        $schema = json_decode(file_get_contents(base_path('../contracts/lifecycle-evidence-v1.schema.json')), false, 16, JSON_THROW_ON_ERROR);
        $object = json_decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 16, JSON_THROW_ON_ERROR);
        if (! (new CompliantValidator)->validate($object, $schema)->isValid() || strlen(json_encode($data)) > 65536 || array_key_exists('link_id', $data) !== $qa) {
            $this->invalid();
        }
        foreach ($data as $v) {
            if (! is_string($v) || str_contains($v, "\0") || ! trim($v)) {
                $this->invalid();
            }
        }
    }

    public function invalid(): never
    {
        throw ValidationException::withMessages(['evidence' => 'Invalid evidence input.']);
    }
}
