<?php

namespace Tests\Feature;

use App\Domain\Access\TrustedActor;
use App\Domain\Ba\BaReadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BaTestCase;

class BaAccessTest extends BaTestCase
{
    public function test_cursor_is_query_bound_and_next_page_rechecks_revocation(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->openRequest(null, 'first');
        $this->openRequest(null, 'second');
        $service = app(BaReadService::class);
        $first = $service->queue($this->actor(), $this->p, ['limit' => 1]);
        $second = $service->queue($this->actor(), $this->p, ['limit' => 1, 'cursor' => $first['next_cursor']]);
        $this->assertCount(1, $second['items']);
        $this->assertNotSame($first['items'][0]['clarification_id'], $second['items'][0]['clarification_id']);
        try {
            $service->queue($this->actor(), $this->p, ['limit' => 2, 'cursor' => $first['next_cursor']]);
            $this->fail('Cursor limit mismatch accepted.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->baRun(fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'ba.read')->update(['revoked_at' => now()]));
        $this->assertBaDenied(fn () => $service->queue($this->actor(), $this->p, ['limit' => 1, 'cursor' => $first['next_cursor']]));
    }

    public function test_hidden_context_assignee_suppresses_whole_root_before_limit(): void
    {
        $this->baInputs();
        $this->baRole();
        $other = $this->pmActor();
        $employee = $this->baRun(fn () => DB::table('users')->where('id', $other->id)->value('employee_id'));
        $body = $this->requestInput();
        $task = $this->baTask(0, $employee);
        $body['links'][] = ['task_version_id' => $task['version_id'], 'criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id'], 'relation' => 'context'];
        $hidden = $this->openRequest($body, 'hidden');
        $healthy = $this->openRequest(null, 'healthy');
        $this->baRun(fn () => DB::table('project_memberships')->where('employee_id', $employee)->where('project_id', $this->p)->update(['valid_to' => now()->subSecond()]));
        $page = app(BaReadService::class)->queue($this->actor(), $this->p, ['limit' => 1]);
        $this->assertCount(1, $page['items']);
        $this->assertSame($healthy['clarification_id'], $page['items'][0]['clarification_id']);
        $this->assertStringNotContainsString($hidden['clarification_id'], json_encode($page));
        $history = app(BaReadService::class)->history($this->actor(), $this->p, $hidden['clarification_id']);
        $this->assertSame([], $history['items']);
        $this->assertNull($history['next_cursor']);
    }

    public function test_current_source_purpose_is_required_even_for_empty_page(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->baRun(fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'ba.source.read')->update(['revoked_at' => now()]));
        $this->assertBaDenied(fn () => app(BaReadService::class)->queue($this->actor(), $this->p));
    }

    public function test_department_requires_role_purpose_and_every_named_membership(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->openRequest();
        $department = (string) Str::uuid();
        $this->baRun(function () use ($department) {
            DB::table('departments')->insert(['id' => $department, 'tenant_id' => $this->tenant, 'name' => 'Synthetic department']);
            DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'employee_id' => $this->employee, 'valid_from' => now()->subDay()]);
        });
        $this->grant('ba.department.read', null, $department);
        $this->assertBaDenied(fn () => app(BaReadService::class)->queue($this->actor(), $this->p, ['department_id' => $department]));
        $this->baRole('department_head');
        $this->assertCount(1, app(BaReadService::class)->queue($this->actor(), $this->p, ['department_id' => $department])['items']);
        $other = $this->pmActor();
        $employee = $this->baRun(fn () => DB::table('users')->where('id', $other->id)->value('employee_id'));
        $body = $this->requestInput();
        $body['requester_id'] = $employee;
        $this->openRequest($body, 'other-department');
        $this->assertCount(1, app(BaReadService::class)->queue($this->actor(), $this->p, ['department_id' => $department])['items']);
    }

    public function test_cross_project_and_tenant_are_denied(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->openRequest();
        $this->assertBaDenied(fn () => app(BaReadService::class)->queue($this->actor(), $this->project()));
        $this->expectException(HttpException::class);
        app(BaReadService::class)->queue(new TrustedActor($this->user, (string) Str::uuid()), $this->p);
    }
}
