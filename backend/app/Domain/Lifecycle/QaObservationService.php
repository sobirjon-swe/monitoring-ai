<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class QaObservationService
{
    public function __construct(private readonly ProjectCoordination $coordination, private readonly EvidenceService $evidence, private readonly LifecycleChangeWriter $changes, private readonly AuditWriter $audit) {}

    public function record(TrustedActor $actor, string $projectId, array $data, int $expectedRevision, int $expectedGeneration): array
    {
        $this->evidence->validate($data, true);
        if ($expectedRevision < 0) {
            $this->evidence->invalid();
        }
        $r = $this->coordination->run($actor, $projectId, 'evidence.review', $expectedGeneration, function () use ($actor, $projectId, $data, $expectedRevision): array {
            $this->evidence->sourceAccess($actor, $projectId);
            $cv = $this->evidence->criterion($projectId, $data['criterion_version_id']);
            $l = $this->evidence->link($projectId, $data['link_id']);
            if ($l->criterion_version_id !== $cv->id || ! in_array($data['test_key'], json_decode($cv->required_test_keys, true), true)) {
                $this->evidence->invalid();
            }
            $this->evidence->receipt($projectId, $l->receipt_id, $l->canonical_hash, $l->locator);
            $review = DB::table('evidence_review_heads as h')->join('evidence_reviews as r', 'r.id', '=', 'h.current_review_id')->where('h.project_id', $projectId)->where('h.link_id', $l->id)->first(['r.decision']);
            if (! $review || $review->decision !== 'reviewed') {
                throw new ConflictHttpException;
            }
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $projectId];
            $identity = DB::table('qa_test_identities')->where('project_id', $projectId)->where('criterion_id', $cv->criterion_id)->where('test_key', $data['test_key'])->first();
            $identityId = $identity->id ?? (string) Str::uuid();
            $head = DB::table('qa_observation_heads')->where('project_id', $projectId)->where('test_identity_id', $identityId)->first();
            if (($head->revision ?? 0) !== $expectedRevision) {
                throw new ConflictHttpException;
            }
            if (! $identity) {
                DB::table('qa_test_identities')->insert(['id' => $identityId, 'criterion_id' => $cv->criterion_id, 'test_key' => $data['test_key']] + $scope);
            }
            $id = (string) Str::uuid();
            $revision = $expectedRevision + 1;
            DB::table('qa_observations')->insert(['id' => $id, 'criterion_id' => $cv->criterion_id, 'criterion_version_id' => $cv->id, 'test_identity_id' => $identityId, 'test_key' => $data['test_key'], 'link_id' => $l->id, 'result' => $data['result'], 'revision' => $revision, 'actor_id' => $actor->id] + $scope);
            if ($head) {
                DB::table('qa_observation_heads')->where('project_id', $projectId)->where('test_identity_id', $identityId)->update(['revision' => $revision, 'current_observation_id' => $id]);
            } else {
                DB::table('qa_observation_heads')->insert(['test_identity_id' => $identityId, 'revision' => $revision, 'current_observation_id' => $id] + $scope);
            }
            $this->changes->record($actor, $projectId, 'evidence.review', 'qa_changed', $cv->criterion_id, $id, 'Synthetic QA observation');
            $this->audit->record($actor->id, $scope, 'qa.recorded', ['type' => 'qa_observations', 'id' => $id], $revision, 'approved_change');
            $this->evidence->sourceAccess($actor, $projectId);

            return ['id' => $id, 'test_identity_id' => $identityId, 'revision' => $revision, 'state' => $data['result'], 'synthetic' => true];
        });

        return $r['result'] + ['generation' => $r['generation']];
    }
}
