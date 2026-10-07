<?php

namespace App\Domain\Qa;

final class QaVerificationCalculator
{
    public function calculate(array $eligiblePlan): array
    {
        $selection = $eligiblePlan['selection'] ?? null;
        $reasons = $eligiblePlan['reason_codes'] ?? [];
        $counts = ['numerator' => $selection === null ? null : 0, 'denominator' => $selection === null ? null : 0, 'ratio_basis_points' => null, 'fully_executed_criterion_count' => 0, 'all_required_passed_criterion_count' => 0, 'excluded_criterion_count' => 0, 'criteria' => [], 'excluded' => [], 'omitted_count' => $eligiblePlan['omitted_count'] ?? 0];
        $signals = [];
        foreach ($eligiblePlan['criteria'] ?? [] as $entry) {
            $id = $entry['criterion_id'];
            if (isset($counts['criteria'][$id]) || isset($counts['excluded'][$id])) {
                $reasons[] = 'duplicate_criterion';

                continue;
            }
            if (! $entry['included']) {
                $counts['excluded'][$id] = ['criterion_version_id' => $entry['criterion_version_id'] ?? null, 'reason' => $entry['exclusion_reason'] ?? null];
                $counts['excluded_criterion_count']++;

                continue;
            }
            $counts['denominator']++;
            $group = ['required' => 0, 'optional' => 0, 'completed' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0, 'missing' => 0, 'stale' => 0, 'unknown' => 0, 'required_completed' => 0, 'required_passed' => 0, 'fully_executed' => false, 'all_required_passed' => false, 'tests' => $entry['tests'], 'reason_codes' => $entry['reason_codes'] ?? []];
            $group['required_counts'] = $group['optional_counts'] = array_fill_keys(['completed', 'passed', 'failed', 'blocked', 'missing', 'stale', 'unknown'], 0);
            foreach ($entry['tests'] as $test) {
                $status = in_array($test['status'] ?? '', ['passed', 'failed', 'blocked', 'missing', 'stale', 'unknown'], true) ? $test['status'] : 'unknown';
                $required = $test['required'];
                $group[$required ? 'required' : 'optional']++;
                $group[$status]++;
                $category = $required ? 'required_counts' : 'optional_counts';
                $group[$category][$status]++;
                if (in_array($status, ['passed', 'failed'], true)) {
                    $group['completed']++;
                    $group[$category]['completed']++;
                    $group['required_completed'] += (int) $required;
                }
                $group['required_passed'] += (int) ($required && $status === 'passed');
                if ($status === 'failed' && ($test['release_critical'] ?? false)) {
                    $signals[] = ['code' => 'release_critical_test_failed', 'criterion_id' => $id, 'definition_version_id' => $test['definition_version_id'] ?? null, 'execution_id' => $test['execution_id'] ?? null, 'required' => $required];
                }
            }
            if ($group['required'] === 0) {
                $group['reason_codes'][] = 'definition_missing';
                $reasons[] = 'definition_missing';
            }
            $group['fully_executed'] = $group['required'] > 0 && $group['required_completed'] === $group['required'];
            $group['all_required_passed'] = $group['required'] > 0 && $group['required_passed'] === $group['required'];
            $counts['numerator'] += (int) ($group['completed'] > 0);
            $counts['fully_executed_criterion_count'] += (int) $group['fully_executed'];
            $counts['all_required_passed_criterion_count'] += (int) $group['all_required_passed'];
            $counts['criteria'][$id] = $group;
        }
        foreach ($eligiblePlan['critical_signals'] ?? [] as $signal) {
            $signals[] = $signal;
        }
        if ($counts['denominator'] > 0) {
            $counts['ratio_basis_points'] = intdiv(10000 * $counts['numerator'], $counts['denominator']);
        }
        if ($selection === null) {
            $reasons[] = 'plan_missing';
        }
        $coverage = $selection === null ? 'unknown' : ($eligiblePlan['coverage_status'] ?? 'complete');
        $omitted = max(0, count($signals) - 100);

        return ['qa_verification' => ['basis' => 'distinct_included_criteria_with_current_completed_execution', 'scope_basis' => 'approved_plan', 'ratio_basis_points' => $counts['ratio_basis_points']], 'selection' => $selection, 'counts' => $counts, 'critical_signals' => ['codes' => array_values(array_unique(array_column($signals, 'code'))), 'items' => array_slice($signals, 0, 100), 'omitted_count' => $omitted, 'continuation_required' => $omitted > 0 || ($eligiblePlan['critical_continuation_required'] ?? false)], 'coverage_status' => $coverage, 'reason_codes' => array_values(array_unique($reasons)), 'synthetic' => true, 'ai_interpretation' => 'unavailable'];
    }
}
