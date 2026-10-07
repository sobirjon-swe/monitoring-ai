<?php

namespace Tests\Feature;

use App\Domain\Lifecycle\TaskService;
use App\Domain\Qa\QaAuthority;
use App\Domain\Qa\QaChangeWriter;
use App\Domain\Qa\QaContract;
use App\Domain\Qa\QaCoordination;
use App\Domain\Qa\QaPins;
use App\Domain\Qa\QaResponsibility;
use App\Support\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\QaTestCase;

class QaAuthorityTest extends QaTestCase
{
    public function test_tenant_only_grant_never_authorizes_project_qa(): void
    {
        $this->assertDenied(fn () => $this->captureTenantOnlyQaGrant());
    }

    public function test_explicit_owner_is_prelocked_without_user_or_task_assignee_inference(): void
    {
        $this->qaInputs();
        $task = $this->qaTask();
        $owner = $this->ownerFixture();
        $this->assertNotSame($this->employee, $owner['employee_id']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $results = $this->qaRun(fn ($r) => $r, [['key' => 'handoff-owner', 'actor_id' => $this->user, 'purposes' => ['qa.handoff.manage'], 'required' => true, 'owner_refs' => [$owner['employee_id']], 'responsibility_refs' => [['task_version_id' => $task['version_id']]]]]);
        foreach (DB::getQueryLog() as $query) {
            if (str_starts_with($query['query'], 'select * from "employees"')) {
                $expected = [$this->employee, $owner['employee_id']];
                sort($expected, SORT_STRING);
                $this->assertSame($expected, $query['bindings']);
                $this->assertStringContainsString('for share', $query['query']);
            }
            if (str_starts_with($query['query'], 'select * from "users"') || str_starts_with($query['query'], 'select * from "access_grants"')) {
                $this->assertNotContains($owner['employee_id'], $query['bindings']);
            }
        }
        DB::disableQueryLog();
        $captured = $results['handoff-owner']['owners'][$owner['employee_id']];
        $this->assertTrue($results['handoff-owner']['valid']);
        $this->assertSame($owner['employee_id'], $captured['employee_id']);
        $this->assertSame($owner['memberships'][0], $captured['membership']['id']);
        $this->assertSame(1, $captured['membership']['revision']);
        $this->assertSame($owner['employee_id'], $captured['membership']['employee_id']);
        $this->assertArrayHasKey('valid_from', $captured['membership']);
        $this->assertNull($captured['membership']['valid_to']);
        $this->assertNull($captured['uniqueness_valid_until']);
        $this->assertSame($this->employee, $results['handoff-owner']['proof']['employee_id']);
        $this->assertSame(0, app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('employee_id', $owner['employee_id'])->count()));
    }

    public function test_owner_missing_wrong_scope_and_nonunique_effective_membership_never_validate(): void
    {
        $this->qaInputs();
        $otherProject = $this->project();
        $otherTenant = (string) Str::uuid();
        $foreign = (string) Str::uuid();
        app(TenantContext::class)->run($otherTenant, function () use ($otherTenant, $foreign) {
            DB::table('tenants')->insert(['id' => $otherTenant, 'name' => 'Synthetic other owner tenant']);
            DB::table('employees')->insert(['id' => $foreign, 'tenant_id' => $otherTenant, 'display_name' => 'Synthetic other owner']);
        });
        $owners = [(string) Str::uuid(), $foreign, $this->user,
            $this->ownerFixture($otherProject)['employee_id'],
            $this->ownerFixture(null, ['valid_from' => now()->subDays(2), 'valid_to' => now()->subDay()])['employee_id'],
            $this->ownerFixture(null, ['valid_from' => now()->addDay()])['employee_id'],
            $this->ownerFixture(null, [], 2)['employee_id']];
        foreach ($owners as $owner) {
            $request = ['key' => 'owner', 'actor_id' => $this->user, 'purposes' => ['qa.handoff.manage'], 'required' => true, 'owner_refs' => [$owner]];
            $this->assertDenied(fn () => $this->qaRun(fn () => $this->fail('Invalid required owner reached callback.'), [$request]));
            $request['required'] = false;
            $results = $this->qaRun(fn ($r) => $r, [$request]);
            $this->assertTrue($results['current']['valid']);
            $this->assertFalse($results['owner']['valid']);
            $this->assertSame('access_revoked', $results['owner']['reason_code']);
            $this->assertArrayNotHasKey('owners', $results['owner']);
        }
    }

    public function test_owner_reference_union_is_bounded_and_membership_overflow_has_no_favourable_subset(): void
    {
        $this->qaInputs();
        $owners = [];
        for ($i = 0; $i < 100; $i++) {
            $owners[] = $this->ownerFixture()['employee_id'];
        }
        $request = ['key' => 'owners', 'actor_id' => $this->user, 'purposes' => ['qa.handoff.manage'], 'required' => true, 'owner_refs' => array_reverse($owners)];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $captured = $this->qaRun(fn ($r) => $r['owners'], [$request]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertTrue($captured['valid']);
        $this->assertCount(100, $captured['owners']);
        $this->assertLessThanOrEqual(30, $queries, 'Owner union is batched, never one query per owner.');
        foreach ([$owners[0], ['employee_id' => $owners[0]], [$owners[0], $owners[0]], [42], ['not-a-uuid'], [...$owners, (string) Str::uuid()]] as $bad) {
            $request['owner_refs'] = $bad;
            $this->assertDenied(fn () => $this->qaRun(fn () => null, [$request]));
        }
        $request['owner_refs'] = $owners;
        $extra = ['key' => 'extra', 'actor_id' => $this->user, 'purposes' => ['qa.read'], 'required' => false, 'owner_refs' => [(string) Str::uuid()]];
        $this->assertDenied(fn () => $this->qaRun(fn () => null, [$request, $extra]));
        $overflow = $this->ownerFixture(null, [], 101)['employee_id'];
        $request['owner_refs'] = [$overflow];
        $request['required'] = false;
        $result = $this->qaRun(fn ($r) => $r['owners'], [$request]);
        $this->assertSame(['valid' => false, 'reason_code' => 'coverage_limit'], $result);
        $request['required'] = true;
        $this->assertDenied(fn () => $this->qaRun(fn () => null, [$request]));
    }

    public function test_owner_membership_natural_expiry_rolls_back_writes_at_final_database_clock(): void
    {
        $this->qaInputs();
        $owner = $this->ownerFixture(null, ['valid_from' => now()->subDay(), 'valid_to' => DB::raw("clock_timestamp()+interval '250 milliseconds'")]);
        $tables = ['qa_receipts', 'qa_source_heads', 'qa_build_versions', 'qa_changes', 'qa_outbox', 'audit_events', 'qa_project_state'];
        $before = app(TenantContext::class)->run($this->tenant, fn () => array_map(fn ($table) => DB::table($table)->count(), $tables));
        $worked = false;
        $this->assertDenied(function () use ($owner, &$worked) {
            return $this->qaRun(function ($r) use ($owner, &$worked) {
                $worked = true;
                $this->assertSame($owner['memberships'][0], $r['owner']['owners'][$owner['employee_id']]['membership']['id']);
                $body = ['logical_key' => 'owner-expiry', 'revision' => 1, 'title' => 'Synthetic owner expiry', 'content_hash' => str_repeat('a', 64)];
                $receipt = $this->receipt($r['current']['proof'], 'build', $body, 'owner-expiry');
                DB::table('qa_build_versions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'receipt_id' => $receipt['receipt_id']] + $body);
                app(QaChangeWriter::class)->appendLocked($this->actor(), $this->p, 'source_recorded', ['receipt_id' => $receipt['receipt_id']], $receipt['input_hash'], $r['current']['proof']);
                DB::select('select pg_sleep(0.35)');
            }, [['key' => 'owner', 'actor_id' => $this->user, 'purposes' => ['qa.handoff.manage'], 'required' => true, 'owner_refs' => [$owner['employee_id']]]]);
        });
        $this->assertTrue($worked, 'Owner was initially effective; denial must happen after work at final clock.');
        $this->assertSame($before, app(TenantContext::class)->run($this->tenant, fn () => array_map(fn ($table) => DB::table($table)->count(), $tables)));
    }

    public function test_future_owner_membership_activation_during_authority_locks_never_returns_valid_owner(): void
    {
        $this->qaInputs();
        $owner = $this->ownerFixture();
        $future = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->insert(['id' => $future, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $owner['employee_id'], 'valid_from' => now()->addDay()]));
        foreach ([true, false] as $required) {
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('id', $future)->update(['valid_from' => DB::raw("clock_timestamp()+interval '250 milliseconds'")]));
            $connection = DB::connection();
            $originalDispatcher = $connection->getEventDispatcher();
            $dispatcher = clone $originalDispatcher;
            $connection->setEventDispatcher($dispatcher);
            $waited = false;
            $dispatcher->listen(QueryExecuted::class, function ($query) use (&$waited) {
                if (! $waited && str_starts_with($query->sql, 'select * from "project_memberships"') && str_contains($query->sql, 'for share')) {
                    $waited = true;
                    // Real database time passes inside the authority lock phase, after its temporal probe.
                    DB::select('select pg_sleep(0.35)');
                }
            });
            try {
                $request = ['key' => 'owner', 'actor_id' => $this->user, 'purposes' => ['qa.handoff.manage'], 'required' => $required, 'owner_refs' => [$owner['employee_id']]];
                if ($required) {
                    $this->assertDenied(fn () => $this->qaRun(fn () => $this->fail('Ambiguous required owner reached callback.'), [$request]));
                } else {
                    $result = $this->qaRun(function ($r) use ($owner) {
                        $this->assertSame(2, DB::table('project_memberships')->where('employee_id', $owner['employee_id'])->whereRaw('valid_from<=clock_timestamp()')->count());

                        return $r['owner'];
                    }, [$request]);
                    $this->assertSame(['valid' => false, 'reason_code' => 'access_revoked'], $result);
                }
                $this->assertTrue($waited, 'Delay must follow the candidate probe in the actual lock phase.');
            } finally {
                $connection->setEventDispatcher($originalDispatcher);
            }
        }
    }

    public function test_future_owner_membership_activation_rolls_back_callback_writes_without_history_loading(): void
    {
        $this->qaInputs();
        $owner = $this->ownerFixture();
        app(TenantContext::class)->run($this->tenant, function () use ($owner) {
            $rows = [];
            for ($i = 0; $i < 300; $i++) {
                $rows[] = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $owner['employee_id'], 'valid_from' => now()->addDays($i + 1)];
            }
            DB::table('project_memberships')->insert($rows);
        });
        $future = (string) Str::uuid();
        $tables = ['qa_receipts', 'qa_source_heads', 'qa_build_versions', 'qa_changes', 'qa_outbox', 'audit_events', 'qa_project_state'];
        $before = app(TenantContext::class)->run($this->tenant, fn () => array_map(fn ($table) => DB::table($table)->count(), $tables));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->insert(['id' => $future, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $owner['employee_id'], 'valid_from' => DB::raw("clock_timestamp()+interval '250 milliseconds'")]));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $worked = false;
        $membershipLockSeen = false;
        try {
            $this->assertDenied(function () use ($owner, &$worked) {
                return $this->qaRun(function ($r) use ($owner, &$worked) {
                    $worked = true;
                    $this->assertSame($owner['memberships'][0], $r['owner']['owners'][$owner['employee_id']]['membership']['id']);
                    $this->assertNotNull($r['owner']['owners'][$owner['employee_id']]['uniqueness_valid_until']);
                    $body = ['logical_key' => 'owner-future', 'revision' => 1, 'title' => 'Synthetic future owner', 'content_hash' => str_repeat('a', 64)];
                    $receipt = $this->receipt($r['current']['proof'], 'build', $body, 'owner-future');
                    DB::table('qa_build_versions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'receipt_id' => $receipt['receipt_id']] + $body);
                    app(QaChangeWriter::class)->appendLocked($this->actor(), $this->p, 'source_recorded', ['receipt_id' => $receipt['receipt_id']], $receipt['input_hash'], $r['current']['proof']);
                    DB::select('select pg_sleep(0.35)');
                    $this->assertSame(2, DB::table('project_memberships')->where('employee_id', $owner['employee_id'])->whereRaw('valid_from<=clock_timestamp()')->count());
                }, [['key' => 'owner', 'actor_id' => $this->user, 'purposes' => ['qa.handoff.manage'], 'required' => true, 'owner_refs' => [$owner['employee_id']]]]);
            });
            $this->assertTrue($worked, 'Owner starts unique; ambiguity must deny after callback writes.');
            foreach (DB::getQueryLog() as $query) {
                if (str_starts_with($query['query'], 'select * from "project_memberships"')) {
                    $membershipLockSeen = true;
                    $this->assertLessThanOrEqual(4, count($query['bindings']), 'Only caller, active owner and earliest future membership reach sorted SHARE locking.');
                    $this->assertContains($future, $query['bindings']);
                    $this->assertStringContainsString('for share', $query['query']);
                }
            }
            $this->assertTrue($membershipLockSeen);
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame($before, app(TenantContext::class)->run($this->tenant, fn () => array_map(fn ($table) => DB::table($table)->count(), $tables)));
    }

    public function test_department_subjects_capture_current_employee_without_user_or_proof_changes(): void
    {
        $this->qaInputs();
        $department = $this->departmentFixture();
        $subject = $this->departmentSubjectFixture($department);
        $request = ['key' => 'department', 'actor_id' => $this->user, 'purposes' => ['qa.department.read'], 'required' => true];
        $proof = $this->departmentRun($department, fn ($r) => $r['department']['proof'], [$request]);
        $request['pinned_proof'] = $proof;
        $request['department_refs'] = [$subject['employee_id']];
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = $this->departmentRun($department, fn ($r) => $r['department'], [$request]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertTrue($result['valid']);
        $captured = $result['department_subjects'][$subject['employee_id']];
        $this->assertSame('visible', $captured['visibility']);
        $this->assertSame($subject['employee_id'], $captured['employee_id']);
        $this->assertSame($subject['memberships'][0], $captured['membership']['id']);
        $this->assertSame($department, $captured['membership']['department_id']);
        $this->assertSame(1, $captured['membership']['revision']);
        $this->assertNull($captured['uniqueness_valid_until']);
        $original = $proof;
        $current = $result['proof'];
        unset($original['captured_at'], $current['captured_at']);
        $this->assertSame(QaContract::canonical($original), QaContract::canonical($current));
        $positions = [];
        foreach ($queries as $i => $query) {
            foreach (['employees', 'department_memberships', 'access_grants', 'policy_versions'] as $table) {
                if (str_starts_with($query['query'], 'select * from "'.$table.'"')) {
                    $positions[$table] = $i;
                    $this->assertStringContainsString('for share', $query['query']);
                }
            }
            if (str_starts_with($query['query'], 'select * from "employees"')) {
                $expected = [$this->employee, $subject['employee_id']];
                sort($expected, SORT_STRING);
                $this->assertSame($expected, $query['bindings']);
            }
            if (str_starts_with($query['query'], 'select * from "department_memberships"')) {
                $expected = [$proof['department_membership']['id'], $subject['memberships'][0]];
                sort($expected, SORT_STRING);
                $this->assertSame([$department, ...$expected], $query['bindings']);
            }
            if (str_starts_with($query['query'], 'select * from "users"') || str_starts_with($query['query'], 'select * from "access_grants"')) {
                $this->assertNotContains($subject['employee_id'], $query['bindings']);
            }
        }
        $this->assertCount(4, $positions);
        $this->assertLessThan($positions['department_memberships'], $positions['employees']);
        $this->assertLessThan($positions['access_grants'], $positions['department_memberships']);
        $this->assertLessThan($positions['policy_versions'], $positions['access_grants']);
        $this->assertSame(0, app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('employee_id', $subject['employee_id'])->count()));
        $this->assertSame(0, app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('employee_id', $subject['employee_id'])->count()));
    }

    public function test_department_subject_unavailability_is_uniform_for_missing_scope_and_periods(): void
    {
        $this->qaInputs();
        $department = $this->departmentFixture();
        $otherDepartment = $this->departmentFixture();
        $foreignTenant = (string) Str::uuid();
        $foreignEmployee = (string) Str::uuid();
        app(TenantContext::class)->run($foreignTenant, function () use ($foreignTenant, $foreignEmployee) {
            DB::table('tenants')->insert(['id' => $foreignTenant, 'name' => 'Synthetic department other tenant']);
            DB::table('employees')->insert(['id' => $foreignEmployee, 'tenant_id' => $foreignTenant, 'display_name' => 'Synthetic department foreign employee']);
        });
        $subjects = [(string) Str::uuid(), $foreignEmployee, $this->user,
            $this->departmentSubjectFixture($department, [], 0)['employee_id'],
            $this->departmentSubjectFixture($otherDepartment)['employee_id'],
            $this->departmentSubjectFixture($department, ['valid_from' => now()->subDays(2), 'valid_to' => now()->subDay()])['employee_id'],
            $this->departmentSubjectFixture($department, ['valid_from' => now()->addDay()])['employee_id'],
            $this->departmentSubjectFixture($department, [], 101)['employee_id']];
        app(TenantContext::class)->run($this->tenant, function () use ($department, $subjects) {
            $rows = [];
            for ($i = 0; $i < 1200; $i++) {
                $from = now()->subDays(1500 - $i);
                $rows[] = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'employee_id' => $subjects[5], 'valid_from' => $from, 'valid_to' => $from->copy()->addDay()];
            }
            for ($i = 0; $i < 600; $i++) {
                $rows[] = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'employee_id' => $subjects[6], 'valid_from' => now()->addDays($i + 2), 'valid_to' => null];
            }
            DB::table('department_memberships')->insert($rows);
        });
        $request = ['key' => 'department', 'actor_id' => $this->user, 'purposes' => ['qa.department.read'], 'required' => true, 'department_refs' => $subjects];
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $results = $this->departmentRun($department, fn ($r) => $r, [$request]);
            $subjectProbeSeen = $departmentLocksSeen = false;
            foreach (DB::getQueryLog() as $query) {
                if (str_contains($query['query'], "'current'::text AS kind")) {
                    $subjectProbeSeen = true;
                    $this->assertStringContainsString('LIMIT 2', $query['query']);
                    $this->assertStringContainsString('LIMIT 1', $query['query']);
                }
                if (str_starts_with($query['query'], 'select * from "department_memberships"')) {
                    $departmentLocksSeen = true;
                    $this->assertLessThanOrEqual(5, count($query['bindings']), 'Actor + two ambiguous current candidates + one earliest future, not 1900+ rows.');
                    $this->assertStringContainsString('for share', $query['query']);
                }
            }
            $this->assertTrue($subjectProbeSeen);
            $this->assertTrue($departmentLocksSeen);
        } finally {
            DB::disableQueryLog();
        }
        $this->assertTrue($results['department']['valid']);
        foreach ($subjects as $subject) {
            $this->assertSame(['employee_id' => $subject, 'membership' => null, 'visibility' => 'unavailable', 'uniqueness_valid_until' => null], $results['department']['department_subjects'][$subject]);
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'qa.department.read')->where('department_id', $department)->update(['revoked_at' => now()]));
        $this->assertDenied(fn () => $this->departmentRun($department, fn () => $this->fail('Department actor grant remains required.'), [$request]));
    }

    public function test_department_subject_bounds_are_independent_of_actor_and_owner_limits(): void
    {
        $this->qaInputs();
        $department = $this->departmentFixture();
        $subjects = app(TenantContext::class)->run($this->tenant, function () use ($department) {
            $ids = $employees = $memberships = $owners = [];
            for ($i = 0; $i < 2000; $i++) {
                $ids[] = $id = (string) Str::uuid();
                $employees[] = ['id' => $id, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic bounded department subject'];
                $memberships[] = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'employee_id' => $id, 'department_id' => $department, 'valid_from' => now()->subDay()];
                if ($i < 100) {
                    $owners[] = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'employee_id' => $id, 'project_id' => $this->p, 'valid_from' => now()->subDay()];
                }
            }
            DB::table('employees')->insert($employees);
            DB::table('department_memberships')->insert($memberships);
            DB::table('project_memberships')->insert($owners);

            return $ids;
        });
        $request = ['key' => 'current', 'actor_id' => $this->user, 'purposes' => ['qa.read', 'qa.department.read'], 'required' => true, 'owner_refs' => array_slice($subjects, 0, 100), 'department_refs' => array_reverse($subjects)];
        $originalRequests = [];
        for ($i = 0; $i < 100; $i++) {
            $originalRequests[] = ['key' => 'original-'.$i, 'actor_id' => (string) Str::uuid(), 'purposes' => ['qa.read'], 'required' => false];
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $results = app(TenantContext::class)->run($this->tenant, fn () => app(QaAuthority::class)->captureBatch($this->actor(), $this->p, [$request, ...$originalRequests], $department));
            $this->assertLessThanOrEqual(28, count(DB::getQueryLog()), 'Department employees are batched independently of actor/user and owner counts.');
        } finally {
            DB::disableQueryLog();
        }
        $this->assertTrue($results['current']['valid']);
        $this->assertCount(2000, $results['current']['department_subjects']);
        $this->assertCount(100, $results['current']['owners']);
        $this->assertSame('access_revoked', $results['original-99']['reason_code'], 'Exactly100 original actors must not be mistaken for subject overflow.');
        $this->assertCount(2000, array_filter($results['current']['department_subjects'], fn ($subject) => $subject['visibility'] === 'visible'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            foreach ([null, $subjects[0], ['employee' => $subjects[0]], [$subjects[0], $subjects[0]], [42], ['invalid'], [...$subjects, (string) Str::uuid()]] as $bad) {
                $invalid = $request;
                $invalid['department_refs'] = $bad;
                $this->assertDenied(fn () => app(TenantContext::class)->run($this->tenant, fn () => app(QaAuthority::class)->captureBatch($this->actor(), $this->p, [$invalid], $department)));
            }
            foreach (DB::getQueryLog() as $query) {
                $this->assertStringNotContainsString('from "employees"', $query['query'], 'Invalid subject shapes must deny before authority fetch.');
                $this->assertStringNotContainsString('from "department_memberships"', $query['query']);
            }
        } finally {
            DB::disableQueryLog();
        }
        $extra = ['key' => 'extra', 'actor_id' => $this->user, 'purposes' => ['qa.department.read'], 'required' => false, 'department_refs' => [(string) Str::uuid()]];
        $this->assertDenied(fn () => app(TenantContext::class)->run($this->tenant, fn () => app(QaAuthority::class)->captureBatch($this->actor(), $this->p, [$request, $extra], $department)));
        $this->assertDenied(fn () => app(TenantContext::class)->run($this->tenant, fn () => app(QaAuthority::class)->captureBatch($this->actor(), $this->p, [$request])));
        $request['purposes'] = ['qa.read'];
        $this->assertDenied(fn () => app(TenantContext::class)->run($this->tenant, fn () => app(QaAuthority::class)->captureBatch($this->actor(), $this->p, [$request], $department)));
    }

    public function test_department_subject_two_probe_candidates_stay_unavailable_after_lock_delay(): void
    {
        $this->qaInputs();
        $department = $this->departmentFixture();
        $subject = $this->departmentSubjectFixture($department, [], 3);
        sort($subject['memberships'], SORT_STRING);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('department_memberships')->where('id', $subject['memberships'][0])->update(['valid_to' => DB::raw("clock_timestamp()+interval '250 milliseconds'")]));
        $request = ['key' => 'department', 'actor_id' => $this->user, 'purposes' => ['qa.department.read'], 'required' => true, 'department_refs' => [$subject['employee_id']]];
        $result = $this->withDepartmentLockDelay(fn () => $this->departmentRun($department, function ($r) use ($subject) {
            $this->assertSame(2, DB::table('department_memberships')->where('employee_id', $subject['employee_id'])->whereRaw('(valid_to IS NULL OR valid_to>clock_timestamp())')->count());

            return $r['department'];
        }, [$request]));
        $this->assertTrue($result['valid']);
        $this->assertSame(['employee_id' => $subject['employee_id'], 'membership' => null, 'visibility' => 'unavailable', 'uniqueness_valid_until' => null], $result['department_subjects'][$subject['employee_id']]);
    }

    public function test_department_subject_future_activation_during_locks_is_unavailable(): void
    {
        $this->qaInputs();
        $department = $this->departmentFixture();
        $subject = $this->departmentSubjectFixture($department);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'employee_id' => $subject['employee_id'], 'valid_from' => DB::raw("clock_timestamp()+interval '250 milliseconds'")]));
        $request = ['key' => 'department', 'actor_id' => $this->user, 'purposes' => ['qa.department.read'], 'required' => true, 'department_refs' => [$subject['employee_id']]];
        $result = $this->withDepartmentLockDelay(fn () => $this->departmentRun($department, function ($r) use ($subject) {
            $this->assertSame(2, DB::table('department_memberships')->where('employee_id', $subject['employee_id'])->whereRaw('valid_from<=clock_timestamp()')->count());

            return $r['department'];
        }, [$request]));
        $this->assertTrue($result['valid']);
        $this->assertSame(['employee_id' => $subject['employee_id'], 'membership' => null, 'visibility' => 'unavailable', 'uniqueness_valid_until' => null], $result['department_subjects'][$subject['employee_id']]);
    }

    public function test_department_subject_expiry_and_future_horizon_roll_back_callback_writes(): void
    {
        $this->qaInputs();
        $department = $this->departmentFixture();
        $tables = ['qa_receipts', 'qa_source_heads', 'qa_build_versions', 'qa_changes', 'qa_outbox', 'audit_events', 'qa_project_state'];
        foreach (['expiry', 'future'] as $kind) {
            $subject = $this->departmentSubjectFixture($department);
            $before = app(TenantContext::class)->run($this->tenant, fn () => array_map(fn ($table) => DB::table($table)->count(), $tables));
            app(TenantContext::class)->run($this->tenant, function () use ($kind, $subject, $department) {
                if ($kind === 'expiry') {
                    DB::table('department_memberships')->where('id', $subject['memberships'][0])->update(['valid_to' => DB::raw("clock_timestamp()+interval '250 milliseconds'")]);
                } else {
                    DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'employee_id' => $subject['employee_id'], 'valid_from' => DB::raw("clock_timestamp()+interval '250 milliseconds'")]);
                }
            });
            $request = ['key' => 'department', 'actor_id' => $this->user, 'purposes' => ['qa.department.read'], 'required' => true, 'department_refs' => [$subject['employee_id']]];
            $worked = false;
            $this->assertDenied(function () use ($department, $request, $subject, $kind, &$worked) {
                return $this->departmentRun($department, function ($r) use ($subject, $kind, &$worked) {
                    $worked = true;
                    $this->assertSame('visible', $r['department']['department_subjects'][$subject['employee_id']]['visibility']);
                    $body = ['logical_key' => 'department-'.$kind, 'revision' => 1, 'title' => 'Synthetic department time boundary', 'content_hash' => str_repeat('a', 64)];
                    $receipt = $this->receipt($r['current']['proof'], 'build', $body, 'department-'.$kind);
                    DB::table('qa_build_versions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'receipt_id' => $receipt['receipt_id']] + $body);
                    app(QaChangeWriter::class)->appendLocked($this->actor(), $this->p, 'source_recorded', ['receipt_id' => $receipt['receipt_id']], $receipt['input_hash'], $r['current']['proof']);
                    DB::select('select pg_sleep(0.35)');
                }, [$request]);
            });
            $this->assertTrue($worked, 'Initial visible subject must reach work before final-clock denial.');
            $this->assertSame($before, app(TenantContext::class)->run($this->tenant, fn () => array_map(fn ($table) => DB::table($table)->count(), $tables)));
        }
    }

    private function departmentFixture(): string
    {
        $id = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($id) {
            DB::table('departments')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'name' => 'Synthetic department authority']);
            DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'employee_id' => $this->employee, 'department_id' => $id, 'valid_from' => now()->subDay()]);
        });
        $this->grant('qa.department.read', null, $id);

        return $id;
    }

    private function departmentSubjectFixture(string $department, array $period = [], int $memberships = 1): array
    {
        return app(TenantContext::class)->run($this->tenant, function () use ($department, $period, $memberships) {
            $employee = (string) Str::uuid();
            DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic department subject']);
            $ids = [];
            for ($i = 0; $i < $memberships; $i++) {
                $ids[] = $id = (string) Str::uuid();
                DB::table('department_memberships')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'employee_id' => $employee, 'department_id' => $department] + $period + ['valid_from' => now()->subDay()]);
            }

            return ['employee_id' => $employee, 'memberships' => $ids];
        });
    }

    private function departmentRun(string $department, \Closure $work, array $requests): mixed
    {
        return app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.read'], false, $work, $requests, $department);
    }

    private function withDepartmentLockDelay(\Closure $work): mixed
    {
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        $connection->setEventDispatcher($dispatcher);
        $waited = false;
        $dispatcher->listen(QueryExecuted::class, function ($query) use (&$waited) {
            if (! $waited && str_starts_with($query->sql, 'select * from "department_memberships"') && str_contains($query->sql, 'for share')) {
                $waited = true;
                DB::select('select pg_sleep(0.35)');
            }
        });
        try {
            $result = $work();
            $this->assertTrue($waited);

            return $result;
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }
    }

    private function ownerFixture(?string $project = null, array $period = [], int $memberships = 1): array
    {
        return app(TenantContext::class)->run($this->tenant, function () use ($project, $period, $memberships) {
            $employee = (string) Str::uuid();
            DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic explicit owner']);
            $ids = [];
            for ($i = 0; $i < $memberships; $i++) {
                $ids[] = $id = (string) Str::uuid();
                DB::table('project_memberships')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'project_id' => $project ?? $this->p, 'employee_id' => $employee] + $period + ['valid_from' => now()->subDay()]);
            }

            return ['employee_id' => $employee, 'memberships' => $ids];
        });
    }

    public function test_irrelevant_authority_history_is_not_materialized_or_locked(): void
    {
        $this->qaInputs();
        $task = $this->qaTask();
        $proof = $this->qaRun(fn ($r) => $r['current']['proof']);
        $identity = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($identity) {
            DB::table('identities')->insert(['id' => $identity, 'tenant_id' => $this->tenant, 'source_instance_id' => $this->envelopeSource(), 'external_user_id' => 'synthetic']);
            $grants = $members = $roles = $mappings = [];
            for ($i = 0; $i < 1200; $i++) {
                $from = now()->setDate(2020, 1, 1)->startOfDay()->addDays($i);
                $period = ['valid_from' => $from, 'valid_to' => $from->copy()->addDay()];
                $grants[] = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'user_id' => $this->user, 'policy_version_id' => $this->policy, 'purpose' => 'qa.read', 'scope_type' => 'project', 'project_id' => $this->p] + $period;
                $members[] = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee] + $period;
                $roles[] = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa'] + $period;
                $mappings[] = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'identity_id' => $identity, 'employee_id' => $this->employee, 'status' => 'approved', 'changed_by' => $this->user, 'reviewer_id' => $this->user, 'reviewed_at' => $from, 'reason' => 'Synthetic history'] + $period;
            }
            DB::table('access_grants')->insert($grants);
            DB::table('project_memberships')->insert($members);
            DB::table('role_assignments')->insert($roles);
            DB::table('identity_mappings')->insert($mappings);
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]);
            DB::table('identity_mappings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'identity_id' => $identity, 'employee_id' => $this->employee, 'status' => 'approved', 'changed_by' => $this->user, 'reviewer_id' => $this->user, 'reviewed_at' => now(), 'reason' => 'Synthetic current', 'valid_from' => now()->subDay()]);
        });
        $at = DB::scalar('select clock_timestamp()');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->qaRun(function ($results) use ($task, $at, $identity) {
            return ['proofs' => $results, 'snapshot' => json_decode(DB::scalar("select current_setting('app.qa_responsibility')"), true), 'responsibility' => app(QaResponsibility::class)->captureLocked($this->p, $task['version_id'], $at, $identity)];
        }, [['key' => 'original', 'actor_id' => $this->user, 'purposes' => ['qa.read'], 'required' => false, 'pinned_proof' => $proof, 'responsibility_refs' => [['task_version_id' => $task['version_id'], 'identity_id' => $identity, 'occurred_at' => $at]]]]);
        foreach (DB::getQueryLog() as $query) {
            if (str_starts_with($query['query'], 'select * from "access_grants"')) {
                $this->assertLessThanOrEqual(8, count($query['bindings']), 'Only exact selected grant IDs may reach row locking.');
            }
        }
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertTrue($data['proofs']['original']['valid']);
        $this->assertLessThanOrEqual(1, count($data['snapshot']['memberships']));
        $this->assertCount(1, $data['snapshot']['roles']);
        $this->assertCount(1, $data['snapshot']['mappings']);
        $this->assertSame('known', $data['responsibility']['attribution']);
        $this->assertLessThanOrEqual(36, $queries, 'Temporal probes and exact-ID locks must stay fixed by table count.');
    }

    public function test_responsibility_requires_exact_preloaded_event_time_and_candidate_overflow_is_unknown(): void
    {
        $this->qaInputs();
        $task = $this->qaTask();
        $at = DB::scalar('select clock_timestamp()');
        $missing = $this->qaRun(fn () => app(QaResponsibility::class)->captureLocked($this->p, $task['version_id'], $at, null), [['key' => 'responsibility', 'actor_id' => $this->user, 'purposes' => ['qa.read'], 'required' => true, 'responsibility_refs' => [['task_version_id' => $task['version_id']]]]]);
        $this->assertSame('historical_context_unknown', $missing['reason_code']);
        $pinned = $this->qaRun(fn ($r) => $r['source']['proof'], [['key' => 'source', 'actor_id' => $this->user, 'purposes' => ['qa.source.read'], 'required' => true]]);
        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'user_id' => $this->user, 'policy_version_id' => $this->policy, 'purpose' => 'qa.source.read', 'scope_type' => 'project', 'project_id' => $this->p, 'valid_from' => now()->subDay()];
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->insert($rows));
        $overflow = $this->qaRun(fn ($r) => $r, [['key' => 'source', 'actor_id' => $this->user, 'purposes' => ['qa.source.read'], 'required' => false], ['key' => 'pinned', 'actor_id' => $this->user, 'purposes' => ['qa.source.read'], 'required' => false, 'pinned_proof' => $pinned]]);
        $this->assertSame('coverage_limit', $overflow['source']['reason_code']);
        $this->assertFalse($overflow['source']['valid']);
        $this->assertTrue($overflow['pinned']['valid'], 'Excess candidates cannot truncate an exact original proof.');
    }

    public function test_same_actor_distinct_original_proofs_are_evaluated_independently(): void
    {
        $this->qaInputs();
        $first = $this->qaRun(fn ($r) => $r['current']['proof']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $first['grants'][0]['id'])->update(['revoked_at' => now(), 'revision' => 2]));
        $this->grant('qa.read', $this->p);
        $second = $this->qaRun(fn ($r) => $r['current']['proof']);
        $results = $this->qaRun(fn ($r) => $r, [
            ['key' => 'old', 'actor_id' => $this->user, 'purposes' => ['qa.read'], 'required' => false, 'pinned_proof' => $first],
            ['key' => 'new', 'actor_id' => $this->user, 'purposes' => ['qa.read'], 'required' => false, 'pinned_proof' => $second],
        ]);
        $this->assertFalse($results['old']['valid']);
        $this->assertTrue($results['new']['valid']);
        $this->assertTrue($results['current']['valid']);
    }

    public function test_actual_database_clock_expiry_rolls_back_callback_writes(): void
    {
        $this->qaInputs();
        $tables = ['qa_receipts', 'qa_source_heads', 'qa_build_versions', 'qa_request_keys', 'qa_changes', 'qa_outbox', 'audit_events'];
        $before = app(TenantContext::class)->run($this->tenant, fn () => array_map(fn ($t) => DB::table($t)->count(), $tables));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'qa.read')->update(['valid_to' => DB::raw("clock_timestamp()+interval '250 milliseconds'"), 'revision' => 2]));
        $this->assertDenied(fn () => $this->qaRun(function ($r) {
            DB::table('qa_project_state')->where('project_id', $this->p)->update(['maintenance_watermark' => 1]);
            $body = ['logical_key' => 'expiry-build', 'revision' => 1, 'title' => 'Synthetic expiry', 'content_hash' => str_repeat('a', 64)];
            $receipt = $this->receipt($r['current']['proof'], 'build', $body, 'expiry-build');
            DB::table('qa_build_versions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'receipt_id' => $receipt['receipt_id']] + $body);
            app(QaChangeWriter::class)->appendLocked($this->actor(), $this->p, 'source_recorded', ['receipt_id' => $receipt['receipt_id']], $receipt['input_hash'], $r['current']['proof']);
            DB::select('select pg_sleep(0.35)');
        }));
        $this->assertSame(0, app(TenantContext::class)->run($this->tenant, fn () => (int) DB::table('qa_project_state')->where('project_id', $this->p)->value('maintenance_watermark')));
        $this->assertSame($before, app(TenantContext::class)->run($this->tenant, fn () => array_map(fn ($t) => DB::table($t)->count(), $tables)));
    }

    public function test_authority_cannot_be_reacquired_inside_content_phase(): void
    {
        $this->qaInputs();
        $this->expectException(\LogicException::class);
        $this->qaRun(fn () => app(QaAuthority::class)->captureBatch($this->actor(), $this->p, [['key' => 'again', 'actor_id' => $this->user, 'purposes' => ['qa.read'], 'required' => true]]));
    }

    public function test_elapsed_age_is_utc_and_future_time_is_unknown(): void
    {
        $r = app(QaResponsibility::class);
        $this->assertSame(60, $r->elapsedAge('2026-10-06T00:00:00.000000Z', '2026-10-06T00:01:00.000000Z')['elapsed_seconds']);
        $this->assertNull($r->elapsedAge('2026-10-07T00:00:00Z', '2026-10-06T00:00:00Z')['elapsed_seconds']);
        $this->assertNull($r->elapsedAge('yesterday', '2026-10-06T00:00:00Z')['elapsed_seconds']);
        $this->assertNull($r->elapsedAge('2026-02-31T00:00:00Z', '2026-10-06T00:00:00Z')['elapsed_seconds']);
    }

    public function test_prelocked_event_time_responsibility_records_role_and_membership_without_new_locks(): void
    {
        $this->qaInputs();
        $criterion = app(TenantContext::class)->run($this->tenant, fn () => DB::table('criterion_versions')->where('id', $this->criteria[0]['version_id'])->first());
        $task = app(TaskService::class)->create($this->actor(), $this->p, ['title' => 'Synthetic task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic only', 'remaining_effort' => null, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => [['criterion_id' => $criterion->criterion_id, 'criterion_version_id' => $criterion->id]]], $this->generation);
        $this->generation = $task['generation'];
        $role = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->insert(['id' => $role, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]));
        $instant = DB::scalar('select clock_timestamp()');
        $snapshot = $this->qaRun(fn () => app(QaResponsibility::class)->captureLocked($this->p, $task['version_id'], $instant, null), [['key' => 'responsibility', 'actor_id' => $this->user, 'purposes' => ['qa.read'], 'required' => true, 'responsibility_refs' => [['task_version_id' => $task['version_id'], 'identity_id' => null, 'occurred_at' => $instant]]]]);
        $this->assertSame('known', $snapshot['attribution']);
        $this->assertSame($this->employee, $snapshot['employee_id']);
        $this->assertSame($role, $snapshot['roles'][0]['id']);
        $this->assertSame(1, $snapshot['roles'][0]['revision']);
        $this->assertSame(64, strlen($snapshot['input_hash']));
        $earlyAt = now()->subHour()->toIso8601String();
        $early = $this->qaRun(fn () => app(QaResponsibility::class)->captureLocked($this->p, $task['version_id'], $earlyAt, null), [['key' => 'responsibility', 'actor_id' => $this->user, 'purposes' => ['qa.read'], 'required' => true, 'responsibility_refs' => [['task_version_id' => $task['version_id'], 'identity_id' => null, 'occurred_at' => $earlyAt]]]]);
        $this->assertSame('unknown', $early['attribution']);
    }

    public function test_locked_pins_can_read_immutable_rows_as_nonowner_without_update_privilege(): void
    {
        $this->qaInputs();
        $ids = $this->seedQaLineage();
        $pins = app(QaPins::class)->collect($this->actor(), $this->p, ['build' => [$ids['build']], 'definition' => [$ids['definition']]]);
        $loaded = $this->qaRun(fn () => app(QaPins::class)->loadLocked($this->p, $pins['refs']), $pins['requests']);
        $this->assertSame($ids['build'], $loaded['build'][$ids['build']]->id);
        $this->assertSame($ids['definition'], $loaded['definition'][$ids['definition']]->id);
    }

    public function test_pin_authority_hints_prefetch_receipts_in_one_bounded_query(): void
    {
        $this->qaInputs();
        $ids = $this->qaRun(function ($r) {
            $ids = [];
            for ($i = 0; $i < 25; $i++) {
                $body = ['logical_key' => 'build-'.$i, 'revision' => 1, 'title' => 'Synthetic', 'content_hash' => str_repeat('a', 64)];
                $receipt = $this->receipt($r['current']['proof'], 'build', $body, 'build-'.$i);
                $ids[] = $id = (string) Str::uuid();
                DB::table('qa_build_versions')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'receipt_id' => $receipt['receipt_id']] + $body);
            }

            return $ids;
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $pins = app(QaPins::class)->collect($this->actor(), $this->p, ['build' => $ids]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(25, $pins['requests']);
        $this->assertLessThanOrEqual(6, $queries, 'Receipt hints must be batched, never one lookup per fact.');
    }

    public function test_more_than_five_thousand_keyed_original_proofs_for_one_actor_are_batched(): void
    {
        $this->qaInputs();
        $proof = $this->qaRun(fn ($r) => $r['current']['proof']);
        $requests = [];
        for ($i = 0; $i < 5001; $i++) {
            $requests[] = ['key' => 'original-'.$i, 'actor_id' => $this->user, 'purposes' => ['qa.read'], 'required' => false, 'pinned_proof' => $proof];
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $results = $this->qaRun(fn ($r) => $r, $requests);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(5002, $results);
        $this->assertTrue($results['original-5000']['valid']);
        $this->assertLessThanOrEqual(30, $queries, 'Authority must lock the union, not query each proof.');
    }

    public function test_actor_overflow_marks_all_optional_original_proofs_unknown_without_favourable_subset(): void
    {
        $this->qaInputs();
        $requests = [];
        for ($i = 0; $i < 101; $i++) {
            $requests[] = ['key' => 'original-'.$i, 'actor_id' => (string) Str::uuid(), 'purposes' => ['qa.read'], 'required' => false];
        }
        $results = $this->qaRun(fn ($r) => $r, $requests);
        $this->assertTrue($results['current']['valid']);
        $this->assertCount(102, $results);
        foreach ($requests as $request) {
            $this->assertSame(['valid' => false, 'reason_code' => 'coverage_limit'], $results[$request['key']]);
        }
    }

    public function test_more_than_five_thousand_scoped_pin_slots_reach_scoped_lookup_without_unsupported_cap(): void
    {
        $this->qaInputs();
        $refs = ['build' => [], 'environment' => [], 'definition' => []];
        for ($i = 0; $i < 5001; $i++) {
            $refs[array_keys($refs)[$i % 3]][] = (string) Str::uuid();
        }
        $this->expectException(NotFoundHttpException::class);
        app(QaPins::class)->collect($this->actor(), $this->p, $refs);
    }

    public function test_exactly_one_hundred_original_actors_do_not_count_the_separate_caller_as_overflow(): void
    {
        $this->qaInputs();
        $requests = [];
        for ($i = 0; $i < 100; $i++) {
            $requests[] = ['key' => 'original-'.$i, 'actor_id' => (string) Str::uuid(), 'purposes' => ['qa.read'], 'required' => false];
        }
        $results = $this->qaRun(fn ($r) => $r, $requests);
        $this->assertTrue($results['current']['valid']);
        $this->assertSame('access_revoked', $results['original-99']['reason_code']);
    }
}
