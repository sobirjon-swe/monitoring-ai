<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementActionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\ManagementTestCase;

class ManagementActionTest extends ManagementTestCase
{
    public static function invalidManageAlternatives(): array
    {
        return [['orphan'], ['retired_policy'], ['both_invalid']];
    }

    #[DataProvider('invalidManageAlternatives')]
    public function test_valid_helper_context_survives_an_unusable_manage_alternative(string $case): void
    {
        $f = $this->workflowAssigned();
        $this->managementGrantActor($f['helper'], ['management.action.manage']);
        app(TenantContext::class)->run($this->tenant, function () use ($f, $case) {
            if ($case === 'retired_policy') {
                $policy = (string) Str::uuid();
                DB::table('policy_versions')->insert(['id' => $policy, 'tenant_id' => $this->tenant, 'version' => 2, 'status' => 'retired']);
                DB::table('access_grants')->where('user_id', $f['helper']->id)->where('purpose', 'management.action.manage')->update(['policy_version_id' => $policy]);
                $employee = DB::table('users')->where('id', $f['helper']->id)->value('employee_id');
                DB::table('role_assignments')->where('project_id', $this->p)->where('employee_id', $employee)->where('role', 'developer')->update(['role' => 'pm']);
            } elseif ($case === 'both_invalid') {
                DB::table('access_grants')->where('user_id', $f['helper']->id)->where('purpose', 'management.support.record')->update(['revoked_at' => now()]);
            }
        });
        if ($case === 'both_invalid') {
            $this->expectException(AccessDeniedHttpException::class);
        }
        $context = app(ManagementActionService::class)->event($f['helper'], $this->p, $f['open']['action_id'], ['operation' => 'context.add', 'expected_revision' => 2, 'context' => 'Synthetic authorized helper context'], 'helper-alternative');
        $this->assertSame(3, $context['revision']);
        app(TenantContext::class)->run($this->tenant, function () use ($context, $f) {
            $receipt = DB::table('management_receipts')->where('id', $context['receipt_id'])->first();
            $proof = json_decode($receipt->authority_proof, true, 32, JSON_THROW_ON_ERROR);
            $this->assertContains('management.support.record', $proof['purposes']);
            $this->assertNotContains('management.action.manage', $proof['purposes']);
            $this->assertSame($f['assignment']['assignment_id'], DB::table('management_actions')->where('id', $f['open']['action_id'])->value('current_assignment_id'));
        });
    }

    public function test_exact_open_replay_returns_the_original_subject(): void
    {
        $this->managementInputs();
        $envelope = $this->managementEnvelope('action.open', $this->actionInput());
        $service = app(ManagementActionService::class);
        $first = $service->open($this->actor(), $this->p, $envelope, 'open-1', 0);
        $again = $service->open($this->actor(), $this->p, $envelope, 'open-1', 0);
        $this->assertFalse($first['replayed']);
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['action_id'], $again['action_id']);
        $this->assertSame($first['event_id'], $again['event_id']);
    }

    public function test_open_identity_conflicts_and_human_cas_are_atomic(): void
    {
        $this->managementInputs();
        $service = app(ManagementActionService::class);
        $envelope = $this->managementEnvelope('action.open', $this->actionInput());
        $open = $service->open($this->actor(), $this->p, $envelope, 'open', 0);
        $changed = $envelope;
        $changed['body']['reason'] = 'Changed content';
        try {
            $service->open($this->actor(), $this->p, $changed, 'open', 0);
            $this->fail('Different content must conflict.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
        $body = ['operation' => 'context.add', 'expected_revision' => 1, 'context' => 'Synthetic employee context'];
        $first = $service->event($this->actor(), $this->p, $open['action_id'], $body, 'context');
        $again = $service->event($this->actor(), $this->p, $open['action_id'], $body, 'context');
        $this->assertSame($first['event_id'], $again['event_id']);
        try {
            $service->event($this->actor(), $this->p, $open['action_id'], $body, 'stale-context');
            $this->fail('Stale CAS must conflict.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
        app(TenantContext::class)->run($this->tenant, function () {
            $this->assertSame(2, DB::table('management_receipts')->count());
            $this->assertSame(2, DB::table('management_events')->count());
            $this->assertSame(2, DB::table('management_request_bindings')->count());
            $this->assertSame(2, DB::table('management_project_state')->value('generation'));
        });
    }

    public function test_revise_withdraw_reopen_and_illegal_reopen(): void
    {
        $this->managementInputs();
        $service = app(ManagementActionService::class);
        $open = $service->open($this->actor(), $this->p, $this->managementEnvelope('action.open', $this->actionInput()), 'open', 0);
        try {
            $service->event($this->actor(), $this->p, $open['action_id'], ['operation' => 'action.reopen', 'expected_revision' => 1, 'action' => $this->actionInput()], 'illegal');
            $this->fail('Open state cannot reopen.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
        $revise = $service->event($this->actor(), $this->p, $open['action_id'], ['operation' => 'action.revise', 'expected_revision' => 1, 'action' => $this->actionInput()], 'revise');
        $this->assertNotSame($open['action_version_id'], $revise['action_version_id']);
        $service->event($this->actor(), $this->p, $open['action_id'], ['operation' => 'action.withdraw', 'expected_revision' => 2, 'reason' => 'Synthetic withdrawal'], 'withdraw');
        $reopen = $service->event($this->actor(), $this->p, $open['action_id'], ['operation' => 'action.reopen', 'expected_revision' => 3, 'action' => $this->actionInput()], 'reopen');
        $this->assertSame(4, $reopen['revision']);
        app(TenantContext::class)->run($this->tenant, function () use ($open) {
            $root = DB::table('management_actions')->where('id', $open['action_id'])->first();
            $this->assertSame(3, $root->episode);
            $this->assertSame('open', $root->state);
            $this->assertNull($root->current_assignment_id);
        });
    }

    public function test_role_title_without_exact_project_purpose_cannot_open(): void
    {
        $this->managementInputs();
        $pm = $this->managementActor('pm');
        $this->expectException(AccessDeniedHttpException::class);
        app(ManagementActionService::class)->open($pm, $this->p, $this->managementEnvelope('action.open', $this->actionInput()), 'denied', 0);
    }

    public function test_assigned_helper_can_add_text_only_context(): void
    {
        $f = $this->workflowAssigned();
        $context = app(ManagementActionService::class)->event($f['helper'], $this->p, $f['open']['action_id'], ['operation' => 'context.add', 'expected_revision' => 2, 'context' => 'Synthetic helper context'], 'helper-context');
        $this->assertSame(3, $context['revision']);
        app(TenantContext::class)->run($this->tenant, function () use ($f) {
            $root = DB::table('management_actions')->where('id', $f['open']['action_id'])->first();
            $this->assertSame('assigned', $root->state);
            $this->assertSame($f['assignment']['assignment_id'], $root->current_assignment_id);
        });
    }
}
