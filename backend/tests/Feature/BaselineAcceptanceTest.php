<?php

namespace Tests\Feature;

use App\Domain\Ingestion\IngestionService;
use App\Domain\Lifecycle\BaselineService;
use App\Domain\Lifecycle\DodPolicyService;
use App\Domain\Lifecycle\QaObservationService;
use App\Domain\Lifecycle\RequirementService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\BaselineTestCase;

class BaselineAcceptanceTest extends BaselineTestCase
{
    public function test_source_grant_restored_after_calculation_cannot_freeze_redacted_progress(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        $this->accept($this->evidence());
        $before = $this->historyState();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $this->readGrant)->update(['revoked_at' => now()]));
        $restored = false;
        // Real calculation has returned redaction before the close event is inserted; restore access before freeze.
        DB::listen(function ($query) use (&$restored) {
            if (! $restored && str_starts_with($query->sql, 'insert into "lifecycle_changes"')) {
                $restored = true;
                DB::table('access_grants')->where('id', $this->readGrant)->update(['revoked_at' => null]);
            }
        });
        try {
            app(BaselineService::class)->close($this->actor(), $this->p, $this->generation, 'Synthetic restored grant');
            $this->fail('Restored grant allowed previously redacted progress to become history');
        } catch (AccessDeniedHttpException) {
            $this->assertTrue($restored);
            $this->assertSame($before, $this->historyState());
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $this->readGrant)->update(['revoked_at' => null]));
        $this->assertSame(67, $this->progress()['accepted_weight']);
        app(BaselineService::class)->close($this->actor(), $this->p, $this->generation, 'Synthetic authorized retry');
        $this->assertSame(67, $this->progress()['accepted_weight']);
    }

    public function test_separate_approver_cannot_freeze_redacted_history(): void
    {
        $this->assertSeparateApproverHistory('close');
    }

    public function test_separate_approver_cannot_freeze_redacted_replacement_history(): void
    {
        $this->assertSeparateApproverHistory('replace');
    }

    private function assertSeparateApproverHistory(string $operation): void
    {
        $this->inputs();
        $original = $this->user;
        $approver = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($approver) {
            $employee = (string) Str::uuid();
            DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic approver']);
            DB::table('users')->insert(['id' => $approver, 'tenant_id' => $this->tenant, 'employee_id' => $employee, 'display_name' => 'Synthetic approver']);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
        });
        $this->user = $approver;
        $this->grant('baseline.approve', $this->p);
        $this->baselineId = null;
        $this->baseline($this->scope()); // Initial scope creates no historical snapshot.
        $this->user = $original;
        $this->accept($this->evidence());
        $old = $this->baselineId;
        $before = $this->historyState();
        $this->user = $approver;
        try {
            $operation === 'close'
                ? app(BaselineService::class)->close($this->actor(), $this->p, $this->generation, 'Synthetic close')
                : $this->baseline($this->scope());
            $this->fail('Approver without independent source access froze history');
        } catch (AccessDeniedHttpException) {
            $this->assertSame($before, $this->historyState());
        }
        $this->user = $original;
        $this->assertSame(67, $this->progress()['accepted_weight']);
        $this->assertSame(100, $this->progress()['total_weight']);
        $this->user = $approver;
        $grant = $this->grant('ingestion.read', $this->p);
        if ($operation === 'close') {
            $r = app(BaselineService::class)->close($this->actor(), $this->p, $this->generation, 'Synthetic close');
            $this->generation = $r['generation'];
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
            $this->baseline($this->scope(), true); // Already frozen history does not require new source access.
        } else {
            $this->baseline($this->scope());
        }
        $this->user = $original;
        $this->assertSame(67, $this->progress($old)['accepted_weight']);
        $this->assertSame(100, $this->progress($old)['total_weight']);
    }

    private function historyState(): array
    {
        return app(TenantContext::class)->run($this->tenant, fn () => [
            (array) DB::table('lifecycle_project_state')->where('project_id', $this->p)->first(),
            ...array_map(fn ($table) => DB::table($table)->where('project_id', $this->p)->count(), ['baselines', 'baseline_items', 'baseline_progress_snapshots', 'lifecycle_changes', 'lifecycle_outbox']),
        ]);
    }

    public function test_all_six_action_times_follow_server_observation_inside_outer_transaction(): void
    {
        $observations = [];
        $this->inputs();
        $data = $this->requirement();
        $data['criteria'][] = ['key' => 'C', 'text' => 'Synthetic C', 'required_test_keys' => ['test-c']];
        app(RequirementService::class)->updateDraft($this->actor(), $this->p, $this->draft['id'], $data, 1);
        $observations['requirement_versions.approved_at'] = DB::scalar('select clock_timestamp()');
        $version = app(RequirementService::class)->approve($this->actor(), $this->p, $this->draft['id'], 2, $this->generation, 'Synthetic action time');
        $this->generation = $version['generation'];
        $this->criteria = $version['criteria'];
        $observations['dod_policy_versions.approved_at'] = DB::scalar('select clock_timestamp()');
        $policy = app(DodPolicyService::class)->approve($this->actor(), $this->p, ['fictional' => true, 'mandatory_qa' => true, 'human_acceptance' => true], $this->generation);
        $this->generation = $policy['generation'];
        $this->policyId = $policy['id'];
        $observations['baselines.effective_at'] = DB::scalar('select clock_timestamp()');
        $this->baseline($this->scope());
        $link = $this->evidence();
        $observations['human_acceptance_decisions.decided_at'] = DB::scalar('select clock_timestamp()');
        $this->accept($link);
        $observations['baseline_progress_snapshots.as_of'] = DB::scalar('select clock_timestamp()');
        $observations['lifecycle_changes.occurred_at'] = $observations['baseline_progress_snapshots.as_of'];
        app(BaselineService::class)->close($this->actor(), $this->p, $this->generation, 'Synthetic close');
        app(TenantContext::class)->run($this->tenant, function () use ($observations) {
            $upper = DB::scalar('select clock_timestamp()');
            $start = DB::scalar('select transaction_timestamp()');
            foreach ($observations as $field => $lower) {
                [$table, $column] = explode('.', $field);
                $query = DB::table($table)->where('project_id', $this->p);
                if (in_array($table, ['requirement_versions', 'dod_policy_versions'], true)) {
                    $query->orderByDesc('version');
                }
                if ($table === 'lifecycle_changes') {
                    $query->where('subject_kind', 'project_closed');
                }
                $stored = $query->value($column);
                $this->assertNotNull($stored, $field);
                $this->assertTrue(DB::scalar('select ?::timestamptz >= ?::timestamptz and ?::timestamptz <= ?::timestamptz and ?::timestamptz > ?::timestamptz', [$stored, $lower, $stored, $upper, $stored, $start]), $field.' must reflect action time, not outer transaction start');
            }
        });
    }

    public function test_acceptance_rejects_json_object_disguised_as_link_list(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        $l = $this->evidence();
        $d = ['baseline_id' => $this->baselineId, 'criterion_version_id' => $this->criteria[0]['version_id'], 'reviewed_link_ids' => (object) ['0' => $l['id']], 'expected_generation' => $this->generation, 'reason' => 'Synthetic'];
        $this->call('POST', '/api/v1/projects/'.$this->p.'/acceptance-decisions', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], json_encode($d))->assertUnprocessable();
        $this->assertSame(0, $this->progress()['accepted_weight']);
    }

    public function test_policy_missing_zero_scope_and_stale_revision(): void
    {
        $this->inputs();
        $this->assertSame('unavailable', $this->progress()['status']);
        $this->assertNull($this->progress()['percentage_basis_points']);
        $this->withToken($this->token);
        $url = '/api/v1/projects/'.$this->p.'/baselines';
        foreach ([0, -1] as $w) {
            $items = $this->scope();
            $items[0]['weight'] = $w;
            $this->postJson($url, ['items' => $items, 'policy_id' => $this->policyId, 'expected_current_baseline_id' => null, 'expected_generation' => 2, 'reason' => 'Synthetic'])->assertUnprocessable();
        }
        $items = $this->scope();
        $items[1] = $items[0];
        $this->postJson($url, ['items' => $items, 'policy_id' => $this->policyId, 'expected_current_baseline_id' => null, 'expected_generation' => 2, 'reason' => 'Synthetic'])->assertUnprocessable();
        $this->baseline([]);
        $this->assertSame(0, $this->progress()['total_weight']);
        $this->assertNull($this->progress()['percentage_basis_points']);
        $this->postJson($url, ['items' => $this->scope(), 'policy_id' => $this->policyId, 'expected_current_baseline_id' => null, 'expected_generation' => $this->generation, 'reason' => 'Synthetic'])->assertConflict();
    }

    public function test_closure_freezes_and_reopen_new_version(): void
    {
        $this->inputs();
        $old = $this->baseline($this->scope());
        $d = $this->accept($this->evidence());
        $r = app(BaselineService::class)->close($this->actor(), $this->p, $this->generation, 'Synthetic closure');
        $this->generation = $r['generation'];
        $this->withToken($this->token);
        $url = '/api/v1/projects/'.$this->p.'/baselines';
        $payload = ['items' => $this->scope(), 'policy_id' => $this->policyId, 'expected_current_baseline_id' => $old['id'], 'expected_generation' => $this->generation, 'reason' => 'Synthetic'];
        $this->postJson($url, $payload)->assertConflict();
        $new = $this->baseline($this->scope([67, 20, 13], $d['id']), true);
        $this->assertNotSame($old['id'], $new['id']);
        $this->assertSame(67, $this->progress()['accepted_weight']);
        $payload['reopen'] = true;
        $this->postJson($url, $payload)->assertConflict();
        $payload['expected_current_baseline_id'] = $new['id'];
        $payload['expected_generation'] = $this->generation;
        $this->postJson($url, $payload)->assertConflict();
        $this->assertSame(67, $this->progress($old['id'])['accepted_weight']);
    }

    public function test_cross_criterion_carry_rejected(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        $d = $this->accept($this->evidence());
        $items = $this->scope();
        $items[1]['carry_decision_id'] = $d['id'];
        $this->expectException(ConflictHttpException::class);
        $this->baseline($items);
    }

    public function test_changed_policy_or_hash_requires_new_human_decision(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        $l = $this->evidence();
        $d = $this->accept($l);
        $q = app(QaObservationService::class)->record($this->actor(), $this->p, ['criterion_version_id' => $this->criteria[0]['version_id'], 'link_id' => $l['id'], 'test_key' => 'test-a', 'result' => 'passed'], 1, $this->generation);
        $this->generation = $q['generation'];
        $this->assertSame(0, $this->progress()['accepted_weight']);
        $this->accept($l);
        $this->assertSame(67, $this->progress()['accepted_weight']);
        $p = app(DodPolicyService::class)->approve($this->actor(), $this->p, ['fictional' => true, 'mandatory_qa' => true, 'human_acceptance' => true], $this->generation);
        $this->policyId = $p['id'];
        $this->generation = $p['generation'];
        $this->baseline($this->scope());
        $this->assertSame(0, $this->progress()['accepted_weight']);
    }

    public function test_immutable_and_full_scoped_lineage(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        $this->accept($this->evidence());
        $this->baseline($this->scope());
        $this->assertSame(0, DB::table('baselines')->count());
        app(TenantContext::class)->run($this->tenant, function () {
            foreach (['baselines', 'baseline_items', 'human_acceptance_decisions', 'baseline_progress_snapshots'] as $t) {
                try {
                    DB::transaction(fn () => DB::table($t)->update(['id' => (string) Str::uuid()]));
                    $this->fail('Immutable update accepted');
                } catch (QueryException $e) {
                    $this->assertSame('42501', $e->errorInfo[0]);
                }
            }
            $d = (array) DB::table('human_acceptance_decisions')->first();
            $d['id'] = (string) Str::uuid();
            $d['criterion_version_id'] = $this->criteria[1]['version_id'];
            try {
                DB::transaction(fn () => DB::table('human_acceptance_decisions')->insert($d));
                $this->fail('Forged decision accepted');
            } catch (QueryException $e) {
                $this->assertSame('23503', $e->errorInfo[0]);
            }
        });
    }

    public function test_changed_criterion_version_immediately_requires_new_human_decision(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        $d = $this->accept($this->evidence());
        $data = $this->requirement();
        $data['criteria'][] = ['key' => 'C', 'text' => 'Synthetic C', 'required_test_keys' => ['test-c']];
        $data['criteria'][0]['text'] = 'Changed synthetic acceptance';
        app(RequirementService::class)->updateDraft($this->actor(), $this->p, $this->draft['id'], $data, 1);
        $v = app(RequirementService::class)->approve($this->actor(), $this->p, $this->draft['id'], 2, $this->generation, 'Synthetic replacement');
        $this->generation = $v['generation'];
        $this->assertSame(0, $this->progress()['accepted_weight']);
        $this->assertContains('criterion_replaced', $this->progress()['coverage_reason_codes']);
        $this->criteria = $v['criteria'];
        $this->withToken($this->token);
        $this->postJson('/api/v1/projects/'.$this->p.'/baselines', ['items' => $this->scope([67, 20, 13], $d['id']), 'policy_id' => $this->policyId, 'expected_current_baseline_id' => $this->baselineId, 'expected_generation' => $this->generation, 'reason' => 'Synthetic'])->assertConflict();
        $this->baseline($this->scope());
        $this->assertSame(0, $this->progress()['accepted_weight']);
    }

    public function test_strict_transport_and_independent_permissions(): void
    {
        $this->inputs();
        $this->withToken($this->token);
        $url = '/api/v1/projects/'.$this->p.'/baselines';
        $d = ['items' => $this->scope(), 'policy_id' => $this->policyId, 'expected_current_baseline_id' => null, 'expected_generation' => 2, 'reason' => 'Synthetic'];
        foreach (['expected_generation' => 2.0, 'tenant_id' => $this->tenant, 'reason' => "Synthetic\0"] as $k => $v) {
            $raw = $d;
            $raw[$k] = $v;
            $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], json_encode($raw, JSON_PRESERVE_ZERO_FRACTION))->assertUnprocessable();
        }
        $raw = $d;
        $raw['items'][0]['weight'] = 1.0;
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], json_encode($raw, JSON_PRESERVE_ZERO_FRACTION))->assertUnprocessable();
        $raw = json_encode($d);
        $raw = substr($raw, 0, -1).',"reason":"Synthetic"}';
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], $raw)->assertUnprocessable();
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], str_repeat(' ', 65537))->assertStatus(413);
        $this->baseline($this->scope());
        $l = $this->evidence();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'acceptance.review')->update(['revoked_at' => now()]));
        $this->postJson('/api/v1/projects/'.$this->p.'/acceptance-decisions', ['baseline_id' => $this->baselineId, 'criterion_version_id' => $this->criteria[0]['version_id'], 'reviewed_link_ids' => [$l['id']], 'expected_generation' => $this->generation, 'reason' => 'Synthetic'])->assertForbidden();
        $this->getJson('/api/v1/projects/'.$this->p.'/progress?as_of=2020-01-01')->assertUnprocessable();
    }

    public function test_original_author_revocation_and_replacement_grant_require_new_acceptance(): void
    {
        $this->inputs();
        $old = $this->baseline($this->scope());
        $l = $this->evidence();
        $d = $this->accept($l);
        $this->baseline($this->scope([67, 20, 33], $d['id']));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'acceptance.review')->update(['revoked_at' => now()]));
        $this->assertSame(0, $this->progress()['accepted_weight']);
        $this->assertContains('acceptance_author_access_revoked', $this->progress()['coverage_reason_codes']);
        $this->assertSame('unavailable', $this->progress()['status']);
        $this->assertNull($this->progress()['percentage_basis_points']);
        $this->grant('acceptance.review', $this->p);
        $this->assertSame(0, $this->progress()['accepted_weight']);
        $this->assertSame(67, $this->progress($old['id'])['accepted_weight']);
        $this->accept($l);
        $this->assertSame(67, $this->progress()['accepted_weight']);
    }

    public function test_two_sessions_baseline_correction_acceptance_read_and_reopen_are_serialized(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        $l = $this->evidence();
        $d = $this->accept($l);
        $marker = DB::scalar("select shobj_description(oid,'pg_database') from pg_database where datname=current_database()");
        $this->assertStringStartsWith('monitoring-ai-local-v1:', $marker);
        DB::commit(); // Retain one immutable synthetic fixture in the guarded isolated test database.
        config(['database.connections.baseline_contender' => config('database.connections.pgsql')]);
        $other = DB::connection('baseline_contender');
        $this->assertSame('monitoring_ai_test', $other->scalar('select current_database()'));
        $this->assertSame('monitoring_ai_app', $other->scalar('select current_user'));
        $other->beginTransaction();
        try {
            $other->select("select set_config('app.tenant_id',?,true)", [$this->tenant]);
            $other->table('projects')->where('id', $this->p)->lockForUpdate()->first();
            foreach (['baseline', 'correction', 'acceptance', 'read'] as $op) {
                try {
                    app(TenantContext::class)->run($this->tenant, function () use ($op, $l) {
                        DB::statement("SET LOCAL lock_timeout='100ms'");
                        match ($op) {
                            'baseline' => $this->baseline($this->scope()),'correction' => app(IngestionService::class)->accept($this->actor(), $this->connector, $this->envelope),'acceptance' => $this->accept($l),'read' => $this->progress()
                        };
                    });
                    $this->fail('Project lock bypassed');
                } catch (QueryException $e) {
                    $this->assertSame('55P03', $e->errorInfo[0]);
                }
            }
        } finally {
            $other->rollBack();
        }
        $other->beginTransaction();
        try {
            $other->select("select set_config('app.tenant_id',?,true)", [$this->tenant]);
            $other->table('access_grants')->where('user_id', $this->user)->where('purpose', 'acceptance.review')->lockForUpdate()->first();
            try {
                app(TenantContext::class)->run($this->tenant, function () {
                    DB::statement("SET LOCAL lock_timeout='100ms'");
                    $this->progress();
                });
                $this->fail('Concurrent author grant mutation was not fenced');
            } catch (QueryException $e) {
                $this->assertSame('55P03', $e->errorInfo[0]);
            }
        } finally {
            $other->rollBack();
        }
        $e = $this->envelope;
        $e['source_version'] = 'v2';
        $e['source_updated_at'] = '2026-10-05T12:01:00Z';
        app(IngestionService::class)->accept($this->actor(), $this->connector, $e);
        try {
            $this->accept($l);
            $this->fail('Corrected source accepted');
        } catch (ConflictHttpException) {
            $this->assertSame(0, $this->progress()['accepted_weight']);
        }
        $r = app(BaselineService::class)->close($this->actor(), $this->p, $this->generation, 'Synthetic close');
        $this->generation = $r['generation'];
        $closed = $this->baselineId;
        $closedGeneration = $this->generation;
        $other->beginTransaction();
        try {
            $other->select("select set_config('app.tenant_id',?,true)", [$this->tenant]);
            $other->table('projects')->where('id', $this->p)->lockForUpdate()->first();
            try {
                app(TenantContext::class)->run($this->tenant, function () {
                    DB::statement("SET LOCAL lock_timeout='100ms'");
                    $this->baseline($this->scope(), true);
                });
                $this->fail('Reopen lock bypassed');
            } catch (QueryException $e) {
                $this->assertSame('55P03', $e->errorInfo[0]);
            }
        } finally {
            $other->rollBack();
            DB::purge('baseline_contender');
        }
        $this->baseline($this->scope(), true);
        try {
            app(BaselineService::class)->approve($this->actor(), $this->p, $this->scope(), $this->policyId, $closed, $closedGeneration, 'Synthetic competing reopen', true);
            $this->fail('Repeated reopen accepted');
        } catch (ConflictHttpException) {
            $this->assertNotSame($closed, $this->baselineId);
        }
    }
}
