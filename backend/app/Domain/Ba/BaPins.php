<?php

namespace App\Domain\Ba;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BaPins
{
    public const TABLES = ['clarification' => 'ba_clarifications', 'receipt' => 'ba_receipts', 'party' => 'ba_source_party_versions', 'request' => 'ba_request_versions', 'answer' => 'ba_answer_versions', 'review' => 'ba_reviews', 'event' => 'ba_events'];

    public function collect(TrustedActor $actor, string $project, array $roots = [], array $sourceIds = [], array $linkInputs = [], bool $prospectiveRequest = false): array
    {
        $refs = array_is_list($roots) ? ['clarification' => $roots] : $roots;
        if (array_diff(array_keys($refs), array_keys(self::TABLES))) {
            throw new InvalidArgumentException('Invalid BA references.');
        }
        if ($prospectiveRequest && (count($refs['clarification'] ?? []) > 1 || count($linkInputs) < 1 || count($linkInputs) > 20)) {
            throw new InvalidArgumentException('A prospective BA request is one bounded boundary.');
        }
        foreach ($refs as $kind => $ids) {
            $this->ids($ids);
            if (count($ids) > ($kind === 'receipt' ? 200 : 50)) {
                return $this->unknown();
            }
        }
        $this->ids($sourceIds);
        if (count($sourceIds) > 200 || ! array_is_list($linkInputs) || count($linkInputs) > 1000) {
            return $this->unknown();
        }
        // Admission counts selected roots, not the independent expanded provenance categories.
        $nonReceiptCounts = array_map('count', array_diff_key($refs, ['receipt' => true]));
        $R = max([1, ...$nonReceiptCounts, (int) ceil(count($refs['receipt'] ?? []) / 4)]);

        return app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $refs, $sourceIds, $linkInputs, $R, $prospectiveRequest) {
            $explicitReceipts = $refs['receipt'] ?? [];
            $rows = $this->rows($project, $refs);
            $heads = ['clarification' => [], 'source' => []];
            $currentRequests = [];
            $originRefs = [];
            foreach ($rows['clarification'] ?? [] as $root) {
                $heads['clarification'][$root->id] = $this->head($root);
                foreach (['request' => 'current_request_id', 'answer' => 'current_answer_id', 'review' => 'current_review_id', 'event' => 'current_event_id'] as $kind => $field) {
                    if ($root->{$field} !== null) {
                        $refs[$kind][] = $root->{$field};
                    }
                }
                if ($root->current_request_id !== null) {
                    $currentRequests[] = $root->current_request_id;
                }
            }
            // A write validates both the retained current and one prospective boundary.
            // It does not widen either stored request or omit current privacy/proof inputs.
            $additionalBoundary = (int) ($prospectiveRequest && count($currentRequests) === 1);
            $R += $additionalBoundary;
            $origins = DB::table('ba_scope_obligations')->where('project_id', $project)->whereIn('clarification_id', array_keys($heads['clarification']))->get();
            foreach ($origins as $origin) {
                // Origin request/answer IDs and hashes remain metadata, never old text delivery.
                foreach (['review' => 'origin_review_id'] as $kind => $field) {
                    $refs[$kind][] = $origin->{$field};
                    $originRefs[$kind.':'.$origin->{$field}] = true;
                }
            }
            $refs = $this->deduplicate($refs);
            $rows = $this->rows($project, $refs);
            $named = [];
            foreach ($rows as $kind => $group) {
                foreach ($group as $row) {
                    if (isset($row->receipt_id)) {
                        $refs['receipt'][] = $row->receipt_id;
                    }
                    foreach (['requester_id', 'coordinator_id', 'decision_owner_id'] as $field) {
                        if (isset($row->{$field})) {
                            $named[] = $row->{$field};
                        }
                    }
                    if (isset($row->source_instance_id)) {
                        $sourceIds[] = $row->source_instance_id;
                    }
                    if (isset($row->decision_owner_party_version_id)) {
                        $refs['party'][] = $row->decision_owner_party_version_id;
                    }
                    if (isset($row->author_party_version_id)) {
                        $refs['party'][] = $row->author_party_version_id;
                    }
                }
            }
            $refs = $this->deduplicate($refs);
            // Admit source receipt references before loading their envelopes. Human
            // action/origin authority remains on the already selected immutable fact.
            $refs['receipt'] = $this->receiptRefs($project, $refs['receipt'] ?? [], $explicitReceipts);
            if (count($refs['receipt'] ?? []) > 200 || count($refs['party'] ?? []) > 150) {
                return $this->unknown();
            }
            $rows = $this->rows($project, $refs);
            // Newly selected owner/author parties contribute their own receipt category once.
            foreach ($rows['party'] ?? [] as $party) {
                $refs['receipt'][] = $party->receipt_id;
                $sourceIds[] = $party->source_instance_id;
            }
            $refs = $this->deduplicate($refs);
            // Human fact authority is already carried verbatim on its typed fact.
            $refs['receipt'] = $this->receiptRefs($project, $refs['receipt'] ?? [], $explicitReceipts);
            if (count($refs['receipt'] ?? []) > 4 * $R || count($refs['receipt'] ?? []) > 200) {
                return $this->unknown();
            }
            $rows = $this->rows($project, $refs);
            $storedLinks = DB::table('ba_request_links')->where('project_id', $project)->whereIn('request_version_id', $currentRequests)->orderBy('id')->limit(1001)->get();
            if ($storedLinks->count() > 1000) {
                return $this->unknown();
            }
            $links = $this->associations($project, [...$linkInputs, ...$storedLinks->map(fn ($row) => array_intersect_key((array) $row, array_flip(['task_version_id', 'criterion_id', 'criterion_version_id', 'relation'])))->all()]);
            $requests = [];
            $proofKeys = $identicalProofs = [];
            $responsibilitySlots = [];
            foreach ($links as $link) {
                $named[] = $link['assignee_id'];
            }
            foreach ($rows as $kind => $group) {
                foreach ($group as $row) {
                    if (! isset($row->authority_proof)) {
                        continue;
                    }
                    $proof = json_decode($row->authority_proof, true, 32, JSON_THROW_ON_ERROR);
                    $named[] = $proof['employee_id'];
                    $named = [...$named, ...array_column($proof['coordinators'] ?? [], 'employee_id'), ...array_column($proof['request_subjects'] ?? [], 'employee_id')];
                    $sourceIds = [...$sourceIds, ...array_column($proof['sources'] ?? [], 'id')];
                    $fingerprint = BaContract::canonical($proof);
                    $key = $identicalProofs[$fingerprint] ?? (isset($row->receipt_id) ? 'receipt:'.$row->receipt_id : $kind.':'.$row->id);
                    $proofKeys[$kind.':'.$row->id] = $key;
                    if (isset($row->receipt_id)) {
                        $proofKeys['receipt:'.$row->receipt_id] = $key;
                    }
                    if (! isset($identicalProofs[$fingerprint])) {
                        $identicalProofs[$fingerprint] = $key;
                        $requests[] = ['key' => $key, 'actor_id' => $proof['actor_id'], 'purposes' => $proof['purposes'], 'required' => false, 'pinned_proof' => $proof, 'root_refs' => array_keys($heads['clarification'])];
                    }
                }
            }
            foreach ($storedLinks as $link) {
                $request = $rows['request'][$link->request_version_id];
                $root = $rows['clarification'][$link->clarification_id];
                $answer = $root->current_answer_id === null ? null : ($rows['answer'][$root->current_answer_id] ?? null);
                $phases = ['request' => [$request, $request->requested_at]];
                if ($answer !== null) {
                    $phases['answer'] = [$answer, $answer->response_at];
                }
                foreach ($phases as $phase => [$row, $time]) {
                    $slot = ['clarification_id' => $root->id, 'request_version_id' => $request->id, 'task_version_id' => $link->task_version_id, 'criterion_id' => $link->criterion_id, 'criterion_version_id' => $link->criterion_version_id, 'relation' => $link->relation, 'phase' => $phase, 'occurred_at' => null, 'proof_key' => null, 'coverage' => 'unknown', 'reason_code' => 'time_unknown'];
                    if ($time !== null) {
                        $at = CarbonImmutable::parse($time)->utc()->format('Y-m-d\TH:i:s.u\Z');
                        $ref = ['task_version_id' => $link->task_version_id, 'occurred_at' => $at];
                        $key = 'responsibility:'.hash('sha256', BaContract::canonical([$request->id, $link->task_version_id, $link->criterion_id, $link->criterion_version_id, $phase]));
                        $proof = json_decode($row->authority_proof, true, 32, JSON_THROW_ON_ERROR);
                        $requests[] = ['key' => $key, 'actor_id' => $proof['actor_id'], 'purposes' => $proof['purposes'], 'required' => false, 'pinned_proof' => $proof, 'responsibility_refs' => [$ref], 'root_refs' => array_keys($heads['clarification'])];
                        $slot = array_replace($slot, ['occurred_at' => $at, 'proof_key' => $key, 'coverage' => 'captured_input', 'reason_code' => null]);
                    }
                    $responsibilitySlots[] = $slot;
                }
            }
            $sourceIds = array_values(array_unique($sourceIds));
            sort($sourceIds, SORT_STRING);
            $sourcePoints = array_values(array_map(fn ($receipt) => ['source_instance_id' => $receipt->source_instance_id, 'family' => $receipt->family, 'object_id' => $receipt->object_id], array_filter($rows['receipt'] ?? [], fn ($receipt) => $receipt->variant === 'source')));
            if ($sourcePoints) {
                $headRows = DB::select('SELECT DISTINCT h.* FROM jsonb_to_recordset(?::jsonb) AS p(source_instance_id uuid,family text,object_id text) JOIN ba_source_heads h ON h.project_id=?::uuid AND h.source_instance_id=p.source_instance_id AND h.family=p.family AND h.object_id=p.object_id', [json_encode($sourcePoints, JSON_THROW_ON_ERROR), $project]);
                foreach ($headRows as $head) {
                    $heads['source'][$head->id] = (array) $head;
                }
            }
            // Source access is independent of the original registrar/action authority.
            if ($sourceIds) {
                $requests[] = ['key' => 'sources', 'actor_id' => $actor->id, 'purposes' => ['ba.source.read'], 'required' => true, 'source_ids' => $sourceIds, 'source_head_refs' => array_keys($heads['source']), 'root_refs' => array_keys($heads['clarification'])];
            }
            $named = array_values(array_unique($named));
            $L = count($linkInputs) + $storedLinks->count();
            if ($R > 50 || $L > 20 * $R || count($requests) > 6 * $R + 2 * $L + 1 || count($requests) > 2301 || count($named) > 7 * $R + $L || count($named) > 1350 || count($sourceIds) > 200) {
                return $this->unknown();
            }
            $graph = ['refs' => $refs, 'heads' => $heads, 'requests' => $requests, 'source_ids' => $sourceIds, 'named_refs' => $named, 'links' => $links, 'link_slots' => $storedLinks->map(fn ($row) => (array) $row)->all(), 'responsibility_slots' => $responsibilitySlots, 'categories' => ['roots' => $R, 'links' => $L, 'proofs' => count($requests), 'named' => count($named), 'receipts' => count($refs['receipt'] ?? [])], 'scope_origins' => $origins->map(fn ($row) => (array) $row)->all(), 'origin_refs' => array_keys($originRefs), 'coverage' => 'complete', 'reason_code' => null];
            if ($prospectiveRequest) {
                $graph['categories']['prospective_boundaries'] = 1;
            }
            $activeSubjects = [];
            foreach ($currentRequests as $requestId) {
                $active = $rows['request'][$requestId];
                $activeSubjects = [...$activeSubjects, $active->requester_id, $active->coordinator_id, $active->decision_owner_id];
            }
            // Current named-person access is independent from optional historic actors.
            $graph['active_subject_refs'] = array_values(array_unique(array_filter($activeSubjects)));
            $graph['proof_keys'] = $proofKeys;
            $graph['categories']['proofs'] -= (int) (bool) $sourceIds;
            $graph['input_hash'] = hash('sha256', BaContract::canonical([$refs, $heads, $links]));

            return $graph;
        });
    }

    public function loadLocked(string $project, array $graph): array
    {
        $this->locked();
        if (($graph['coverage'] ?? '') !== 'complete') {
            return ['rows' => [], 'links' => [], 'coverage' => 'unknown'];
        }
        $rows = $this->rows($project, $graph['refs']);
        foreach ($graph['heads']['clarification'] as $id => $captured) {
            if (BaContract::canonical($this->head($rows['clarification'][$id])) !== BaContract::canonical($captured)) {
                throw new ConflictHttpException;
            }
        }
        $sourceHeads = DB::table('ba_source_heads')->where('project_id', $project)->whereIn('id', array_keys($graph['heads']['source']))->get()->keyBy('id');
        foreach ($graph['heads']['source'] as $id => $captured) {
            if (! $sourceHeads->has($id) || BaContract::canonical((array) $sourceHeads[$id]) !== BaContract::canonical($captured)) {
                throw new ConflictHttpException;
            }
        }
        $links = $this->associations($project, $graph['links']);
        if (BaContract::canonical($links) !== BaContract::canonical($graph['links'])) {
            throw new ConflictHttpException;
        }

        return ['rows' => $rows, 'links' => $links, 'coverage' => 'complete'];
    }

    private function associations(string $project, array $links): array
    {
        if (! $links) {
            return [];
        }
        $inputs = [];
        foreach ($links as $link) {
            $link = array_intersect_key($link, array_flip(['task_version_id', 'criterion_id', 'criterion_version_id', 'relation']));
            app(BaContract::class)->validate('link', $link);
            $inputs[BaContract::canonical($link)] = $link;
        }
        ksort($inputs, SORT_STRING);
        $json = json_encode(array_values($inputs), JSON_THROW_ON_ERROR);
        $rows = DB::select('SELECT p.*,r.requirement_version_id,v.content_hash AS requirement_hash,t.assignee_id,t.content_hash AS task_hash,h.current_version_id AS task_current_version_id,h.revision AS task_current_revision,n.id AS task_successor_version_id,n.effective_at AS task_effective_until,curr.id AS requirement_current_version_id FROM jsonb_to_recordset(?::jsonb) AS p(task_version_id uuid,criterion_id uuid,criterion_version_id uuid,relation text) JOIN task_criterion_links l ON l.project_id=?::uuid AND l.task_version_id=p.task_version_id AND l.criterion_id=p.criterion_id AND l.criterion_version_id=p.criterion_version_id JOIN task_versions t ON t.id=l.task_version_id AND t.project_id=l.project_id JOIN lifecycle_tasks h ON h.id=t.task_id AND h.project_id=t.project_id LEFT JOIN task_versions n ON n.task_id=t.task_id AND n.project_id=t.project_id AND n.revision=t.revision+1 JOIN LATERAL (SELECT c.requirement_version_id FROM requirement_version_criteria c JOIN requirement_versions v ON v.id=c.requirement_version_id AND v.project_id=c.project_id WHERE c.project_id=l.project_id AND c.criterion_id=l.criterion_id AND c.criterion_version_id=l.criterion_version_id ORDER BY v.version DESC LIMIT 1) r ON true JOIN requirement_versions v ON v.id=r.requirement_version_id AND v.project_id=l.project_id JOIN LATERAL (SELECT v2.id FROM requirement_versions v2 WHERE v2.requirement_id=v.requirement_id AND v2.project_id=v.project_id ORDER BY v2.version DESC LIMIT 1) curr ON true', [$json, $project]);
        if (count($rows) !== count($inputs)) {
            throw new NotFoundHttpException;
        }
        $result = array_map(fn ($row) => (array) $row, $rows);
        usort($result, fn ($a, $b) => strcmp(BaContract::canonical($a), BaContract::canonical($b)));

        return $result;
    }

    private function rows(string $project, array $refs): array
    {
        $rows = [];
        foreach ($refs as $kind => $ids) {
            $values = DB::table(self::TABLES[$kind])->where('project_id', $project)->whereIn('id', $ids)->orderBy('id')->get();
            if ($values->count() !== count($ids)) {
                throw new NotFoundHttpException;
            }
            $rows[$kind] = $values->keyBy('id')->all();
        }

        return $rows;
    }

    private function receiptRefs(string $project, array $ids, array $explicit): array
    {
        return DB::table('ba_receipts')->where('project_id', $project)->whereIn('id', $ids)->where(fn ($q) => $q->where('variant', 'source')->orWhereIn('id', $explicit))->orderBy('id')->pluck('id')->all();
    }

    private function head(object $root): array
    {
        return array_intersect_key((array) $root, array_flip(['id', 'revision', 'state', 'current_event_id', 'current_request_id', 'current_answer_id', 'current_review_id']));
    }

    private function deduplicate(array $refs): array
    {
        foreach ($refs as &$ids) {
            $ids = array_values(array_unique($ids));
            sort($ids, SORT_STRING);
        }
        ksort($refs, SORT_STRING);

        return $refs;
    }

    private function ids(array $ids): void
    {
        if (! array_is_list($ids)) {
            throw new InvalidArgumentException;
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new InvalidArgumentException;
            }
        }
    }

    private function unknown(): array
    {
        return ['refs' => [], 'heads' => ['clarification' => [], 'source' => []], 'requests' => [], 'source_ids' => [], 'named_refs' => [], 'links' => [], 'link_slots' => [], 'responsibility_slots' => [], 'categories' => [], 'scope_origins' => [], 'origin_refs' => [], 'coverage' => 'unknown', 'reason_code' => 'coverage_limit', 'input_hash' => null];
    }

    private function locked(): void
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.ba_authority_closed',true),'')") !== '1') {
            throw new LogicException('BA pins require coordination.');
        }
    }
}
