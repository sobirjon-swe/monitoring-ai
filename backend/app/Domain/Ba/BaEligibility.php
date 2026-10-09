<?php

namespace App\Domain\Ba;

use Carbon\CarbonImmutable;

final class BaEligibility
{
    public function evaluate(array $graph, array $proofs): array
    {
        $result = [];
        foreach ($graph['rows']['clarification'] ?? [] as $id => $root) {
            $root = (object) $root;
            $request = $graph['rows']['request'][$root->current_request_id] ?? null;
            $answer = $graph['rows']['answer'][$root->current_answer_id] ?? null;
            $review = $graph['rows']['review'][$root->current_review_id] ?? null;
            $reasons = [];
            $keys = [];
            $sourceHeads = [];
            $clock = $proofs['current']['proof']['captured_at'] ?? null;
            $applicability = ($graph['coverage'] ?? 'unknown') === 'complete' ? 'current' : 'unknown';
            $mandatory = ['request' => $request];
            if ($answer) {
                $mandatory['answer'] = $answer;
            }
            if ($review) {
                $mandatory['review'] = $review;
            }
            foreach ($mandatory as $kind => $row) {
                if (! $row) {
                    $applicability = $this->worse($applicability, 'unknown');
                    $reasons[] = 'missing_'.$kind;

                    continue;
                }
                $key = $graph['proof_keys'][$kind.':'.$row->id] ?? null;
                if ($key) {
                    $keys[] = $key;
                }
                if (! ($proofs[$key]['valid'] ?? false)) {
                    $reason = $proofs[$key]['reason_code'] ?? 'proof_missing';
                    $applicability = $this->worse($applicability, $reason === 'coverage_limit' || $reason === 'proof_missing' ? 'unknown' : 'revoked');
                    $reasons[] = $reason;
                }
                foreach ($graph['terminal'] ?? [] as $terminal) {
                    if ($terminal['subject_id'] === $row->id || $terminal['subject_id'] === $row->receipt_id) {
                        $applicability = 'revoked';
                        $reasons[] = $terminal['reason_code'];
                    }
                }
                if ($kind !== 'review') {
                    $receipt = $graph['rows']['receipt'][$row->receipt_id] ?? null;
                    if ($receipt && $receipt->variant === 'source') {
                        if ($receipt->occurred_at === null || $receipt->source_updated_at === null || $receipt->received_at === null || CarbonImmutable::parse($receipt->occurred_at)->greaterThan(CarbonImmutable::parse($receipt->source_updated_at)) || CarbonImmutable::parse($receipt->source_updated_at)->greaterThan(CarbonImmutable::parse($receipt->received_at))) {
                            $applicability = $this->worse($applicability, 'unknown');
                            $reasons[] = 'source_time_unknown';
                        }
                        $head = $this->sourceHead($graph, $receipt);
                        if ($head) {
                            $sourceHeads[$head['id']] = $head;
                        }
                        if (! $head || ! $head['complete']) {
                            $applicability = $this->worse($applicability, 'unknown');
                            $reasons[] = 'source_incomplete';
                        } elseif ($head['receipt_id'] !== $row->receipt_id) {
                            $applicability = $this->worse($applicability, 'stale');
                            $reasons[] = 'source_superseded';
                        }
                    }
                }
            }
            $requestKey = $request ? ($graph['proof_keys']['request:'.$request->id] ?? null) : null;
            if ($request && empty($proofs[$requestKey]['proof']['request_subjects'])) {
                // Legacy facts remain readable, but cannot invent original requester provenance.
                $applicability = $this->worse($applicability, 'unknown');
                $reasons[] = 'request_subject_proof_missing';
            }
            foreach (['request_subjects' => 'request_subject_time_unknown', 'coordinators' => 'coordinator_time_unknown'] as $category => $reason) {
                foreach ($proofs[$requestKey]['proof'][$category] ?? [] as $subject) {
                    foreach ([$subject['membership'], ...($subject['roles'] ?? [])] as $interval) {
                        if ($request->requested_at === null || CarbonImmutable::parse($interval['valid_from'])->greaterThan(CarbonImmutable::parse($request->requested_at)) || ($interval['valid_to'] !== null && CarbonImmutable::parse($interval['valid_to'])->lessThanOrEqualTo(CarbonImmutable::parse($request->requested_at)))) {
                            $applicability = $this->worse($applicability, 'unknown');
                            $reasons[] = $reason;
                        }
                    }
                }
            }
            foreach ($graph['link_slots'] ?? [] as $slot) {
                if ($slot['clarification_id'] !== $id) {
                    continue;
                }
                $link = $this->link($graph, $slot);
                if (! $link) {
                    $applicability = $this->worse($applicability, 'unknown');
                    $reasons[] = 'link_missing';
                } elseif ($link['task_current_version_id'] !== $slot['task_version_id'] || $link['requirement_current_version_id'] !== $slot['requirement_version_id']) {
                    $applicability = $this->worse($applicability, 'stale');
                    $reasons[] = 'link_superseded';
                }
            }
            foreach ($graph['responsibility_slots'] ?? [] as $slot) {
                if ($slot['clarification_id'] !== $id || $slot['relation'] !== 'blocks') {
                    continue;
                }
                $key = $slot['proof_key'];
                if ($key) {
                    $keys[] = $key;
                }
                $captured = $slot['occurred_at'] === null ? ['attribution' => 'unknown'] : app(BaResponsibility::class)->captureLocked($slot, $slot['occurred_at'], [$proofs[$key] ?? []]);
                if ($captured['attribution'] !== 'known') {
                    $applicability = $this->worse($applicability, 'unknown');
                    $reasons[] = 'responsibility_unknown';
                }
            }
            if ($request && $request->decision_party === 'unknown') {
                $applicability = $this->worse($applicability, 'unknown');
                $reasons[] = 'decision_identity_unknown';
            }
            if ($request && ($clock === null || $request->requested_at === null || CarbonImmutable::parse($request->requested_at)->greaterThan(CarbonImmutable::parse($clock)))) {
                $applicability = $this->worse($applicability, 'unknown');
                $reasons[] = 'time_unknown';
            }
            if ($request && $request->decision_owner_id === null && $request->decision_owner_party_version_id === null) {
                $applicability = $this->worse($applicability, 'unknown');
                $reasons[] = 'decision_identity_unknown';
            }
            foreach (array_values(array_unique(array_filter([$request?->decision_owner_party_version_id, $answer?->author_party_version_id]))) as $partyId) {
                $party = $graph['rows']['party'][$partyId] ?? null;
                $receipt = $party ? ($graph['rows']['receipt'][$party->receipt_id] ?? null) : null;
                $head = $receipt ? $this->sourceHead($graph, $receipt) : null;
                if ($head) {
                    $sourceHeads[$head['id']] = $head;
                }
                if (! $party || $party->party_kind === 'unknown' || ! $head || ! $head['complete']) {
                    $applicability = $this->worse($applicability, 'unknown');
                    $reasons[] = 'party_identity_unknown';
                } elseif ($head['receipt_id'] !== $party->receipt_id) {
                    $applicability = $this->worse($applicability, 'stale');
                    $reasons[] = 'party_superseded';
                }
            }
            if ($answer && $answer->operation !== 'answer.retract') {
                if ($answer->request_version_id !== $root->current_request_id || $answer->request_hash !== $request?->request_hash || $answer->links_hash !== $request?->links_hash) {
                    $applicability = $this->worse($applicability, 'stale');
                    $reasons[] = 'answer_boundary';
                }
                if ($answer->response_at === null || $request?->requested_at === null || $clock === null || CarbonImmutable::parse($answer->response_at)->greaterThan(CarbonImmutable::parse($clock)) || CarbonImmutable::parse($answer->response_at)->lessThan(CarbonImmutable::parse($request->requested_at))) {
                    $applicability = $this->worse($applicability, 'unknown');
                    $reasons[] = 'time_unknown';
                }
                $party = $graph['rows']['party'][$answer->author_party_version_id] ?? null;
                if (! $party || $party->party_kind === 'unknown') {
                    $applicability = $this->worse($applicability, 'unknown');
                    $reasons[] = 'answer_identity_unknown';
                }
            }
            $answerStatus = $answer ? ($answer->operation === 'answer.retract' ? 'retracted' : 'awaiting_review') : 'unanswered';
            if ($review) {
                if ($review->request_version_id !== $root->current_request_id || $review->answer_version_id !== $root->current_answer_id || $review->answer_hash !== $answer?->answer_hash) {
                    $applicability = $this->worse($applicability, 'stale');
                    $reasons[] = 'review_boundary';
                }
                $answerStatus = match ($review->kind) {
                    'review.confirm' => 'confirmed', 'review.reject' => 'rejected', default => 'revoked'
                };
            }
            $scope = 'not_requested';
            $originKeys = [];
            foreach ($graph['scope_origins'] ?? [] as $origin) {
                if ($origin['clarification_id'] !== $id) {
                    continue;
                }
                $key = $graph['proof_keys']['review:'.$origin['origin_review_id']] ?? null;
                if ($key) {
                    $originKeys[] = $key;
                }
                $originSafe = ($proofs[$key]['valid'] ?? false) && ! collect($graph['terminal'] ?? [])->contains(fn ($t) => $t['subject_id'] === $origin['origin_review_id']);
                $scope = $originSafe && $applicability === 'current' && $origin['origin_request_version_id'] === $root->current_request_id && $origin['origin_answer_version_id'] === $root->current_answer_id ? 'scope_approval_pending' : 'unknown';
            }
            if ($review?->disposition === 'scope_change_required' && ! $originKeys) {
                $scope = 'unknown';
                $reasons[] = 'scope_origin_missing';
            }
            $resolved = $root->state === 'resolved' && $answerStatus === 'confirmed' && $applicability === 'current' && $scope === 'not_requested';
            $result[$id] = ['clarification_state' => $root->state, 'applicability' => $applicability, 'answer_review_status' => $answerStatus, 'scope_status' => $scope, 'dependency_status' => $resolved ? 'decision_resolved' : 'unresolved', 'reasons' => array_values(array_unique($reasons)), 'coverage' => $applicability === 'unknown' ? 'unknown' : 'complete', 'request_version_id' => $root->current_request_id, 'answer_version_id' => $root->current_answer_id, 'review_id' => $root->current_review_id, 'source_heads' => $sourceHeads, 'required_proof_keys' => array_values(array_unique($keys)), 'origin_proof_keys' => array_values(array_unique($originKeys))];
        }

        return $result;
    }

    private function worse(string $old, string $new): string
    {
        $rank = ['current' => 0, 'stale' => 1, 'unknown' => 2, 'revoked' => 3];

        return $rank[$new] > $rank[$old] ? $new : $old;
    }

    private function sourceHead(array $graph, object $receipt): ?array
    {
        foreach ($graph['heads']['source'] ?? [] as $head) {
            if ($head['source_instance_id'] === $receipt->source_instance_id && $head['family'] === $receipt->family && $head['object_id'] === $receipt->object_id) {
                return $head;
            }
        }

        return null;
    }

    private function link(array $graph, array $slot): ?array
    {
        foreach ($graph['links'] ?? [] as $link) {
            if ($link['task_version_id'] === $slot['task_version_id'] && $link['criterion_id'] === $slot['criterion_id'] && $link['criterion_version_id'] === $slot['criterion_version_id'] && $link['relation'] === $slot['relation']) {
                return $link;
            }
        }

        return null;
    }
}
