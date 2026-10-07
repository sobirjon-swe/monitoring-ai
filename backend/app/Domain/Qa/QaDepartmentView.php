<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class QaDepartmentView
{
    // Only dependencies of the delivered fact; selection and whole-plan links are deliberately absent.
    private const LINKS = [
        'build' => ['receipt_id' => 'receipt'], 'environment' => ['receipt_id' => 'receipt'],
        'definition' => ['receipt_id' => 'receipt'], 'entry' => [], 'review' => ['definition_id' => 'definition'],
        'execution' => ['receipt_id' => 'receipt', 'definition_id' => 'definition', 'build_id' => 'build', 'environment_id' => 'environment'],
        'defect' => ['receipt_id' => 'receipt', 'definition_id' => 'definition', 'build_id' => 'build', 'environment_id' => 'environment', 'execution_id' => 'execution', 'current_event_id' => 'defect_event', 'canonical_defect_id' => 'defect'],
        'defect_event' => ['defect_id' => 'defect', 'definition_id' => 'definition', 'build_id' => 'build', 'environment_id' => 'environment', 'task_version_id' => 'task_version', 'execution_id' => 'execution', 'canonical_defect_id' => 'defect', 'fix_request_event_id' => 'defect_event'],
        'handoff' => ['receipt_id' => 'receipt', 'task_version_id' => 'task_version', 'execution_id' => 'execution', 'defect_id' => 'defect', 'linked_handoff_id' => 'handoff', 'current_event_id' => 'handoff_event'],
        'handoff_event' => ['handoff_id' => 'handoff', 'execution_id' => 'execution', 'defect_event_id' => 'defect_event'],
    ];

    private function links(bool $currentReviews): array
    {
        $links = self::LINKS;
        if ($currentReviews) {
            $links['definition']['current_review_id'] = 'review';
        }

        return $links;
    }

    /** Pure traversal of an individual delivered fact, independent of unrelated selected facts. */
    public function dependencies(array $rows, string $kind, string $id): array
    {
        $pending = [$kind.':'.$id];
        $seen = [];
        $links = $this->links(true);
        while ($pending) {
            $key = array_pop($pending);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            [$type, $rowId] = explode(':', $key, 2);
            $row = $rows[$type][$rowId] ?? null;
            if (! $row) {
                continue;
            }
            foreach ($links[$type] ?? [] as $field => $dependency) {
                if ($row->{$field} ?? null) {
                    $pending[] = $dependency.':'.$row->{$field};
                }
            }
            if ($type === 'execution') {
                $receipt = $rows['receipt'][$row->receipt_id] ?? null;
                $task = $receipt ? json_decode($receipt->envelope, true)['body']['task_version_id'] ?? null : null;
                if ($task) {
                    $pending[] = 'task_version:'.$task;
                }
            }
        }

        return array_keys($seen);
    }

    /** Relationship/proof hints only; current membership visibility comes from the authority batch. */
    public function prepare(TrustedActor $actor, string $project, string $department, array $graph, bool $currentReviews = false): array
    {
        if (! Str::isUuid($department)) {
            throw new AccessDeniedHttpException;
        }

        return app(TenantContext::class)->run($actor->tenantId, function () use ($project, $graph, $currentReviews) {
            $rows = [];
            foreach ($graph['refs'] as $kind => $ids) {
                $rows[$kind] = DB::table(QaPins::TABLES[$kind])->where('project_id', $project)->whereIn('id', $ids)->get()->keyBy('id')->all();
            }
            $subjects = [];
            foreach ($rows as $kind => $group) {
                foreach ($group as $id => $row) {
                    $named = [];
                    $known = true;
                    if (isset($row->authority_proof)) {
                        $proof = json_decode($row->authority_proof, true);
                        $named[] = $proof['employee_id'] ?? null;
                    }
                    if (isset($row->receipt_id)) {
                        $receipt = $rows['receipt'][$row->receipt_id] ?? null;
                        $proof = $receipt ? json_decode($receipt->authority_proof, true) : [];
                        $named[] = $proof['employee_id'] ?? null;
                    }
                    if (isset($row->owner_id)) {
                        $named[] = $row->owner_id;
                    }
                    if (isset($row->responsibility)) {
                        $responsibility = json_decode($row->responsibility, true);
                        $known = ($responsibility['attribution'] ?? 'unknown') === 'known';
                        $named[] = $responsibility['employee_id'] ?? null;
                    }
                    if (isset($row->task_version_id)) {
                        $named[] = ($rows['task_version'][$row->task_version_id] ?? null)?->assignee_id;
                    }
                    if ($kind === 'task_version') {
                        $named[] = $row->assignee_id;
                    }
                    if ($kind === 'execution') {
                        $receipt = $rows['receipt'][$row->receipt_id] ?? null;
                        $task = $receipt ? json_decode($receipt->envelope, true)['body']['task_version_id'] ?? null : null;
                        $named[] = ($rows['task_version'][$task ?? ''] ?? null)?->assignee_id;
                    }
                    $known = $known && ! in_array(null, $named, true);
                    $named = array_values(array_unique(array_filter($named)));
                    $subjects[$kind.':'.$id] = ['known' => $known, 'employees' => $named];
                }
            }
            // Merge only reachable employees into each fact. UNION-style visited sets bound cycles.
            $links = $this->links($currentReviews);
            foreach ($subjects as $key => $unused) {
                $pending = [$key];
                $seen = [];
                $named = [];
                $known = true;
                while ($pending) {
                    $next = array_pop($pending);
                    if (isset($seen[$next])) {
                        continue;
                    }
                    $seen[$next] = true;
                    [$kind,$id] = explode(':', $next, 2);
                    $row = $rows[$kind][$id] ?? null;
                    if (! $row || ! isset($subjects[$next])) {
                        $known = false;

                        continue;
                    }
                    $known = $known && $subjects[$next]['known'];
                    $named = [...$named, ...$subjects[$next]['employees']];
                    foreach ($links[$kind] ?? [] as $field => $dependency) {
                        if ($row->{$field} ?? null) {
                            $pending[] = $dependency.':'.$row->{$field};
                        }
                    }
                }
                $expanded[$key] = ['known' => $known, 'employees' => array_values(array_unique($named))];
            }
            $subjects = $expanded ?? [];
            $all = [];
            foreach ($subjects as $key => $subject) {
                [$kind,$id] = explode(':', $key, 2);
                if (in_array($kind, ['entry', 'definition', 'execution', 'defect', 'defect_event', 'handoff', 'handoff_event'], true) || ($kind === 'review' && ($rows['review'][$id]->definition_id ?? null))) {
                    foreach ($subject['employees'] as $employee) {
                        $all[$employee] = true;
                    }
                }
            }
            $all = array_keys($all);
            sort($all, SORT_STRING);
            $overflow = ($graph['dependency_overflow'] ?? false) || count($all) > 2000;

            return ['subjects' => $subjects, 'refs' => $overflow ? [] : $all, 'overflow' => $overflow];
        });
    }

    /** SQL visibility is applied before LIMIT. The authority batch rechecks these exact subjects. */
    public function filter(Builder $query, string $department, string $kind, string $idExpression, bool $currentReviews = false): void
    {
        if (! isset(QaPins::TABLES[$kind])) {
            throw new \LogicException('Invalid department fact.');
        }
        $edges = [];
        foreach ($this->links($currentReviews) as $source => $links) {
            foreach ($links as $field => $target) {
                $table = QaPins::TABLES[$source];
                $edges[] = "SELECT '$target'::text AS kind,t.$field AS id FROM $table t WHERE f.kind='$source' AND t.id=f.id AND t.$field IS NOT NULL";
            }
        }
        // The immutable execution receipt carries the task version, never today's inferred assignee.
        $edges[] = "SELECT 'task_version'::text AS kind,(r.envelope->'body'->>'task_version_id')::uuid AS id FROM qa_receipts r WHERE f.kind='receipt' AND r.id=f.id AND r.kind='execution'";
        $named = [];
        foreach (['receipt', 'entry', 'review', 'defect_event', 'handoff_event'] as $source) {
            $table = QaPins::TABLES[$source];
            $named[] = "SELECT t.authority_proof->>'employee_id' AS employee,true AS known FROM facts f JOIN $table t ON f.kind='$source' AND t.id=f.id";
        }
        foreach (['handoff', 'handoff_event'] as $source) {
            $table = QaPins::TABLES[$source];
            $named[] = "SELECT t.owner_id::text AS employee,true AS known FROM facts f JOIN $table t ON f.kind='$source' AND t.id=f.id";
        }
        foreach (['execution', 'handoff'] as $source) {
            $table = QaPins::TABLES[$source];
            $named[] = "SELECT t.responsibility->>'employee_id' AS employee,(t.responsibility->>'attribution'='known') AS known FROM facts f JOIN $table t ON f.kind='$source' AND t.id=f.id";
        }
        $named[] = "SELECT t.assignee_id::text AS employee,true AS known FROM facts f JOIN task_versions t ON f.kind='task_version' AND t.id=f.id";
        $links = implode(' UNION ALL ', $edges);
        $people = implode(' UNION ALL ', $named);
        $query->whereRaw("NOT EXISTS (WITH RECURSIVE facts(kind,id) AS (SELECT '$kind'::text,$idExpression UNION SELECT l.kind,l.id FROM facts f CROSS JOIN LATERAL ($links) l), named AS ($people) SELECT 1 FROM named n WHERE n.known IS NOT TRUE OR n.employee IS NULL OR (SELECT count(*) FROM department_memberships dm WHERE dm.employee_id::text=n.employee AND dm.department_id=?::uuid AND dm.valid_from<=clock_timestamp() AND (dm.valid_to IS NULL OR dm.valid_to>clock_timestamp()))<>1)", [$department]);
    }

    public function authorizeLocked(TrustedActor $actor, string $project, string $department, array $inputs, array $proofResults): array
    {
        $current = $proofResults['current'] ?? [];
        if (! ($current['valid'] ?? false) || ($current['proof']['department_id'] ?? null) !== $department || ! in_array('qa.department.read', $current['proof']['purposes'] ?? [], true) || ($current['proof']['project_id'] ?? null) !== $project || ($current['proof']['actor_id'] ?? null) !== $actor->id) {
            throw new AccessDeniedHttpException;
        }
        $view = $inputs['department_view'];
        if ($view['overflow']) {
            return ['criteria' => [], 'coverage_status' => 'unknown', 'reason_codes' => ['coverage_limit']];
        }
        $eligible = app(QaEligibility::class)->inspectLocked($project, $inputs, $proofResults);
        $criteria = [];
        // Evaluate mandatory global context independently of facts withheld from this department.
        $globalInputs = $inputs;
        $globalInputs['selected_entry_ids'] = [];
        $globalInputs['rows'] = array_replace($inputs['rows'] ?? [], ['entry' => [], 'definition' => []]);
        $global = app(QaEligibility::class)->inspectLocked($project, $globalInputs, $proofResults);
        $unavailable = ($global['coverage_status'] ?? 'unknown') !== 'complete';
        foreach ($eligible['criteria'] as $criterion) {
            if (! $this->visible($view, $current, 'entry', $criterion['entry_snapshot_id'])) {
                continue;
            }
            $tests = [];
            foreach ($criterion['tests'] as $test) {
                if (! $this->visible($view, $current, 'definition', $test['definition_version_id']) || ! $this->visible($view, $current, 'review', $test['definition_review_id'])) {
                    continue;
                }
                if ($test['execution_id'] && ! $this->visible($view, $current, 'execution', $test['execution_id'])) {
                    continue;
                }
                $tests[] = $test;
            }
            $criterion['tests'] = $tests;
            $criteria[] = $criterion;
            $unavailable = $unavailable || (bool) $criterion['reason_codes'] || (bool) array_filter($tests, fn ($test) => in_array($test['status'], ['stale', 'unknown'], true));
        }

        return ['criteria' => $criteria, 'coverage_status' => $unavailable ? 'unknown' : 'complete', 'reason_codes' => $unavailable ? ['current_verification_unavailable'] : []];
    }

    public function visible(array $view, array $current, string $kind, string $id): bool
    {
        $subject = $view['subjects'][$kind.':'.$id] ?? null;
        if (! $subject || ! $subject['known']) {
            return false;
        }
        foreach ($subject['employees'] as $employee) {
            if (($current['department_subjects'][$employee]['visibility'] ?? 'unavailable') !== 'visible') {
                return false;
            }
        }

        return true;
    }

    public function redact(array $result, array $visible): array
    {
        $result['qa_verification'] = ['basis' => 'department_authorized_entries', 'scope_basis' => 'department_authorized_entries', 'ratio_basis_points' => null];
        $result['selection'] = null;
        foreach (['numerator', 'denominator', 'ratio_basis_points', 'fully_executed_criterion_count', 'all_required_passed_criterion_count', 'excluded_criterion_count'] as $field) {
            $result['counts'][$field] = null;
        }
        unset($result['counts']['omitted_count']);
        $result['counts']['excluded'] = [];
        foreach ($result['counts']['criteria'] as &$criterion) {
            $criterion['fully_executed'] = $criterion['all_required_passed'] = null;
            // Withheld context remains mandatory internally; its private trigger never enters the DTO.
            if ($criterion['reason_codes']) {
                $criterion['reason_codes'] = ['current_verification_unavailable'];
            }
            foreach ($criterion['tests'] as &$test) {
                if ($test['reason_codes']) {
                    $test['reason_codes'] = ['current_verification_unavailable'];
                }
            } unset($test);
        } unset($criterion);
        $result['counts']['visible_record_count'] = array_sum(array_map(fn ($criterion) => count($criterion['tests']), $result['counts']['criteria']));
        $result['critical_signals'] = ['codes' => [], 'items' => []];
        foreach ($result['counts']['criteria'] as $id => $criterion) {
            foreach ($criterion['tests'] as $test) {
                if ($test['status'] === 'failed' && $test['release_critical']) {
                    $result['critical_signals']['codes'] = ['release_critical_test_failed'];
                    $result['critical_signals']['items'][] = ['code' => 'release_critical_test_failed', 'criterion_id' => $id, 'definition_version_id' => $test['definition_version_id'], 'execution_id' => $test['execution_id'], 'required' => $test['required']];
                }
            }
        }
        foreach ($visible['critical_signals'] ?? [] as $signal) {
            $result['critical_signals']['codes'][] = $signal['code'];
            $result['critical_signals']['items'][] = $signal;
        }
        $result['critical_signals']['codes'] = array_values(array_unique($result['critical_signals']['codes']));
        $omitted = max(0, count($result['critical_signals']['items']) - 100);
        $result['critical_signals']['omitted_count'] = $omitted;
        $result['critical_signals']['continuation_required'] = $omitted > 0 || ($visible['critical_continuation_required'] ?? false);
        $result['critical_signals']['items'] = array_slice($result['critical_signals']['items'], 0, 100);
        if ($result['coverage_status'] !== 'unavailable') {
            $result['coverage_status'] = $visible['coverage_status'] ?? 'unknown';
            $result['reason_codes'] = $visible['reason_codes'] ?? ['coverage_limit'];
        }

        return $result;
    }
}
