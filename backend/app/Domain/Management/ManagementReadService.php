<?php

namespace App\Domain\Management;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ManagementReadService
{
    public function queue(TrustedActor $actor, string $project, array $query = []): array
    {
        return $this->read($actor, $project, null, $query);
    }

    public function history(TrustedActor $actor, string $project, string $action, array $query = []): array
    {
        if (! Str::isUuid($action)) {
            throw new InvalidArgumentException('Invalid management action.');
        }

        return $this->read($actor, $project, $action, $query);
    }

    private function read(TrustedActor $actor, string $project, ?string $action, array $query): array
    {
        if (! Str::isUuid($project)) {
            throw new InvalidArgumentException('Invalid management project.');
        }
        $pagination = app(ManagementPagination::class);
        $query = $pagination->query($query);
        $purposes = ['management.read', 'management.source.read'];
        if ($query['department_id'] !== null) {
            $purposes[] = 'management.department.read';
        }
        $state = app(ManagementCoordination::class)->run($actor, $project, $purposes, false, ['coverage' => 'complete'], function () use ($project) {
            return ['generation' => $this->generation($project), 'closed' => (bool) DB::table('lifecycle_project_state')->where('project_id', $project)->value('is_closed')];
        }, $query['department_id']);
        $resource = $action === null ? 'queue' : 'history';
        $position = $pagination->decode($actor, $project, $resource, $action, $query, $state['generation']);
        if ($action === null && $state['closed']) {
            return $this->page([], null, 'unavailable', ['project_closed']);
        }
        $probeLimit = max(20, $query['limit']);
        $candidates = app(TenantContext::class)->run($actor->tenantId, function () use ($project, $action, $position, $probeLimit) {
            if ($action === null) {
                $q = DB::table('management_actions')->where('project_id', $project);
                if ($position !== null) {
                    $q->whereRaw('(original_opened_at,id)>(?::timestamptz,?::uuid)', [$position['opened_at'], $position['id']]);
                }

                return $q->orderBy('original_opened_at')->orderBy('id')->limit($probeLimit + 1)->get(['id'])->map(fn ($r) => $r->id)->all();
            }

            return DB::table('management_events')->where('project_id', $project)->where('action_id', $action)->where('revision', '>', $position['revision'] ?? 0)->orderBy('revision')->limit($probeLimit + 1)->get(['id'])->map(fn ($r) => ['action_id' => $action, 'event_id' => $r->id])->all();
        });
        $exhausted = count($candidates) <= $probeLimit;
        $candidates = array_slice($candidates, 0, $probeLimit);
        $pins = app(ManagementPins::class);
        $eligibility = app(ManagementReadEligibility::class);
        $observation = $pins->collect($actor, $project, $candidates);
        if ($observation['coverage'] !== 'complete') {
            $this->authorizeEmpty($actor, $project, $purposes, $query['department_id']);

            return $this->page([], null, 'unknown', ['coverage_limit']);
        }
        $certificates = $eligibility->certificates($observation);
        $additionalActors = array_sum(array_map(fn ($certificate) => count(array_filter($certificate['named_occurrences'], fn ($occurrence) => $occurrence['role'] === 'actor')), $certificates));
        $namedCharge = $observation['budget']['named_slots'] + $additionalActors + array_sum(array_column($certificates, 'actor_mapping_charge'));
        if (array_filter($certificates, fn ($certificate) => $certificate['boundary_named_occurrences'] > 8 || array_filter($certificate['reference_named_counts'], fn ($count) => $count > 8)) || $namedCharge > min(8 * $observation['budget']['boundaries'] + 8 * $observation['budget']['references'], 2000)) {
            $this->authorizeEmpty($actor, $project, $purposes, $query['department_id']);

            return $this->page([], null, 'unknown', ['coverage_limit']);
        }
        $visible = app(ManagementCoordination::class)->run($actor, $project, $purposes, false, ['coverage' => 'complete'], function ($proofs) use ($actor, $project, $query, $state, $certificates, $eligibility) {
            if ($this->generation($project) !== $state['generation']) {
                throw new ConflictHttpException('Management generation changed.');
            }

            return $eligibility->currentVisibleLocked($actor, $project, $certificates, $proofs, $query['department_id']);
        }, $query['department_id']);
        $currentCertificates = array_values(array_filter($certificates, fn ($certificate) => in_array($certificate['event_id'], $visible, true)));
        $keys = array_values(array_unique(array_merge([], ...array_column($currentCertificates, 'proof_keys'))));
        $observation['proof_slots'] = array_values(array_map(fn ($slot) => array_replace($slot, ['mandatory' => false]), array_filter($observation['proof_slots'], fn ($slot) => in_array($slot['key'], $keys, true))));
        $observation['current_requests'] = [];
        $observation['named_slots'] = [];
        $observation['responsibility_slots'] = [];
        $this->currentScope($actor, $observation, $currentCertificates, $query['department_id']);
        $selection = app(ManagementCoordination::class)->run($actor, $project, $purposes, false, $observation, function ($proofs) use ($actor, $project, $query, $state, $position, $observation, $certificates, $eligibility) {
            if ($this->generation($project) !== $state['generation']) {
                throw new ConflictHttpException('Management generation changed.');
            }
            foreach ($observation['proof_slots'] as $slot) {
                $result = $proofs[$slot['key']];
                if (! $result['valid'] && in_array($result['reason_code'], ['access_revoked', 'role_revoked', 'source_revoked', 'source_invalid'], true)) {
                    app(ManagementTerminal::class)->observeLocked($project, 'proof', $slot['subject_id'], $slot['proof_hash'], $result['reason_code']);
                }
            }
            $generation = $this->generation($project);
            if ($position !== null && $generation !== $state['generation']) {
                throw new ConflictHttpException('Management cursor conflict.');
            }

            return ['ids' => $eligibility->selectLocked($actor, $project, $certificates, $proofs, $query['department_id'], $query['limit']), 'generation' => $generation];
        }, $query['department_id']);
        if (count($selection['ids']) < $query['limit'] && ! $exhausted) {
            $this->authorizeEmpty($actor, $project, $purposes, $query['department_id']);

            return $this->page([], null, 'unknown', ['coverage_limit']);
        }
        $selectors = [];
        foreach ($certificates as $certificate) {
            if (in_array($certificate['event_id'], $selection['ids'], true)) {
                $selectors[] = ['action_id' => $certificate['root_id'], 'event_id' => $certificate['event_id']];
            }
        }
        $delivery = $pins->collect($actor, $project, $selectors);
        if ($delivery['coverage'] !== 'complete') {
            $this->authorizeEmpty($actor, $project, $purposes, $query['department_id']);

            return $this->page([], null, 'unknown', ['coverage_limit']);
        }
        $deliveryCertificates = $eligibility->certificates($delivery);
        $this->currentScope($actor, $delivery, $deliveryCertificates, $query['department_id']);
        $authorityNamed = count($delivery['named_slots']);
        foreach ($delivery['proof_slots'] as $slot) {
            foreach (['named', 'coordinators', 'request_subjects'] as $field) {
                $authorityNamed += count($slot['pinned_proof'][$field] ?? []);
            }
        }
        if ($authorityNamed > 2000) {
            $this->authorizeEmpty($actor, $project, $purposes, $query['department_id']);

            return $this->page([], null, 'unknown', ['coverage_limit']);
        }

        return app(ManagementCoordination::class)->run($actor, $project, $purposes, false, $delivery, function ($proofs) use ($actor, $project, $action, $query, $resource, $selection, $delivery, $deliveryCertificates, $eligibility, $pins, $pagination) {
            if ($this->generation($project) !== $selection['generation']) {
                throw new ConflictHttpException('Management generation changed.');
            }
            if ($action === null && DB::table('lifecycle_project_state')->where('project_id', $project)->value('is_closed')) {
                return $this->page([], null, 'unavailable', ['project_closed']);
            }
            if ($eligibility->selectLocked($actor, $project, $deliveryCertificates, $proofs, $query['department_id'], 50) !== $selection['ids']) {
                throw new ConflictHttpException('Management delivery eligibility changed.');
            }
            $loaded = $pins->loadLocked($project, $delivery);
            $evaluations = $pins->evaluateBatchLocked($project, $delivery, $loaded['rows'], $proofs);
            $now = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
            $items = [];
            foreach ($delivery['boundaries'] as $boundary) {
                $items[] = $this->dto($boundary, $loaded['rows'], $evaluations[$boundary['event_id']], $now);
            }
            app(ManagementAuthority::class)->requireDeliveryLocked(array_values(array_unique(array_merge([], ...array_column($deliveryCertificates, 'proof_keys')))));
            if ($eligibility->selectLocked($actor, $project, $deliveryCertificates, $proofs, $query['department_id'], 50) !== $selection['ids']) {
                throw new ConflictHttpException('Management final delivery changed.');
            }
            $cursor = null;
            if (count($items) === $query['limit']) {
                $last = end($items);
                $position = $action === null ? ['opened_at' => $last['original_opened_at'], 'id' => $last['action_id']] : ['revision' => $last['revision'], 'id' => $last['event_id']];
                $cursor = $pagination->encode($actor, $project, $resource, $action, $query, $selection['generation'], $position);
            }

            return $this->page($items, $cursor, 'complete', []);
        }, $query['department_id']);
    }

    private function authorizeEmpty(TrustedActor $actor, string $project, array $purposes, ?string $department): void
    {
        app(ManagementCoordination::class)->run($actor, $project, $purposes, false, ['coverage' => 'complete'], fn () => null, $department);
    }

    private function generation(string $project): int
    {
        return (int) (DB::table('management_project_state')->where('project_id', $project)->value('generation') ?? 0);
    }

    /** Charged certificates precede deduplicated current authority lookups. */
    private function currentScope(TrustedActor $actor, array &$graph, array $certificates, ?string $department): void
    {
        $graph['current_requests'] = [];
        $purposes = array_values(array_unique(array_merge([], ...array_column($certificates, 'purposes'))));
        foreach (['lifecycle' => ['requirements.read', 'progress.read'], 'qa' => ['qa.read', 'qa.source.read'], 'ba' => ['ba.read', 'ba.source.read']] as $family => $required) {
            if (! array_intersect($required, $purposes)) {
                continue;
            }
            if ($department !== null && $family !== 'lifecycle') {
                $required[] = $family.'.department.read';
            }
            $graph['current_requests'][] = ['key' => 'current:'.$family, 'actor_id' => $actor->id, 'purposes' => $required, 'required' => true];
        }
        $sources = array_values(array_unique(array_merge([], ...array_column($certificates, 'source_ids'))));
        if ($sources) {
            $graph['current_requests'][] = ['key' => 'current:sources', 'actor_id' => $actor->id, 'purposes' => ['management.source.read'], 'required' => true, 'source_ids' => $sources];
        }
        $people = array_values(array_unique(array_merge([], ...array_column($certificates, 'employee_ids'))));
        foreach ($people as $employee) {
            if (! in_array($employee, array_column($graph['named_slots'], 'employee_id'), true)) {
                $graph['named_slots'][] = ['key' => 'read:named:'.$employee, 'employee_id' => $employee, 'origin' => 'management', 'mandatory' => true];
            }
        }
    }

    private function dto(array $boundary, array $rows, array $evaluation, string $now): array
    {
        $root = $rows['management_actions'][$boundary['root_id']];
        $version = $rows['management_action_versions'][$boundary['action_version_id']];
        $assignment = $rows['management_assignments'][$boundary['assignment_id'] ?? ''] ?? null;
        $subject = $rows['management_support_versions'][$boundary['subject_support_id'] ?? $boundary['support_id'] ?? ''] ?? null;
        $review = $rows['management_reviews'][$boundary['review_id'] ?? ''] ?? null;
        $support = $this->support($subject);
        $projected = $this->support($rows['management_support_versions'][$boundary['support_id'] ?? ''] ?? null);
        $opened = CarbonImmutable::parse($root['original_opened_at'])->utc()->format('Y-m-d\TH:i:s.u\Z');
        $episode = CarbonImmutable::parse($version['occurred_at'])->utc()->format('Y-m-d\TH:i:s.u\Z');
        $age = app(ManagementResponsibility::class)->age($opened, $episode, $now);
        $evaluation['reason_codes'] = array_values(array_unique([...$evaluation['reason_codes'], ...$age['reason_codes']]));
        $current = in_array('current_projection_unavailable', $evaluation['current_reason_codes'], true) ? null : array_intersect_key($root, array_flip(['revision', 'state', 'support_status', 'review_status', 'current_action_version_id', 'current_assignment_id', 'current_support_id', 'current_review_id']));
        if ($current !== null) {
            $current['current_outcome'] = $evaluation['current_outcome'];
        }

        return [
            'action_id' => $root['id'],
            'event_id' => $boundary['event_id'],
            'revision' => (int) $boundary['revision'],
            'operation' => $boundary['operation'],
            'state' => $boundary['state'],
            'support_status' => $boundary['support_status'],
            'review_status' => $boundary['review_status'],
            'action_version_id' => $version['id'],
            'action_hash' => $version['action_hash'],
            'assignment_id' => $assignment['id'] ?? null,
            'assignment_hash' => $assignment['assignment_hash'] ?? null,
            'owner_id' => $assignment['owner_id'] ?? $version['owner_id'],
            'helper_id' => $assignment['helper_id'] ?? null,
            'requester_id' => $version['requester_id'],
            'subject_support' => $support,
            'support' => $projected,
            'projected_support_id' => $boundary['support_id'],
            'current_projection' => $current,
            'review_id' => $review['id'] ?? null,
            'review_hash' => $review['review_hash'] ?? null,
            'reviewer_id' => $review['reviewer_id'] ?? null,
            'reviewed_at' => isset($review['reviewed_at']) ? CarbonImmutable::parse($review['reviewed_at'])->utc()->format('Y-m-d\TH:i:s.u\Z') : null,
            'action_occurred_at' => CarbonImmutable::parse($version['occurred_at'])->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'recorded_at' => CarbonImmutable::parse($boundary['created_at'])->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'reason' => $boundary['reason'] ?? $version['reason'],
            'context' => $boundary['context'] ?? null,
            'applicability' => $evaluation['applicability'],
            'current_applicability' => $evaluation['current_applicability'],
            'current_outcome' => $evaluation['current_outcome'],
            'current_reason_codes' => $evaluation['current_reason_codes'],
            'reference_results' => $evaluation['reference_results'],
            'underlying_dependency_status' => $evaluation['underlying_dependency_status'],
            'responsibility' => array_values($evaluation['responsibility']),
            'original_opened_at' => $opened,
            'coverage_status' => $evaluation['coverage'],
            'reason_codes' => $evaluation['reason_codes'],
            'synthetic' => true,
            'ai_interpretation' => 'unavailable',
            'customer_authenticity' => 'not_verified',
        ] + $age;
    }

    private function support(?array $fact): ?array
    {
        if ($fact === null) {
            return null;
        }
        $result = array_intersect_key($fact, array_flip(['id', 'operation', 'helper_id', 'recorder_id', 'text', 'reason', 'outcome', 'support_hash', 'references_hash', 'action_version_id', 'assignment_id', 'occurred_at', 'source_updated_at', 'received_at']));
        foreach (['occurred_at', 'source_updated_at', 'received_at'] as $field) {
            if (isset($result[$field])) {
                $result[$field] = CarbonImmutable::parse($result[$field])->utc()->format('Y-m-d\TH:i:s.u\Z');
            }
        }

        return $result;
    }

    private function page(array $items, ?string $cursor, string $coverage, array $reasons): array
    {
        return ['items' => $items, 'next_cursor' => $cursor, 'visible_record_count' => $coverage === 'complete' ? count($items) : null, 'coverage_status' => $coverage, 'reasons' => $reasons, 'synthetic' => true, 'ai_interpretation' => 'unavailable'];
    }
}
