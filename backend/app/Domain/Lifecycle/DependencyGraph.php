<?php

namespace App\Domain\Lifecycle;

use Illuminate\Support\Facades\DB;
use LogicException;

class DependencyGraph
{
    /** Called only inside the consumer's authorized tenant/project transaction. */
    public function coverage(string $projectId): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.tenant_id',true),'')") === null) {
            throw new LogicException('Dependency coverage requires authorized context.');
        }
        $tasks = DB::table('lifecycle_tasks as t')->join('task_versions as v', 'v.id', '=', 't.current_version_id')->where('t.project_id', $projectId)->orderBy('t.id')->limit(101)->get(['t.id', 'v.remaining_effort']);
        $edges = DB::table('task_dependencies')->where('project_id', $projectId)->orderBy('task_id')->orderBy('prerequisite_id')->limit(1001)->get(['task_id', 'prerequisite_id']);
        $overflow = count($tasks) > 100 || count($edges) > 1000;
        $tasks = $tasks->take(100);
        $edges = $edges->take(1000);
        $missing = $tasks->filter(fn ($t) => $t->remaining_effort === null)->pluck('id')->all();
        $graph = array_fill_keys($tasks->pluck('id')->all(), []);
        foreach ($edges as $edge) {
            if (isset($graph[$edge->task_id], $graph[$edge->prerequisite_id])) {
                $graph[$edge->task_id][] = $edge->prerequisite_id;
            }
        }
        // Strongly connected components identify cycle members without marking their dependants.
        $index = 0;
        $indices = $low = $stack = $onStack = $cycles = [];
        $visit = function ($id) use (&$visit, &$index, &$indices, &$low, &$stack, &$onStack, &$cycles, $graph): void {
            $indices[$id] = $low[$id] = $index++;
            $stack[] = $id;
            $onStack[$id] = true;
            foreach ($graph[$id] as $next) {
                if (! array_key_exists($next, $indices)) {
                    $visit($next);
                    $low[$id] = min($low[$id], $low[$next]);
                } elseif ($onStack[$next] ?? false) {
                    $low[$id] = min($low[$id], $indices[$next]);
                }
            }
            if ($low[$id] === $indices[$id]) {
                $component = [];
                do {
                    $node = array_pop($stack);
                    $onStack[$node] = false;
                    $component[] = $node;
                } while ($node !== $id);
                if (count($component) > 1 || in_array($id, $graph[$id], true)) {
                    $cycles = array_merge($cycles, $component);
                }
            }
        };
        foreach (array_keys($graph) as $id) {
            if (! array_key_exists($id, $indices)) {
                $visit($id);
            }
        }
        sort($cycles, SORT_STRING);
        sort($missing, SORT_STRING);
        $reasons = [];
        if ($cycles) {
            $reasons[] = 'dependency_cycle';
        }
        if ($missing) {
            $reasons[] = 'missing_remaining_effort';
        }
        if ($overflow) {
            $reasons[] = 'traversal_limit';
        }

        return ['status' => $reasons ? 'limited' : 'complete', 'reason_codes' => $reasons, 'cycle_task_ids' => $cycles, 'missing_estimate_task_ids' => $missing];
    }
}
