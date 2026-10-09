<?php

namespace Tests\Feature;

use App\Domain\Ba\BaAuthority;
use App\Domain\Ba\BaPins;
use App\Domain\Lifecycle\TaskService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\BaTestCase;

class BaAuthorityTest extends BaTestCase
{
    public function test_original_proof_ceiling_admits_both_trusted_current_and_source_reservations(): void
    {
        $this->baInputs();
        $this->baRole();
        $proof = $this->baRun(fn ($p) => $p['current']['proof'], purposes: ['ba.answer.record']);
        $requests = [];
        for ($i = 0; $i < 2300; $i++) {
            $requests[] = ['key' => 'original:'.$i, 'actor_id' => $this->user, 'purposes' => $proof['purposes'], 'required' => false, 'pinned_proof' => $proof];
        }
        $source = ['key' => 'sources', 'actor_id' => $this->user, 'purposes' => ['ba.source.read'], 'required' => true, 'source_ids' => [$this->baEnvelope()['source_instance_id']]];
        $count = $this->baRun(function ($proofs) {
            $this->assertTrue($proofs['current']['valid']);
            $this->assertTrue($proofs['sources']['valid']);
            $this->assertSame(2302, count(array_filter($proofs, fn ($p) => $p['valid'])));
            app(BaAuthority::class)->requireDeliveryLocked(array_keys($proofs));

            return count($proofs);
        }, [...$requests, $source]);
        $this->assertSame(2302, $count);
        $extra = ['key' => 'original:2300', 'actor_id' => $this->user, 'purposes' => $proof['purposes'], 'required' => false, 'pinned_proof' => $proof];
        $this->assertBaDenied(fn () => $this->baRun(fn () => 'over-limit', [...$requests, $extra, $source]));
        foreach ([['required' => false], ['purposes' => ['ba.read']], ['pinned_proof' => $proof]] as $spoof) {
            $this->assertBaDenied(fn () => $this->baRun(fn () => 'spoofed reservation', [...$requests, array_replace($source, $spoof)]));
        }
    }

    public function test_unreserved_original_proof_overflow_denies_without_trusted_current_slot(): void
    {
        $this->baInputs();
        $proof = $this->baRun(fn ($p) => $p['current']['proof']);
        $requests = [];
        for ($i = 0; $i < 2301; $i++) {
            $requests[] = ['key' => 'unreserved:'.$i, 'actor_id' => $this->user, 'purposes' => $proof['purposes'], 'required' => false, 'pinned_proof' => $proof];
        }
        $this->assertBaDenied(fn () => app(TenantContext::class)->run($this->tenant, fn () => app(BaAuthority::class)->captureBatch($this->actor(), $this->p, $requests)));
    }

    public function test_optional_proof_promoted_for_delivery_must_survive_final_clock(): void
    {
        $this->baInputs();
        $role = $this->baRole();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->where('id', $role)->update(['valid_to' => DB::raw("clock_timestamp()+interval '500 milliseconds'"), 'revision' => 2]));
        $old = ['key' => 'delivered', 'actor_id' => $this->user, 'purposes' => ['ba.answer.record'], 'required' => false];
        $this->assertBaDenied(fn () => $this->baRun(function ($proofs) {
            $this->assertTrue($proofs['delivered']['valid']);
            app(BaAuthority::class)->requireDeliveryLocked(['delivered']);
            DB::select('select pg_sleep(0.55)');

            return 'historical delivery';
        }, [$old]));
        $this->assertNull(DB::scalar("select nullif(current_setting('app.ba_required_keys',true),'')"));
    }

    public function test_optional_distinct_historical_actor_expiry_does_not_abort_independent_fresh_write(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->seedBaRequest();
        $answer = $this->seedBaAnswer($request);
        $review = $this->seedBaReview($request, $answer);
        $proof = app(TenantContext::class)->run($this->tenant, function () use ($review) {
            $proof = json_decode(DB::table('ba_reviews')->where('id', $review['review_id'])->value('authority_proof'), true);
            $end = DB::scalar("select clock_timestamp()+interval '500 milliseconds'");
            DB::table('role_assignments')->where('id', $proof['roles'][0]['id'])->update(['valid_to' => $end]);
            $proof['roles'][0]['valid_to'] = $end;

            return $proof;
        });
        $old = ['key' => 'old-review', 'actor_id' => $proof['actor_id'], 'purposes' => $proof['purposes'], 'required' => false, 'pinned_proof' => $proof];
        $result = $this->baRun(function ($proofs) {
            $this->assertTrue($proofs['old-review']['valid']);
            DB::select('select pg_sleep(0.55)');
            DB::table('audit_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'actor_id' => $this->user, 'project_id' => $this->p, 'action' => 'ba.fresh', 'resource_type' => 'ba_receipts', 'resource_id' => (string) Str::uuid(), 'revision' => 1, 'reason_code' => 'approved_change', 'correlation_id' => (string) Str::uuid(), 'occurred_at' => DB::raw('clock_timestamp()')]);

            return 'fresh';
        }, [$old]);
        $this->assertSame('fresh', $result);
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(1, DB::table('audit_events')->where('action', 'ba.fresh')->count()));
        $old['required'] = true;
        $this->assertBaDenied(fn () => $this->baRun(fn () => 'historical delivery', [$old]));
    }

    public function test_collector_emits_two_exact_event_time_slots_and_distinct_named_assignee(): void
    {
        $this->baInputs();
        $this->baRole();
        $assignee = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($assignee) {
            DB::table('employees')->insert(['id' => $assignee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic task assignee']);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $assignee, 'valid_from' => now()->subDay()]);
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $assignee, 'role' => 'developer', 'valid_from' => now()->subDay()]);
        });
        $task = $this->baTask(assignee: $assignee);
        $request = $this->seedBaRequest(task: $task);
        $this->seedBaAnswer($request);
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, [$request['clarification_id']]);
        $this->assertSame('complete', $graph['coverage']);
        $responsibility = array_values(array_filter($graph['requests'], fn ($r) => isset($r['responsibility_refs'])));
        $this->assertCount(2, $responsibility);
        $this->assertContains($assignee, $graph['named_refs']);
        foreach ($responsibility as $proofRequest) {
            $this->assertSame($task['version_id'], $proofRequest['responsibility_refs'][0]['task_version_id']);
            $this->assertSame($request['body']['requested_at'], $proofRequest['responsibility_refs'][0]['occurred_at']);
        }
        $results = $this->baRun(fn ($p) => $p, $graph['requests']);
        foreach ($responsibility as $proofRequest) {
            $captured = array_values($results[$proofRequest['key']]['responsibilities']);
            $this->assertSame('known', $captured[0]['attribution']);
            $this->assertSame($assignee, $captured[0]['employee_id']);
        }
    }

    public function test_request_action_requires_ba_role_even_when_scoped_grant_exists(): void
    {
        $this->baInputs();
        $this->assertBaDenied(fn () => $this->baRun(fn ($proofs) => $proofs, purposes: ['ba.request.manage']));
    }

    public function test_ba_request_proof_pins_actual_role_assignment_identity(): void
    {
        $this->baInputs();
        $role = $this->baRole();
        $proofs = $this->baRun(fn ($proofs) => $proofs, purposes: ['ba.request.manage']);
        $this->assertTrue($proofs['current']['valid']);
        $this->assertSame($role, $proofs['current']['proof']['roles'][0]['id']);
        $this->assertSame('ba', $proofs['current']['proof']['roles'][0]['role']);
        $this->assertSame('ba-authority-v1', $proofs['current']['proof']['version']);
        $this->assertInstanceOf(BaAuthority::class, app(BaAuthority::class));
    }

    public function test_pm_role_cannot_record_answers_and_ba_role_cannot_confirm(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->assertBaDenied(fn () => $this->baRun(fn ($p) => $p, purposes: ['ba.decision.confirm']));
        $this->baRole('pm');
        $this->assertTrue($this->baRun(fn ($p) => $p['current']['valid'], purposes: ['ba.decision.confirm']));
    }

    public function test_original_role_removal_or_replacement_does_not_repair_saved_proof(): void
    {
        $this->baInputs();
        $role = $this->baRole();
        $saved = $this->baRun(fn ($p) => $p['current']['proof'], purposes: ['ba.answer.record']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->where('id', $role)->update(['valid_to' => now()->subHour(), 'revision' => 2]));
        $this->baRole(replacement: true);
        $request = ['key' => 'original', 'actor_id' => $this->user, 'purposes' => ['ba.answer.record'], 'required' => false, 'pinned_proof' => $saved];
        $proofs = $this->baRun(fn ($p) => $p, [$request]);
        $this->assertFalse($proofs['original']['valid']);
        $this->assertSame('access_revoked', $proofs['original']['reason_code']);
    }

    public function test_saved_variants_for_same_actor_are_checked_independently(): void
    {
        $this->baInputs();
        $this->baRole();
        $a = $this->baRun(fn ($p) => $p['current']['proof'], purposes: ['ba.request.manage']);
        $b = $this->baRun(fn ($p) => $p['current']['proof'], purposes: ['ba.answer.record']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $a['grants'][0]['id'])->update(['revoked_at' => now(), 'revision' => 2]));
        $requests = [];
        foreach (['request' => $a, 'answer' => $b] as $key => $proof) {
            $requests[] = ['key' => $key, 'actor_id' => $this->user, 'purposes' => $proof['purposes'], 'required' => false, 'pinned_proof' => $proof];
        }
        $results = $this->baRun(fn ($p) => $p, $requests);
        $this->assertFalse($results['request']['valid']);
        $this->assertTrue($results['answer']['valid']);
    }

    public function test_more_than_one_hundred_keyed_proofs_for_one_actor_remain_independent(): void
    {
        $this->baInputs();
        $this->baRole();
        $proof = $this->baRun(fn ($p) => $p['current']['proof'], purposes: ['ba.answer.record']);
        $requests = [];
        for ($i = 0; $i < 120; $i++) {
            $requests[] = ['key' => 'answer:'.$i, 'actor_id' => $this->user, 'purposes' => $proof['purposes'], 'required' => false, 'pinned_proof' => $proof];
        }
        $results = $this->baRun(fn ($p) => $p, $requests);
        $this->assertCount(121, $results);
        $this->assertSame(121, count(array_filter($results, fn ($p) => $p['valid'])));
    }

    public function test_batch_authority_query_count_does_not_grow_per_keyed_proof(): void
    {
        $this->baInputs();
        $this->baRole();
        $proof = $this->baRun(fn ($p) => $p['current']['proof'], purposes: ['ba.answer.record']);
        $requests = [];
        for ($i = 0; $i < 120; $i++) {
            $requests[] = ['key' => 'answer:'.$i, 'actor_id' => $this->user, 'purposes' => $proof['purposes'], 'required' => false, 'pinned_proof' => $proof];
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->baRun(fn ($p) => $p, [$requests[0]]);
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->baRun(fn ($p) => $p, $requests);
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($small, $large, 'Authority must batch categories rather than query each proof.');
        $this->assertLessThan(40, $large);
    }

    public function test_actual_role_expiry_at_final_database_clock_rolls_back_callback_write(): void
    {
        $this->baInputs();
        $role = $this->baRole();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->where('id', $role)->update(['valid_to' => DB::raw("clock_timestamp()+interval '300 milliseconds'"), 'revision' => 2]));
        $this->assertBaDenied(fn () => $this->baRun(function () {
            DB::select('select pg_sleep(0.35)');
            DB::table('audit_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'actor_id' => $this->user, 'project_id' => $this->p, 'action' => 'ba.test', 'resource_type' => 'ba_receipts', 'resource_id' => (string) Str::uuid(), 'revision' => 1, 'reason_code' => 'approved_change', 'correlation_id' => (string) Str::uuid(), 'occurred_at' => DB::raw('clock_timestamp()')]);
        }, purposes: ['ba.answer.record']));
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(0, DB::table('audit_events')->where('action', 'ba.test')->count()));
    }

    public function test_optional_actor_overflow_is_unknown_and_current_actor_remains_reserved(): void
    {
        $this->baInputs();
        $requests = [];
        for ($i = 0; $i < 101; $i++) {
            $requests[] = ['key' => 'old:'.$i, 'actor_id' => (string) Str::uuid(), 'purposes' => ['ba.read'], 'required' => false];
        }
        $results = $this->baRun(fn ($p) => $p, $requests);
        $this->assertTrue($results['current']['valid']);
        $this->assertSame('coverage_limit', $results['old:0']['reason_code']);
    }

    public function test_final_clock_checks_role_and_grant_expiry(): void
    {
        $this->baInputs();
        $this->baRole();
        $proofs = $this->baRun(fn ($p) => $p, purposes: ['ba.answer.record']);
        $proofs['current']['proof']['roles'][0]['valid_to'] = '2026-10-07T00:00:00.000000Z';
        $this->assertBaDenied(fn () => app(BaAuthority::class)->assertEffective($proofs, '2026-10-07T00:00:00.000000Z'));
    }

    public function test_event_time_responsibility_uses_exact_task_successor_boundary(): void
    {
        $this->baInputs();
        $this->baRole();
        $task = $this->baTask();
        $row = app(TenantContext::class)->run($this->tenant, fn () => DB::table('task_versions')->find($task['version_id']));
        $data = ['title' => 'Revised', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic only', 'remaining_effort' => null, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => [['criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id']]]];
        $new = app(TaskService::class)->revise($this->actor(), $this->p, $task['id'], $data, 1, $this->generation);
        $boundary = app(TenantContext::class)->run($this->tenant, fn () => DB::table('task_versions')->where('id', $new['version_id'])->value('effective_at'));
        $refs = [['task_version_id' => $task['version_id'], 'occurred_at' => $row->effective_at], ['task_version_id' => $task['version_id'], 'occurred_at' => $boundary]];
        $request = ['key' => 'responsibility', 'actor_id' => $this->user, 'purposes' => ['ba.read'], 'required' => true, 'responsibility_refs' => $refs];
        $proofs = $this->baRun(fn ($p) => $p, [$request]);
        $values = array_values($proofs['responsibility']['responsibilities']);
        $this->assertSame('known', $values[0]['attribution']);
        $this->assertSame($this->employee, $values[0]['employee_id']);
        $this->assertSame('unknown', $values[1]['attribution']);
        $this->assertNull($values[1]['employee_id']);
    }

    public function test_callback_cannot_reopen_authority_phase_and_cleanup_survives_exception(): void
    {
        $this->baInputs();
        try {
            $this->baRun(fn () => app(BaAuthority::class)->captureBatch($this->actor(), $this->p, []));
            $this->fail('Callback reauthorization accepted.');
        } catch (LogicException) {
            $this->assertNull(DB::scalar("select nullif(current_setting('app.ba_authority_closed',true),'')"));
        }
        $this->assertTrue($this->baRun(fn ($p) => $p['current']['valid']));
    }

    public function test_sql_callback_failure_keeps_original_rejection_and_rolls_back_closed_phase(): void
    {
        $this->baInputs();
        $this->assertBaSqlState('22012', fn () => $this->baRun(fn () => DB::select('select 1/0')));
        $this->assertNull(DB::scalar("select nullif(current_setting('app.ba_authority_closed',true),'')"));
        $this->assertTrue($this->baRun(fn ($p) => $p['current']['valid']));
    }
}
