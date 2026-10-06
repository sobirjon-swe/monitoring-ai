<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\TrustedActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CurrentInputEligibility
{
    public function __construct(private readonly EvidenceService $evidence) {}

    /** Caller holds ProjectCoordination read/mutation lock and independently authorizes its own purpose. */
    public function check(TrustedActor $actor, string $projectId, string $criterionVersionId, string $dodPolicyVersionId, array $reviewedLinkIds): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.tenant_id',true),'')") !== $actor->tenantId) {
            throw new LogicException('Eligibility requires authorized coordination context.');
        }
        try {
            $this->evidence->sourceAccess($actor, $projectId);
        } catch (AccessDeniedHttpException) {
            return $this->result('unknown', ['source_access_unavailable']);
        }
        if (! Str::isUuid($criterionVersionId) || ! Str::isUuid($dodPolicyVersionId) || count($reviewedLinkIds) > 100 || ! array_is_list($reviewedLinkIds) || count(array_unique($reviewedLinkIds, SORT_REGULAR)) !== count($reviewedLinkIds)) {
            return $this->result('unknown', ['invalid_input']);
        }
        foreach ($reviewedLinkIds as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                return $this->result('unknown', ['invalid_input']);
            }
        }
        $cv = DB::table('criterion_versions')->where('project_id', $projectId)->where('id', $criterionVersionId)->first();
        $policy = DB::table('dod_policy_versions')->where('project_id', $projectId)->where('id', $dodPolicyVersionId)->first();
        if (! $cv || ! $policy) {
            return $this->result('unknown', ['input_unavailable']);
        }
        $criterion = DB::table('criteria')->where('project_id', $projectId)->where('id', $cv->criterion_id)->first();
        $latest = DB::table('requirement_versions')->where('project_id', $projectId)->where('requirement_id', $criterion->requirement_id)->orderByDesc('version')->first();
        if (! $latest || ! DB::table('requirement_version_criteria')->where('project_id', $projectId)->where('requirement_version_id', $latest->id)->where('criterion_version_id', $cv->id)->exists()) {
            return $this->result('stale', ['criterion_replaced']);
        }
        $pins = ['criterion' => ['id' => $cv->criterion_id, 'version_id' => $cv->id, 'version' => $cv->version, 'context_hash' => $cv->context_hash, 'content_hash' => $cv->content_hash], 'policy' => ['id' => $policy->id, 'content_hash' => $policy->content_hash], 'links' => [], 'qa' => []];
        $reasons = [];
        $stale = false;
        sort($reviewedLinkIds, SORT_STRING);
        if (! $reviewedLinkIds) {
            $reasons[] = 'evidence_missing';
        }
        foreach ($reviewedLinkIds as $id) {
            $l = DB::table('evidence_link_proposals')->where('project_id', $projectId)->where('criterion_version_id', $cv->id)->where('id', $id)->first();
            if (! $l) {
                $reasons[] = 'evidence_unavailable';

                continue;
            }
            $review = DB::table('evidence_review_heads as h')->join('evidence_reviews as r', 'r.id', '=', 'h.current_review_id')->where('h.project_id', $projectId)->where('h.link_id', $id)->first(['r.id', 'r.revision', 'r.decision']);
            if (! $review || $review->decision !== 'reviewed') {
                $reasons[] = 'evidence_not_reviewed';
                $stale = $stale || ($review !== null);

                continue;
            }
            try {
                $receipt = $this->evidence->receipt($projectId, $l->receipt_id, $l->canonical_hash, $l->locator);
            } catch (NotFoundHttpException|ValidationException) {
                $reasons[] = 'evidence_unavailable';

                continue;
            }
            $ordering = $this->sourceOrdering($projectId, $receipt);
            if ($ordering !== null) {
                $reasons[] = $ordering;
                $stale = $stale || $ordering === 'source_receipt_superseded';

                continue;
            }
            $pins['links'][] = ['id' => $id, 'receipt_id' => $l->receipt_id, 'canonical_hash' => $l->canonical_hash, 'locator' => $l->locator, 'review_id' => $review->id, 'review_revision' => $review->revision, 'source_version' => $receipt->source_version, 'source_updated_at' => $receipt->source_updated_at];
        }
        $required = json_decode($cv->required_test_keys, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($required) || ! $required || count($required) > 20 || count(array_unique($required)) !== count($required)) {
            $reasons[] = 'required_tests_unavailable';
        } else {
            sort($required, SORT_STRING);
            foreach ($required as $key) {
                $q = DB::table('qa_test_identities as t')->join('qa_observation_heads as h', 'h.test_identity_id', '=', 't.id')->join('qa_observations as o', 'o.id', '=', 'h.current_observation_id')->where('t.project_id', $projectId)->where('t.criterion_id', $cv->criterion_id)->where('t.test_key', $key)->first(['o.id', 'o.criterion_version_id', 'o.link_id', 'o.test_key', 'o.result', 'o.revision']);
                if (! $q || $q->criterion_version_id !== $cv->id) {
                    $reasons[] = 'qa_missing';

                    continue;
                }
                $pins['qa'][] = ['id' => $q->id, 'revision' => $q->revision, 'test_key' => $q->test_key, 'link_id' => $q->link_id, 'result' => $q->result];
                if (! in_array($q->link_id, array_column($pins['links'], 'id'), true)) {
                    $reasons[] = 'qa_evidence_unavailable';
                }
                if ($q->result === 'failed') {
                    $reasons[] = 'qa_failed';
                    $stale = true;
                } elseif ($q->result !== 'passed') {
                    $reasons[] = 'qa_missing';
                }
            }
        }
        try {
            $this->evidence->sourceAccess($actor, $projectId);
        } catch (AccessDeniedHttpException) {
            return $this->result('unknown', ['source_access_unavailable']);
        }

        return $this->result($reasons ? ($stale ? 'stale' : 'unknown') : 'valid', array_values(array_unique($reasons)), $pins);
    }

    private function result(string $status, array $reasons, array $pins = []): array
    {
        return ['status' => $status, 'reason_codes' => $reasons, 'pins' => $pins];
    }

    private function sourceOrdering(string $project, object $receipt): ?string
    {
        // Accepted immutable inputs gate eligibility immediately; projection lag and arrival order are irrelevant.
        $history = DB::table('ingestion_receipts')->where('project_id', $project)->where('connector_id', $receipt->connector_id)->where('source_instance_id', $receipt->source_instance_id)->where('source_object_type', $receipt->source_object_type)->where('source_object_id', $receipt->source_object_id)->where('id', '<>', $receipt->id)->limit(101)->get(['source_updated_at', 'canonical_hash']);
        if ($history->count() > 100) {
            return 'source_history_limit';
        }
        $unknown = false;
        $superseded = false;
        foreach ($history as $other) {
            if ($receipt->source_updated_at === null || $other->source_updated_at === null) {
                $unknown = true;

                continue;
            }
            $otherTime = CarbonImmutable::parse($other->source_updated_at);
            $pinnedTime = CarbonImmutable::parse($receipt->source_updated_at);
            if ($otherTime->greaterThan($pinnedTime)) {
                $superseded = true;
            } elseif ($otherTime->equalTo($pinnedTime) && ! hash_equals($receipt->canonical_hash, $other->canonical_hash)) {
                $unknown = true;
            }
        }

        return $unknown ? 'source_ordering_unknown' : ($superseded ? 'source_receipt_superseded' : null);
    }
}
