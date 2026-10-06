<?php

namespace App\Domain\DeveloperAnalysis;

use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AnalysisPins
{
    public function lockSource(TrustedActor $scope, string $project, string $snapshotId): void
    {
        $snapshot = DB::table('developer_snapshots')->where('tenant_id', $scope->tenantId)->where('project_id', $project)->where('id', $snapshotId)->first();
        if ($snapshot) {
            DB::table('developer_source_heads')->where(['tenant_id' => $scope->tenantId, 'project_id' => $project, 'source_instance_id' => $snapshot->source_instance_id,
                'repository_id' => $snapshot->repository_id, 'mr_id' => $snapshot->mr_id])->sharedLock()->first();
        }
    }

    public static function normalize(array $ids): array
    {
        $keys = ['criterion_version_ids', 'task_version_ids'];
        if (array_diff(array_keys($ids), $keys) || array_diff($keys, array_keys($ids))) {
            throw ValidationException::withMessages(['pins' => 'Invalid analysis pins.']);
        }
        foreach ($keys as $key) {
            if (! is_array($ids[$key]) || ! array_is_list($ids[$key]) || count($ids[$key]) > 100) {
                throw ValidationException::withMessages(['pins' => 'Invalid analysis pins.']);
            }
            foreach ($ids[$key] as $id) {
                if (! is_string($id) || ! Str::isUuid($id)) {
                    throw ValidationException::withMessages(['pins' => 'Invalid analysis pins.']);
                }
            }
            $ids[$key] = array_values(array_unique(array_map(strtolower(...), $ids[$key])));
            sort($ids[$key], SORT_STRING);
        }

        return $ids;
    }

    public function capture(TrustedActor $actor, string $project, array $ids): array
    {
        $pins = self::normalize($ids);
        foreach (['criterion_versions' => 'criterion_version_ids', 'task_versions' => 'task_version_ids'] as $table => $key) {
            if (DB::table($table)->where('tenant_id', $actor->tenantId)->where('project_id', $project)->whereIn('id', $pins[$key])->count() !== count($pins[$key])) {
                throw new NotFoundHttpException;
            }
        }
        if (! $this->current($actor, $project, $pins)) {
            throw new ConflictHttpException;
        }

        return $pins;
    }

    public function current(TrustedActor $scope, string $project, array $pins): bool
    {
        if ($pins['criterion_version_ids']) {
            // Approved requirement rows are immutable. Reused criterion versions remain current only
            // when the exact criterion/version pair is linked by the latest approved requirement.
            $current = DB::table('criterion_versions as v')
                ->join('criteria as c', fn ($join) => $join->on('c.id', '=', 'v.criterion_id')->on('c.tenant_id', '=', 'v.tenant_id')->on('c.project_id', '=', 'v.project_id'))
                ->join('requirement_version_criteria as l', fn ($join) => $join->on('l.criterion_version_id', '=', 'v.id')->on('l.criterion_id', '=', 'c.id')->on('l.tenant_id', '=', 'v.tenant_id')->on('l.project_id', '=', 'v.project_id'))
                ->join('requirement_versions as r', fn ($join) => $join->on('r.id', '=', 'l.requirement_version_id')->on('r.requirement_id', '=', 'c.requirement_id')->on('r.tenant_id', '=', 'v.tenant_id')->on('r.project_id', '=', 'v.project_id'))
                ->where('v.tenant_id', $scope->tenantId)->where('v.project_id', $project)->whereIn('v.id', $pins['criterion_version_ids'])
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('requirement_versions as newer')
                        ->whereColumn('newer.tenant_id', 'r.tenant_id')->whereColumn('newer.project_id', 'r.project_id')
                        ->whereColumn('newer.requirement_id', 'r.requirement_id')->whereColumn('newer.version', '>', 'r.version');
                })->distinct()->pluck('v.id')->all();
            if (count($current) !== count($pins['criterion_version_ids'])) {
                return false;
            }
        }
        if ($pins['task_version_ids']) {
            $current = DB::table('lifecycle_tasks')->where('tenant_id', $scope->tenantId)->where('project_id', $project)
                ->whereIn('current_version_id', $pins['task_version_ids'])->pluck('current_version_id')->all();
            if (count($current) !== count($pins['task_version_ids'])) {
                return false;
            }
        }

        return true;
    }

    /** Coherent nonmutating delivery check; R5 records the same observed failures durably. */
    public function applicability(TrustedActor $scope, object $run): string
    {
        if ($run->applicability !== 'current') {
            return $run->applicability;
        }
        $snapshot = DB::table('developer_snapshots')->where('tenant_id', $scope->tenantId)->where('project_id', $run->project_id)->where('id', $run->snapshot_id)->first();
        if (! $snapshot || DB::table('lifecycle_project_state')->where('project_id', $run->project_id)->value('is_closed')
            || ! $this->snapshotCurrent($scope, $run->project_id, $snapshot) || ! $this->current($scope, $run->project_id, json_decode($run->pins, true))) {
            return 'stale';
        }

        return 'current';
    }

    /** Shared by request, read and future publication/invalidation boundaries under project coordination. */
    public function snapshotCurrent(TrustedActor $scope, string $project, object $snapshot): bool
    {
        $source = ['tenant_id' => $scope->tenantId, 'project_id' => $project, 'source_instance_id' => $snapshot->source_instance_id,
            'repository_id' => $snapshot->repository_id, 'mr_id' => $snapshot->mr_id];
        if (DB::table('developer_source_heads')->where($source)->value('snapshot_id') !== $snapshot->id) {
            return false;
        }

        // A rejected correction is still observed evidence. An old approved head cannot hide it.
        return ! DB::table('developer_source_events')->where($source)->where(function ($query) use ($snapshot): void {
            $query->where('revision_ordinal', '>', $snapshot->revision_ordinal)
                ->orWhere(function ($same) use ($snapshot): void {
                    $same->where('revision_ordinal', $snapshot->revision_ordinal)->whereNull('snapshot_id');
                });
        })->exists();
    }
}
