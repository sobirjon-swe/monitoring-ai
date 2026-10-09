<?php

namespace Tests\Feature;

use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaReadService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BaTestCase;

class BaRevocationTest extends BaTestCase
{
    public function test_inactive_current_viewer_denies_before_original_observations(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->openRequest();
        $this->baRun(fn () => DB::table('users')->where('id', $this->user)->update(['status' => 'inactive']));
        $this->assertBaDenied(fn () => app(BaReadService::class)->queue($this->actor(), $this->p, ['limit' => 1]));
        $this->assertSame(0, app(TenantContext::class)->run($this->tenant, fn () => DB::table('ba_terminal_applicability')->where('project_id', $this->p)->count()));
    }

    public function test_inactive_original_recorder_is_observed_before_limit_and_reactivation_cannot_replay(): void
    {
        $this->baInputs();
        $this->baRole();
        $original = $this->otherBaActor();
        $body = $this->requestInput();
        $envelope = $this->baEnvelope('request.open', $body, 'a-invalid');
        $affected = app(BaClarificationService::class)->open($original, $this->p, $envelope, 'a-invalid', 0);
        $healthy = $this->openRequest(null, 'b-healthy');
        $this->baRun(fn () => DB::table('users')->where('id', $original->id)->update(['status' => 'inactive']));

        $page = app(BaReadService::class)->queue($this->actor(), $this->p, ['limit' => 1]);
        $this->assertSame([$healthy['clarification_id']], array_column($page['items'], 'clarification_id'));
        $this->assertSame('complete', $page['coverage_status']);
        $this->assertSame(1, $page['visible_record_count']);
        $observations = $this->baRun(fn () => DB::table('ba_terminal_applicability')->where('project_id', $this->p)->get());
        $this->assertNotEmpty($observations);
        $this->assertContains($affected['request_version_id'], $observations->pluck('subject_id')->all());
        $this->assertSame([], array_values(array_diff($observations->pluck('subject_id')->all(), [$affected['request_version_id'], $affected['receipt_id']])));

        $this->baRun(fn () => DB::table('users')->where('id', $original->id)->update(['status' => 'active']));
        $this->assertSame([$healthy['clarification_id']], array_column(app(BaReadService::class)->queue($this->actor(), $this->p, ['limit' => 1])['items'], 'clarification_id'));
        $this->assertBaDenied(fn () => app(BaClarificationService::class)->open($original, $this->p, $envelope, 'a-invalid', 0));
        $this->assertGreaterThan(0, $this->baRun(fn () => DB::table('ba_terminal_applicability')->where('subject_id', $affected['request_version_id'])->count()));
    }

    public function test_exact_original_requester_membership_replacement_observes_only_affected_root(): void
    {
        $this->baInputs();
        $this->baRole();
        $other = $this->pmActor();
        $employee = $this->baRun(fn () => DB::table('users')->where('id', $other->id)->value('employee_id'));
        $body = $this->requestInput();
        $body['requester_id'] = $employee;
        $affected = $this->openRequest($body, 'affected');
        $healthy = $this->openRequest(null, 'healthy');
        $this->baRun(function () use ($employee) {
            DB::table('project_memberships')->where('project_id', $this->p)->where('employee_id', $employee)->update(['valid_to' => now()->subSecond()]);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'valid_from' => now()]);
        });
        $bounded = app(BaReadService::class)->queue($this->actor(), $this->p, ['limit' => 1]);
        $this->assertSame([$healthy['clarification_id']], array_column($bounded['items'], 'clarification_id'));
        $page = app(BaReadService::class)->queue($this->actor(), $this->p);
        $this->assertSame([$healthy['clarification_id']], array_column($page['items'], 'clarification_id'));
        $this->assertSame('complete', $page['coverage_status']);
        $this->assertGreaterThan(0, $this->baRun(fn () => DB::table('ba_terminal_applicability')->where('subject_id', $affected['request_version_id'])->count()));
        $this->assertSame(0, $this->baRun(fn () => DB::table('ba_terminal_applicability')->where('subject_id', $healthy['request_version_id'])->count()));
    }

    public function test_read_observes_original_grant_revocation_and_restore_cannot_replay(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $grant = $this->baRun(fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'ba.request.manage')->first());
        $this->baRun(fn () => DB::table('access_grants')->where('id', $grant->id)->update(['revoked_at' => now()]));
        $page = app(BaReadService::class)->queue($this->actor(), $this->p);
        $this->assertSame([], $page['items']);
        $this->assertSame('unknown', $page['coverage_status']);
        $this->assertNull($page['visible_record_count']);
        $this->assertGreaterThan(0, $this->baRun(fn () => DB::table('ba_terminal_applicability')->where('subject_id', $request['request_version_id'])->count()));
        $this->baRun(fn () => DB::table('access_grants')->where('id', $grant->id)->update(['revoked_at' => null]));
        $this->assertSame([], app(BaReadService::class)->queue($this->actor(), $this->p)['items']);
        $this->assertBaDenied(fn () => app(BaClarificationService::class)->open($this->actor(), $this->p, $this->baEnvelope('request.open', $request['body'], 'question'), 'question', 0));
        $healthy = $this->openRequest(null, 'healthy');
        $this->assertSame([$healthy['clarification_id']], array_column(app(BaReadService::class)->queue($this->actor(), $this->p)['items'], 'clarification_id'));
    }
}
