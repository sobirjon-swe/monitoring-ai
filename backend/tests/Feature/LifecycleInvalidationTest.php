<?php

namespace Tests\Feature;

use App\Domain\Access\TrustedActor;
use App\Domain\Lifecycle\EvidenceService;
use App\Domain\Lifecycle\LifecycleInvalidationProcessor;
use App\Domain\Lifecycle\QaObservationService;
use App\Domain\Lifecycle\RequirementService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\BaselineTestCase;

class LifecycleInvalidationTest extends BaselineTestCase
{
    private string $processGrant;

    private function setupInputs(): void
    {
        $this->inputs();
        $this->processGrant = $this->grant('lifecycle.process', $this->p);
        $this->baseline($this->scope());
    }

    private function scopeDb(\Closure $work): mixed
    {
        return app(TenantContext::class)->run($this->tenant, $work);
    }

    private function processor(): LifecycleInvalidationProcessor
    {
        return app(LifecycleInvalidationProcessor::class);
    }

    private function drain(int $limit = 100): array
    {
        return $this->processor()->drain($this->actor(), $this->p, $limit);
    }

    private function projection(string $id): object
    {
        return $this->scopeDb(fn () => DB::table('acceptance_applicability')->where('decision_id', $id)->first());
    }

    private function qa(array $link, string $result, int $revision): void
    {
        $r = app(QaObservationService::class)->record($this->actor(), $this->p, ['criterion_version_id' => $this->criteria[0]['version_id'], 'link_id' => $link['id'], 'test_key' => 'test-a', 'result' => $result], $revision, $this->generation);
        $this->generation = $r['generation'];
    }

    public function test_reject_to_merge_invalidates_only_linked_acceptance(): void
    {
        $this->setupInputs();
        $aLink = $this->evidence();
        $a = $this->accept($aLink);
        $bLink = $this->evidence(1);
        $b = $this->accept($bLink, 1);
        $old = $this->baselineId;
        $items = $this->scope([67, 20, 33], $a['id']);
        $items[1]['carry_decision_id'] = $b['id'];
        $this->baseline($items);
        $before = $this->progress($old);
        $this->drain();
        $data = $this->requirement();
        $data['criteria'][0]['text'] = 'Merge';
        $data['criteria'][] = ['key' => 'C', 'text' => 'Synthetic C', 'required_test_keys' => ['test-c']];
        app(RequirementService::class)->updateDraft($this->actor(), $this->p, $this->draft['id'], $data, 1);
        $r = app(RequirementService::class)->approve($this->actor(), $this->p, $this->draft['id'], 2, $this->generation, 'Synthetic Reject to Merge');
        $this->generation = $r['generation'];
        $this->assertSame(20, $this->progress()['accepted_weight']);
        $this->assertSame(1, $this->drain()['completed']);
        $this->assertSame('stale', $this->projection($a['id'])->status);
        $this->assertSame('valid', $this->projection($b['id'])->status);
        $this->assertEquals($before, $this->progress($old));
    }

    public function test_review_rejection_only_invalidates_pinned_link(): void
    {
        $this->setupInputs();
        $aLink = $this->evidence();
        $a = $this->accept($aLink);
        $b = $this->accept($this->evidence(1), 1);
        $this->drain();
        $r = app(EvidenceService::class)->review($this->actor(), $this->p, $aLink['id'], 'rejected', 1, $this->generation, 'Synthetic reject');
        $this->generation = $r['generation'];
        $this->assertSame(20, $this->progress()['accepted_weight']);
        $this->assertSame(1, $this->drain()['completed']);
        $this->assertSame('stale', $this->projection($a['id'])->status);
        $this->assertSame('valid', $this->projection($b['id'])->status);
    }

    public function test_passed_replacement_qa_cannot_reaccept_and_old_generation_job_cannot_restore(): void
    {
        $this->setupInputs();
        $link = $this->evidence();
        $decision = $this->accept($link);
        $old = $this->processor()->claimNext($this->actor(), $this->p);
        $this->qa($link, 'failed', 1);
        $this->assertSame(0, $this->progress()['accepted_weight']);
        $this->drain();
        $this->qa($link, 'passed', 2);
        $this->assertSame(0, $this->progress()['accepted_weight']);
        $this->drain();
        $this->assertSame('completed', $this->processor()->deliver($this->actor(), $this->p, $old->id, $old->lease_token));
        $this->assertSame('stale', $this->projection($decision['id'])->status);
        $new = $this->accept($link);
        $this->drain();
        $this->assertSame('valid', $this->projection($new['id'])->status);
        $this->assertSame('stale', $this->projection($decision['id'])->status);
        $this->assertSame(67, $this->progress()['accepted_weight']);
    }

    public function test_job_pending_and_revocation_never_deliver_cached_progress_and_is_sticky(): void
    {
        $this->setupInputs();
        $link = $this->evidence();
        $d = $this->accept($link);
        $this->drain();
        $operator = $this->independentOperator();
        $grant = $this->scopeDb(fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'acceptance.review')->value('id'));
        $this->scopeDb(fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
        $this->assertSame('unavailable', $this->progress()['status']);
        $this->qa($link, 'passed', 1);
        $this->assertSame(1, $this->processor()->drain($operator, $this->p)['completed']);
        $this->assertSame('revoked', $this->projection($d['id'])->status);
        $this->scopeDb(fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => null]));
        $this->assertSame(0, $this->progress()['accepted_weight']);
        $this->assertSame('unavailable', $this->progress()['status']);
        $this->assertSame('revoked', $this->projection($d['id'])->status);
    }

    private function independentOperator(): TrustedActor
    {
        $id = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $this->scopeDb(function () use ($id, $employee) {
            DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic operator']);
            DB::table('users')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'employee_id' => $employee, 'display_name' => 'Synthetic operator']);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
            foreach (['lifecycle.process', 'ingestion.read'] as $purpose) {
                DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'user_id' => $id, 'policy_version_id' => $this->policy, 'purpose' => $purpose, 'scope_type' => 'project', 'project_id' => $this->p, 'valid_from' => now()->subDay()]);
            }
        });

        return new TrustedActor($id, $this->tenant);
    }

    public function test_processor_original_actor_and_membership_revoked(): void
    {
        $this->setupInputs();
        $this->drain();
        $link = $this->evidence();
        $operator = $this->independentOperator();
        $this->scopeDb(fn () => DB::table('project_memberships')->where('employee_id', $this->employee)->update(['valid_to' => now()]));
        $r = $this->processor()->drain($operator, $this->p);
        $this->assertSame(3, $r['blocked']);
        $this->scopeDb(fn () => $this->assertSame(3, DB::table('lifecycle_outbox')->where('status', 'blocked')->where('reason_code', 'access_revoked')->count()));
    }

    public function test_claim_crash_expiry_stale_fence_and_fifth_failure(): void
    {
        $this->setupInputs();
        $this->drain();
        $link = $this->evidence();
        $d = $this->accept($link);
        $this->drain();
        $this->qa($link, 'failed', 1);
        $first = $this->processor()->claimNext($this->actor(), $this->p);
        $this->assertNull($this->processor()->claimNext($this->actor(), $this->p));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $second = $this->processor()->claimNext($this->actor(), $this->p);
        $this->assertNotSame($first->lease_token, $second->lease_token);
        $this->assertSame('stale', $this->processor()->deliver($this->actor(), $this->p, $first->id, $first->lease_token));
        DB::listen(function ($q) {
            if (str_contains($q->sql, 'update "acceptance_applicability"')) {
                throw new \RuntimeException('SECRET synthetic failure');
            }
        });
        $this->assertSame('retry', $this->processor()->deliver($this->actor(), $this->p, $second->id, $second->lease_token));
        $clock = CarbonImmutable::now()->addSeconds(30);
        foreach ([120, 600, null] as $index => $delay) {
            $row = $this->scopeDb(fn () => DB::table('lifecycle_outbox')->where('id', $first->id)->first());
            $this->assertTrue(CarbonImmutable::parse($row->available_at)->equalTo($clock));
            CarbonImmutable::setTestNow(CarbonImmutable::parse($row->available_at));
            $clock = CarbonImmutable::now()->addSeconds($delay ?? 0);
            $r = $this->drain(1);
            $this->assertSame(1, $r[$delay === null ? 'dead_letter' : 'retry']);
            $this->assertSame('valid', $this->projection($d['id'])->status);
        }
        $row = $this->scopeDb(fn () => DB::table('lifecycle_outbox')->where('id', $first->id)->first());
        $this->assertSame(5, $row->attempt_count);
        $this->assertSame('processing_failed', $row->reason_code);
    }

    public function test_cli_guard_and_safe_fixed_counts(): void
    {
        $this->setupInputs();
        $args = ['tenant' => $this->tenant, 'actor' => $this->user, 'project' => $this->p];
        $this->artisan('app:lifecycle-drain', $args + ['--limit' => '101'])->assertFailed();
        $this->artisan('app:lifecycle-drain', $args + ['--limit' => '1x'])->assertFailed();
        $this->artisan('app:lifecycle-drain', $args)->expectsOutputToContain('claimed=2 completed=2 retry=0 dead_letter=0 blocked=0 stale=0')->assertSuccessful();
        config(['database.connections.pgsql.host' => 'remote.invalid']);
        $this->artisan('app:lifecycle-drain', $args)->assertFailed();
        config(['database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.url' => 'postgres://override']);
        $this->artisan('app:lifecycle-drain', $args)->assertFailed();
        config(['database.connections.pgsql.url' => null]);
        $this->app->instance('env', 'production');
        $this->artisan('app:lifecycle-drain', $args)->assertFailed();
    }

    public function test_two_runtime_sessions_same_committed_row(): void
    {
        $this->setupInputs();
        $this->drain();
        $link = $this->evidence();
        $this->accept($link);
        $this->drain();
        $this->qa($link, 'failed', 1);
        $this->assertStringStartsWith('monitoring-ai-local-v1:', DB::scalar("select shobj_description(oid,'pg_database') from pg_database where datname=current_database()"));
        DB::commit(); // Retain this synthetic immutable fixture in the guarded test database.
        $primary = DB::connection('pgsql');
        $pdo = $primary->getPdo();
        config(['database.connections.lifecycle_contender' => config('database.connections.pgsql')]);
        $other = DB::connection('lifecycle_contender');
        $this->assertNotSame($pdo, $other->getPdo());
        $this->assertSame('monitoring_ai_test', $other->scalar('select current_database()'));
        $this->assertSame('monitoring_ai_app', $other->scalar('select current_user'));
        $other->beginTransaction();
        try {
            $other->select("select set_config('app.tenant_id',?,true)", [$this->tenant]);
            $this->assertNotNull($other->table('lifecycle_outbox')->where('status', 'pending')->lockForUpdate()->first());
            $this->assertNull($this->processor()->claimNext($this->actor(), $this->p));
        } finally {
            $other->rollBack();
        }
        try {
            $first = $this->processor()->claimNext($this->actor(), $this->p);
            $primary->setPdo($other->getPdo());
            $this->assertSame($first->lease_token, $this->scopeDb(fn () => DB::table('lifecycle_outbox')->where('id', $first->id)->value('lease_token')));
            $this->assertNull($this->processor()->claimNext($this->actor(), $this->p));
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
            $second = $this->processor()->claimNext($this->actor(), $this->p);
            $primary->setPdo($pdo);
            $this->assertSame('stale', $this->processor()->deliver($this->actor(), $this->p, $first->id, $first->lease_token));
            $this->assertSame('completed', $this->processor()->deliver($this->actor(), $this->p, $second->id, $second->lease_token));
            $primary->setPdo($other->getPdo());
            $this->assertSame('completed', $this->scopeDb(fn () => DB::table('lifecycle_outbox')->where('id', $first->id)->value('status')));
            $this->assertSame(0, $this->progress()['accepted_weight']);
        } finally {
            $primary->setPdo($pdo);
            DB::purge('lifecycle_contender');
        }
    }

    public function test_first_failure_retries_after_five_seconds_and_completion_expiry_rolls_back(): void
    {
        $this->setupInputs();
        $link = $this->evidence();
        $decision = $this->accept($link);
        $this->drain();
        $this->qa($link, 'failed', 1);
        $clock = CarbonImmutable::now();
        CarbonImmutable::setTestNow($clock);
        $inject = true;
        DB::listen(function ($q) use (&$inject) {
            if ($inject && str_contains($q->sql, 'update "acceptance_applicability"')) {
                $inject = false;
                throw new \RuntimeException('SECRET synthetic failure');
            }
        });
        $this->assertSame(1, $this->drain(1)['retry']);
        $row = $this->scopeDb(fn () => DB::table('lifecycle_outbox')->where('status', 'retry')->first());
        $this->assertTrue(CarbonImmutable::parse($row->available_at)->equalTo($clock->addSeconds(5)));
        $this->assertSame('valid', $this->projection($decision['id'])->status);
        $this->assertSame(0, $this->drain()['claimed']);
        CarbonImmutable::setTestNow($clock->addSeconds(5));
        $expiry = true;
        DB::listen(function ($q) use (&$expiry) {
            if ($expiry && str_contains($q->sql, 'update "acceptance_applicability"')) {
                $expiry = false;
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
            }
        });
        $this->assertSame(1, $this->drain(1)['stale']);
        $this->assertSame('valid', $this->projection($decision['id'])->status);
        $this->assertSame(1, $this->drain(1)['completed']);
        $this->assertSame('stale', $this->projection($decision['id'])->status);
    }

    public function test_original_purpose_and_operator_source_revocation_block_without_applied_projection(): void
    {
        $this->setupInputs();
        $this->drain();
        $this->evidence();
        $claim = $this->processor()->claimNext($this->actor(), $this->p);
        $this->scopeDb(fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'evidence.review')->update(['revoked_at' => now()]));
        $operator = $this->independentOperator();
        $this->assertSame('blocked', $this->processor()->deliver($operator, $this->p, $claim->id, $claim->lease_token));
        $this->scopeDb(fn () => DB::table('access_grants')->where('user_id', $operator->id)->where('purpose', 'ingestion.read')->update(['revoked_at' => now()]));
        $this->assertSame(2, $this->processor()->drain($operator, $this->p)['blocked']);
        $this->scopeDb(fn () => $this->assertSame(0, DB::table('acceptance_applicability')->count()));
    }

    public function test_completion_rechecks_operator_and_rolls_back_projection(): void
    {
        $this->setupInputs();
        $link = $this->evidence();
        $d = $this->accept($link);
        $this->drain();
        $this->qa($link, 'failed', 1);
        $inject = true;
        DB::listen(function ($q) use (&$inject) {
            if ($inject && str_contains($q->sql, 'update "acceptance_applicability"')) {
                $inject = false;
                DB::table('access_grants')->where('id', $this->processGrant)->update(['revoked_at' => now()]);
            }
        });
        $this->assertSame(1, $this->drain(1)['blocked']);
        $this->assertSame('valid', $this->projection($d['id'])->status);
    }

    public function test_sticky_projection_cannot_be_reset_by_sql_or_carried_after_restored_grant(): void
    {
        $this->setupInputs();
        $link = $this->evidence();
        $d = $this->accept($link);
        $this->scopeDb(fn () => DB::table('acceptance_applicability')->where('decision_id', $d['id'])->update(['status' => 'stale']));
        $this->assertSame(0, $this->progress()['accepted_weight']);
        try {
            $this->scopeDb(fn () => DB::table('acceptance_applicability')->where('decision_id', $d['id'])->update(['status' => 'valid']));
            $this->fail('Terminal status was restored.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        try {
            $this->baseline($this->scope([67, 20, 33], $d['id']));
            $this->fail('Stale carry was accepted.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
        $new = $this->accept($link);
        $this->drain();
        $this->assertSame('valid', $this->projection($new['id'])->status);
        $this->assertSame(67, $this->progress()['accepted_weight']);
    }

    public function test_expired_fifth_claim_dead_letters_and_scope_and_purpose_cannot_be_forged(): void
    {
        $this->setupInputs();
        $this->drain();
        $link = $this->evidence();
        $this->drain();
        $this->qa($link, 'failed', 1);
        for ($i = 0; $i < 5; $i++) {
            $this->assertNotNull($this->processor()->claimNext($this->actor(), $this->p));
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        }
        $this->assertSame(1, $this->drain(1)['dead_letter']);
        $this->scopeDb(function () {
            $row = DB::table('lifecycle_outbox')->where('status', 'dead_letter')->first();
            $this->assertSame(5, $row->attempt_count);
            $this->assertNull($row->lease_token);
        });
        $this->assertSame(0, $this->drain()['claimed']);
        foreach ([0, 101] as $limit) {
            try {
                $this->drain($limit);
                $this->fail('Unbounded drain.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->scopeDb(fn () => DB::table('access_grants')->where('id', $this->processGrant)->update(['revoked_at' => now()]));
        $this->grant('ingestion.process', $this->p);
        $this->grant('lifecycle.process');
        foreach ([$this->actor(), new TrustedActor($this->user, (string) Str::uuid())] as $actor) {
            try {
                $this->processor()->drain($actor, $this->p);
                $this->fail('Forged dispatcher scope.');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
