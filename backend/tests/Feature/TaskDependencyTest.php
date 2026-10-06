<?php

namespace Tests\Feature;

use App\Domain\Lifecycle\DependencyGraph;
use App\Domain\Lifecycle\RequirementService;
use App\Domain\Lifecycle\TaskService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LifecycleTestCase;

class TaskDependencyTest extends LifecycleTestCase
{
    private function task(array $overrides = []): array
    {
        return $overrides + ['title' => 'Synthetic task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic integer effort units, not worked hours', 'remaining_effort' => 5, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => []];
    }

    public function test_done_does_not_create_acceptance(): void
    {
        $p = $this->lifecycle();
        $this->withToken($this->token)->postJson('/api/v1/projects/'.$p.'/lifecycle-tasks', $this->task(['status' => 'done']) + ['expected_generation' => 0])->assertCreated()->assertJsonPath('data.revision', 1)->assertJsonPath('data.generation', 1);
        app(TenantContext::class)->run($this->tenant, function () {
            $this->assertSame('done', DB::table('task_versions')->value('status'));
            $this->assertSame(0, DB::table('lifecycle_changes')->count());
            $this->assertSame(0, DB::table('lifecycle_outbox')->count());
        });
    }

    public function test_self_and_cross_project_edges_rejected(): void
    {
        $p = $this->lifecycle();
        $other = $this->project();
        $this->grant('requirements.manage', $other);
        $s = app(TaskService::class);
        $a = $s->create($this->actor(), $p, $this->task(), 0);
        $b = $s->create($this->actor(), $other, $this->task(), 0);
        $url = '/api/v1/projects/'.$p.'/lifecycle-tasks/'.$a['id'].'/dependencies';
        $this->withToken($this->token)->postJson($url, ['prerequisite_id' => $a['id'], 'expected_generation' => 1])->assertUnprocessable();
        $this->postJson($url, ['prerequisite_id' => strtoupper($a['id']), 'expected_generation' => 1])->assertUnprocessable();
        $this->postJson($url, ['prerequisite_id' => $b['id'], 'expected_generation' => 1])->assertNotFound();
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(0, DB::table('task_dependency_events')->count()));
    }

    public function test_cycle_and_missing_effort_are_explicit(): void
    {
        $p = $this->lifecycle();
        $s = app(TaskService::class);
        $a = $s->create($this->actor(), $p, $this->task(), 0);
        $b = $s->create($this->actor(), $p, $this->task(['remaining_effort' => null]), 1);
        $s->depend($this->actor(), $p, $a['id'], $b['id'], 2);
        $s->depend($this->actor(), $p, $b['id'], $a['id'], 3);
        $coverage = app(TenantContext::class)->run($this->tenant, fn () => app(DependencyGraph::class)->coverage($p));
        $this->assertSame('limited', $coverage['status']);
        $this->assertSame(['dependency_cycle', 'missing_remaining_effort'], $coverage['reason_codes']);
        $this->assertEqualsCanonicalizing([$a['id'], $b['id']], $coverage['cycle_task_ids']);
        $this->assertSame([$b['id']], $coverage['missing_estimate_task_ids']);
        $this->assertSame(['status', 'reason_codes', 'cycle_task_ids', 'missing_estimate_task_ids'], array_keys($coverage));
    }

    public function test_revision_generation_assignee_and_immutable_history(): void
    {
        $p = $this->lifecycle();
        $base = '/api/v1/projects/'.$p.'/lifecycle-tasks';
        $this->withToken($this->token);
        $a = $this->postJson($base, $this->task() + ['expected_generation' => 0])->assertCreated()->json('data');
        $this->patchJson($base.'/'.$a['id'], $this->task(['status' => 'in_progress']) + ['expected_revision' => 1, 'expected_generation' => 1])->assertOk()->assertJsonPath('data.revision', 2);
        $this->patchJson($base.'/'.$a['id'], $this->task() + ['expected_revision' => 1, 'expected_generation' => 2])->assertConflict();
        $this->postJson($base, $this->task() + ['expected_generation' => 1])->assertConflict();
        $this->postJson($base, $this->task(['assignee_id' => (string) Str::uuid()]) + ['expected_generation' => 2])->assertUnprocessable();
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data');
        $this->json('HEAD', $base)->assertOk();
        app(TenantContext::class)->run($this->tenant, function () use ($a) {
            $this->assertSame(2, DB::table('task_versions')->count());
            $this->assertSame(2, DB::table('lifecycle_project_state')->value('generation'));
            $time = DB::scalar("select to_char(effective_at at time zone 'UTC','YYYY-MM-DD HH24:MI:SS.US') from task_versions where id=?", [$a['version_id']]);
            $this->assertMatchesRegularExpression('/\.\d{6}$/', $time);
            try {
                DB::transaction(fn () => DB::table('task_versions')->where('id', $a['version_id'])->update(['title' => 'Changed']));
                $this->fail('Immutable task snapshot changed');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
        });
    }

    public function test_strict_payload_and_explicit_grants(): void
    {
        $p = $this->lifecycle();
        $url = '/api/v1/projects/'.$p.'/lifecycle-tasks';
        $this->withToken($this->token);
        $raw = json_encode($this->task() + ['expected_generation' => 0]);
        foreach ([str_replace('"title":"Synthetic task"', '"title":"Bad","title":"Other"', $raw), str_replace('"criterion_links":[]', '"criterion_links":{}', $raw), str_replace('"remaining_effort":5', '"remaining_effort":5.0', $raw), json_encode($this->task() + ['expected_generation' => 0, 'tenant_id' => $this->tenant])] as $bad) {
            $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], $bad)->assertUnprocessable();
        }
        $other = $this->project();
        $this->grant('requirements.manage');
        $this->postJson('/api/v1/projects/'.$other.'/lifecycle-tasks', $this->task() + ['expected_generation' => 0])->assertForbidden();
    }

    public function test_links_pin_current_criterion_and_preserve_previous_task_snapshot(): void
    {
        $p = $this->lifecycle();
        $requirements = app(RequirementService::class);
        $draft = $requirements->createDraft($this->actor(), $p, $this->requirement());
        $a = $requirements->approve($this->actor(), $p, $draft['id'], 1, 0, 'Synthetic');
        $link = ['criterion_id' => $a['criteria'][0]['id'], 'criterion_version_id' => $a['criteria'][0]['version_id']];
        $s = app(TaskService::class);
        $task = $s->create($this->actor(), $p, $this->task(['criterion_links' => [$link]]), 1);
        $data = $this->requirement();
        $data['criteria'][0]['text'] = 'Changed';
        $requirements->updateDraft($this->actor(), $p, $draft['id'], $data, 1);
        $b = $requirements->approve($this->actor(), $p, $draft['id'], 2, 2, 'Synthetic');
        $url = '/api/v1/projects/'.$p.'/lifecycle-tasks/'.$task['id'];
        $this->withToken($this->token)->patchJson($url, $this->task(['criterion_links' => [$link]]) + ['expected_revision' => 1, 'expected_generation' => 3])->assertConflict();
        $newLink = ['criterion_id' => $b['criteria'][0]['id'], 'criterion_version_id' => $b['criteria'][0]['version_id']];
        $new = $s->revise($this->actor(), $p, $task['id'], $this->task(['criterion_links' => [$newLink]]), 1, 3);
        app(TenantContext::class)->run($this->tenant, function () use ($task, $new, $link, $newLink) {
            $this->assertSame($link['criterion_version_id'], DB::table('task_criterion_links')->where('task_version_id', $task['version_id'])->value('criterion_version_id'));
            $this->assertSame($newLink['criterion_version_id'], DB::table('task_criterion_links')->where('task_version_id', $new['version_id'])->value('criterion_version_id'));
        });
    }

    public function test_dependency_overflow_never_claims_complete(): void
    {
        $p = $this->lifecycle();
        $tasks = [];
        for ($i = 0; $i < 34; $i++) {
            $tasks[] = app(TaskService::class)->create($this->actor(), $p, $this->task(), $i)['id'];
        }
        app(TenantContext::class)->run($this->tenant, function () use ($p, $tasks) {
            $events = $edges = [];
            foreach ($tasks as $task) {
                foreach ($tasks as $prerequisite) {
                    if ($task === $prerequisite || count($events) >= 1001) {
                        continue;
                    }
                    $id = (string) Str::uuid();
                    $edge = ['tenant_id' => $this->tenant, 'project_id' => $p, 'task_id' => $task, 'prerequisite_id' => $prerequisite];
                    $events[] = ['id' => $id, 'actor_id' => $this->user] + $edge;
                    $edges[] = ['event_id' => $id] + $edge;
                }
            }
            DB::table('task_dependency_events')->insert($events);
            DB::table('task_dependencies')->insert($edges);
            $coverage = app(DependencyGraph::class)->coverage($p);
            $this->assertSame('limited', $coverage['status']);
            $this->assertContains('traversal_limit', $coverage['reason_codes']);
        });
    }

    public function test_scoped_foreign_keys_rls_and_revocation(): void
    {
        $p = $this->lifecycle();
        $other = $this->project();
        $this->grant('requirements.manage', $other);
        $s = app(TaskService::class);
        $a = $s->create($this->actor(), $p, $this->task(), 0);
        $b = $s->create($this->actor(), $other, $this->task(), 0);
        $this->assertSame(0, DB::table('lifecycle_tasks')->count());
        app(TenantContext::class)->run($this->tenant, function () use ($p, $a, $b) {
            try {
                DB::transaction(fn () => DB::table('task_dependency_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $p, 'task_id' => $a['id'], 'prerequisite_id' => $b['id'], 'actor_id' => $this->user]));
                $this->fail('Cross-project edge accepted');
            } catch (QueryException $e) {
                $this->assertSame('23503', $e->errorInfo[0]);
            }
            DB::table('access_grants')->where('project_id', $p)->where('purpose', 'requirements.read')->update(['valid_to' => now()->subSecond()]);
        });
        $this->withToken($this->token)->getJson('/api/v1/projects/'.$p.'/lifecycle-tasks')->assertForbidden();
    }

    public function test_optional_source_receipt_requires_read_grant_and_full_binding(): void
    {
        $p = $this->lifecycle();
        $source = (string) Str::uuid();
        $connector = (string) Str::uuid();
        $receipt = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($p, $source, $connector, $receipt) {
            DB::table('source_instances')->insert(['id' => $source, 'tenant_id' => $this->tenant, 'kind' => 'manual', 'label' => 'Synthetic task binding source']);
            DB::table('ingestion_connectors')->insert(['id' => $connector, 'tenant_id' => $this->tenant, 'project_id' => $p, 'source_instance_id' => $source]);
            DB::table('ingestion_receipts')->insert(['id' => $receipt, 'tenant_id' => $this->tenant, 'project_id' => $p, 'connector_id' => $connector, 'source_instance_id' => $source, 'source_object_id' => 'synthetic-task', 'source_version' => 'v1', 'event_kind' => 'ticket.snapshot', 'payload_bytes' => '{}', 'payload_json' => '{}', 'transport_hash' => hash('sha256', '{}'), 'canonical_hash' => hash('sha256', '{}'), 'occurred_at' => now(), 'received_at' => now(), 'accepted_by' => $this->user]);
        });
        $binding = ['connector_id' => $connector, 'source_instance_id' => $source, 'source_object_type' => 'ticket', 'source_object_id' => 'synthetic-task', 'receipt_id' => $receipt];
        $url = '/api/v1/projects/'.$p.'/lifecycle-tasks';
        $this->withToken($this->token)->postJson($url, $this->task(['source_receipt' => $binding]) + ['expected_generation' => 0])->assertForbidden();
        $this->grant('ingestion.read', $p);
        $wrong = $binding;
        $wrong['source_object_id'] = 'wrong-task';
        $this->postJson($url, $this->task(['source_receipt' => $wrong]) + ['expected_generation' => 0])->assertNotFound();
        $result = $this->postJson($url, $this->task(['source_receipt' => $binding]) + ['expected_generation' => 0])->assertCreated()->json('data');
        app(TenantContext::class)->run($this->tenant, function () use ($result, $receipt, $wrong) {
            $this->assertSame($receipt, DB::table('task_versions')->where('id', $result['version_id'])->value('source_receipt_id'));
            $original = (array) DB::table('task_versions')->where('id', $result['version_id'])->first();
            $original['id'] = (string) Str::uuid();
            $original['revision'] = 2;
            $original['source_object_id'] = $wrong['source_object_id'];
            try {
                DB::transaction(fn () => DB::table('task_versions')->insert($original));
                $this->fail('Receipt object binding was widened');
            } catch (QueryException $e) {
                $this->assertSame('23503', $e->errorInfo[0]);
            }
        });
    }
}
