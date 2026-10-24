<?php

namespace Tests\Feature;

use App\Domain\Ba\BaReadService;
use App\Domain\Management\ManagementActionService;
use App\Domain\Management\ManagementReceiptWriter;
use App\Domain\Management\ManagementReviewService;
use App\Domain\Management\ManagementSupportService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\ManagementTestCase;

class ManagementRevocationTest extends ManagementTestCase
{
    public function test_native_ba_observed_invalidity_survives_same_row_restore_in_management(): void
    {
        $this->managementInputs();
        $ba = $this->managementBaReviewedFixture();
        $reference = array_replace($this->lifecycleReference(), ['kind' => 'ba_review', 'ba_review_id' => $ba['review']['review_id']]);
        $references = [$this->lifecycleReference(), $reference];
        $f = $this->workflowAssigned($references, false);
        $body = $this->workflowSupportBody($f);
        $body['references'] = $references;
        $support = app(ManagementSupportService::class)->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $body, 'ba-terminal-support'), 'ba-terminal-support', 0);
        $this->grant('management.outcome.review', $this->p);
        $grant = app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'ba.request.manage')->first());
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant->id)->update(['revoked_at' => now()]));
        // The native observer is fixture setup, outside the management phase.
        app(BaReadService::class)->history($this->actor(), $this->p, $ba['request']['clarification_id']);
        app(TenantContext::class)->run($this->tenant, function () use ($grant) {
            $this->assertGreaterThan(0, DB::table('ba_terminal_applicability')->count());
            $this->assertSame(0, DB::table('management_terminal_applicability')->count());
            DB::table('access_grants')->where('id', $grant->id)->update(['revoked_at' => $grant->revoked_at]);
        });
        $reviewBody = $this->workflowReviewBody($support);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(ManagementReviewService::class)->review($this->actor(), $this->p, $f['open']['action_id'], $reviewBody, 'ba-terminal-confirm');
            $this->fail('Management revived a native BA terminal source fact.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
            foreach (DB::getQueryLog() as $query) {
                $this->assertDoesNotMatchRegularExpression('/^\s*(insert\s+into|update|delete\s+from|truncate)\s+"?ba_/i', $query['query']);
            }
        } finally {
            DB::disableQueryLog();
        }
        app(TenantContext::class)->run($this->tenant, function () use ($f) {
            $root = DB::table('management_actions')->where('id', $f['open']['action_id'])->first();
            $this->assertNull($root->current_outcome);
            $this->assertSame('unverified', $root->review_status);
            $this->assertSame(0, DB::table('management_reviews')->count());
        });
    }

    public function test_observed_original_assignment_revocation_cannot_resurrect_after_regrant(): void
    {
        $f = $this->workflowAssigned();
        $grant = app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $f['head']->id)->where('purpose', 'management.department.manage')->value('id'));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
        $envelope = $this->managementEnvelope('support.record', $this->workflowSupportBody($f), 'revoked-support');
        $service = app(ManagementSupportService::class);
        $this->denied(fn () => $service->record($f['helper'], $this->p, $f['open']['action_id'], $envelope, 'revoked', 0));
        app(TenantContext::class)->run($this->tenant, function () use ($grant) {
            $this->assertGreaterThan(0, DB::table('management_terminal_applicability')->count());
            $this->assertSame(2, DB::table('management_receipts')->count());
            DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => null]);
        });
        $this->denied(fn () => $service->record($f['helper'], $this->p, $f['open']['action_id'], $envelope, 'after-regrant', 0));
    }

    public function test_current_hidden_helper_membership_prevents_whole_record_mutation(): void
    {
        $f = $this->workflowAssigned();
        app(TenantContext::class)->run($this->tenant, function () use ($f) {
            $employee = DB::table('users')->where('id', $f['helper']->id)->value('employee_id');
            DB::table('project_memberships')->where('project_id', $this->p)->where('employee_id', $employee)->update(['valid_to' => now()]);
        });
        $this->denied(fn () => app(ManagementActionService::class)->event($this->actor(), $this->p, $f['open']['action_id'], ['operation' => 'context.add', 'expected_revision' => 2, 'context' => 'Must remain hidden'], 'hidden'));
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(2, DB::table('management_events')->count()));
    }

    public function test_actual_expiry_after_capture_rolls_back_receipt_before_commit(): void
    {
        $this->managementInputs();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'management.action.manage')->update(['valid_to' => DB::raw("clock_timestamp()+interval '0.8 seconds'")]));
        $envelope = $this->managementEnvelope('action.open', $this->actionInput());
        $writer = app(ManagementReceiptWriter::class);
        $captured = false;
        $this->denied(function () use ($writer, $envelope, &$captured) {
            $writer->run($this->actor(), $this->p, null, 'action.open', $envelope, 'expiry', ['management.action.manage', 'management.source.read', 'requirements.read', 'progress.read'], $envelope['body']['references'], [$this->employee], null, function ($proof) use (&$captured) {
                $captured = true;
                DB::select('select pg_sleep(1)');

                return [];
            });
        });
        $this->assertTrue($captured);
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(0, DB::table('management_receipts')->count()));
    }

    private function denied(\Closure $work): void
    {
        try {
            $work();
            $this->fail('Expected management authorization denial.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
    }
}
