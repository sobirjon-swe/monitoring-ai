<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\CompliantValidator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TaskService
{
    public function __construct(private readonly ProjectCoordination $coordination, private readonly AuditWriter $audit) {}

    public function create(TrustedActor $actor, string $project, array $data, int $expectedGeneration): array
    {
        $data = $this->validate($data);
        $r = $this->coordination->run($actor, $project, 'requirements.manage', $expectedGeneration, function () use ($actor, $project, $data): array {
            if (DB::table('lifecycle_tasks')->where('project_id', $project)->count() >= 100) {
                $this->invalid();
            }
            $id = (string) Str::uuid();
            $version = (string) Str::uuid();
            DB::table('lifecycle_tasks')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'revision' => 1, 'current_version_id' => $version]);

            return $this->snapshot($actor, $project, $id, $version, $data, 1);
        });

        return $r['result'] + ['generation' => $r['generation']];
    }

    public function revise(TrustedActor $actor, string $project, string $task, array $data, int $expectedRevision, int $expectedGeneration): array
    {
        $data = $this->validate($data);
        $r = $this->coordination->run($actor, $project, 'requirements.manage', $expectedGeneration, function () use ($actor, $project, $task, $data, $expectedRevision): array {
            $old = $this->task($project, $task);
            if ($old->revision !== $expectedRevision) {
                throw new ConflictHttpException;
            }
            $version = (string) Str::uuid();
            $result = $this->snapshot($actor, $project, $task, $version, $data, $expectedRevision + 1);
            DB::table('lifecycle_tasks')->where('id', $task)->update(['revision' => $expectedRevision + 1, 'current_version_id' => $version]);

            return $result;
        });

        return $r['result'] + ['generation' => $r['generation']];
    }

    public function depend(TrustedActor $actor, string $project, string $task, string $prerequisite, int $expectedGeneration): array
    {
        if (! Str::isUuid($task) || ! Str::isUuid($prerequisite) || strtolower($task) === strtolower($prerequisite)) {
            $this->invalid();
        }
        $r = $this->coordination->run($actor, $project, 'requirements.manage', $expectedGeneration, function () use ($actor, $project, $task, $prerequisite): array {
            $this->task($project, $task);
            $this->task($project, $prerequisite);
            if (DB::table('task_dependencies')->where('project_id', $project)->where('task_id', $task)->where('prerequisite_id', $prerequisite)->exists()) {
                throw new ConflictHttpException;
            }
            $id = (string) Str::uuid();
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
            $edge = ['task_id' => $task, 'prerequisite_id' => $prerequisite] + $scope;
            DB::table('task_dependency_events')->insert(['id' => $id, 'actor_id' => $actor->id] + $edge);
            DB::table('task_dependencies')->insert(['event_id' => $id] + $edge);
            $this->audit->record($actor->id, $scope, 'task.dependency_created', ['type' => 'task_dependency_events', 'id' => $id], 1, 'approved_change');

            return ['id' => $id, 'task_id' => $task, 'prerequisite_id' => $prerequisite];
        });

        return $r['result'] + ['generation' => $r['generation']];
    }

    public function listing(TrustedActor $actor, string $project, int $limit = 20, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > 100 || ($cursor !== null && ! Str::isUuid($cursor))) {
            $this->invalid();
        }

        return $this->coordination->read($actor, $project, 'requirements.read', function () use ($project, $limit, $cursor): array {
            $q = DB::table('lifecycle_tasks as t')->join('task_versions as v', 'v.id', '=', 't.current_version_id')->where('t.project_id', $project)->orderBy('t.id');
            if ($cursor !== null) {
                $q->where('t.id', '>', $cursor);
            }

            return $q->limit($limit)->get(['t.id', 't.revision', 'v.id as version_id', 'v.title', 'v.assignee_id', 'v.estimate_basis', 'v.remaining_effort', 'v.expected_test_keys', 'v.status', 'v.content_hash', 'v.effective_at'])->map(function ($v): array {
                $data = (array) $v;
                $data['expected_test_keys'] = json_decode($data['expected_test_keys'], true, 16, JSON_THROW_ON_ERROR);

                return $data;
            })->all();
        });
    }

    private function task(string $project, string $id): object
    {
        return DB::table('lifecycle_tasks')->where('project_id', $project)->where('id', $id)->first() ?? throw new NotFoundHttpException;
    }

    private function snapshot(TrustedActor $actor, string $project, string $task, string $version, array $data, int $revision): array
    {
        if (! DB::table('project_memberships')->where('project_id', $project)->where('employee_id', $data['assignee_id'])->where('valid_from', '<=', now())->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()))->exists()) {
            $this->invalid();
        }
        foreach ($data['criterion_links'] as $link) {
            $criterion = DB::table('criteria')->where('project_id', $project)->where('id', $link['criterion_id'])->first();
            $current = $criterion ? DB::table('requirement_versions')->where('project_id', $project)->where('requirement_id', $criterion->requirement_id)->orderByDesc('version')->value('id') : null;
            if (! $current || ! DB::table('requirement_version_criteria')->where('requirement_version_id', $current)->where('criterion_id', $link['criterion_id'])->where('criterion_version_id', $link['criterion_version_id'])->exists()) {
                throw new ConflictHttpException;
            }
        }
        $source = [];
        if (isset($data['source_receipt'])) {
            app(AccessGateway::class)->authorize($actor, 'ingestion.read', ['tenant_id' => $actor->tenantId, 'project_id' => $project]);
            $s = $data['source_receipt'];
            if (! DB::table('ingestion_receipts')->where('project_id', $project)->where('id', $s['receipt_id'])->where('connector_id', $s['connector_id'])->where('source_instance_id', $s['source_instance_id'])->where('source_object_type', $s['source_object_type'])->where('source_object_id', $s['source_object_id'])->exists()) {
                throw new NotFoundHttpException;
            }
            $source = ['source_connector_id' => $s['connector_id'], 'source_instance_id' => $s['source_instance_id'], 'source_object_type' => $s['source_object_type'], 'source_object_id' => $s['source_object_id'], 'source_receipt_id' => $s['receipt_id']];
        }
        $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        DB::table('task_versions')->insert(['id' => $version, 'task_id' => $task, 'revision' => $revision, 'title' => $data['title'], 'assignee_id' => $data['assignee_id'], 'estimate_basis' => $data['estimate_basis'], 'remaining_effort' => $data['remaining_effort'], 'expected_test_keys' => json_encode($data['expected_test_keys'], JSON_THROW_ON_ERROR), 'status' => $data['status'], 'content_hash' => $hash, 'actor_id' => $actor->id] + $scope + $source);
        foreach ($data['criterion_links'] as $link) {
            DB::table('task_criterion_links')->insert(['id' => (string) Str::uuid(), 'task_id' => $task, 'task_version_id' => $version] + $scope + $link);
        }
        $this->audit->record($actor->id, $scope, $revision === 1 ? 'task.created' : 'task.revised', ['type' => 'task_versions', 'id' => $version], $revision, 'approved_change');

        return ['id' => $task, 'version_id' => $version, 'revision' => $revision, 'hash' => $hash];
    }

    public function validate(array $data): array
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $schema = json_decode(file_get_contents(base_path('../contracts/lifecycle-task-v1.schema.json')), false, 16, JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536 || str_contains($json, '\\u0000') || ! (new CompliantValidator)->validate(json_decode($json, false, 16, JSON_THROW_ON_ERROR), $schema)->isValid() || ! trim($data['title']) || ! trim($data['estimate_basis']) || ($data['remaining_effort'] !== null && ! is_int($data['remaining_effort']))) {
            $this->invalid();
        }
        $keys = [];
        foreach ($data['criterion_links'] as $link) {
            if (in_array($link['criterion_id'], $keys, true)) {
                $this->invalid();
            }
            $keys[] = $link['criterion_id'];
        }
        foreach ($data['expected_test_keys'] as $key) {
            if (! trim($key)) {
                $this->invalid();
            }
        }
        sort($data['expected_test_keys'], SORT_STRING);
        usort($data['criterion_links'], fn ($a, $b) => strcmp($a['criterion_id'], $b['criterion_id']));
        foreach ($data['criterion_links'] as &$link) {
            ksort($link, SORT_STRING);
        }
        unset($link);
        if (isset($data['source_receipt'])) {
            ksort($data['source_receipt'], SORT_STRING);
        }
        ksort($data, SORT_STRING);

        return $data;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['task' => 'Invalid task.']);
    }
}
