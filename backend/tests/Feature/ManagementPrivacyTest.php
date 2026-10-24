<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementActionService;
use App\Domain\Management\ManagementPins;
use App\Domain\Management\ManagementReadEligibility;
use App\Domain\Management\ManagementReadService;
use App\Support\LocalManagementDatabase;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\ManagementTestCase;

final class ManagementPrivacyTest extends ManagementTestCase
{
    public function test_sql_admission_requires_the_matching_closed_authority_fence(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $this->expectException(\LogicException::class);
        app(TenantContext::class)->run($this->tenant, fn () => app(ManagementReadEligibility::class)->selectLocked($this->actor(), $this->p, [], [], null, 20));
    }

    public function test_hidden_current_membership_does_not_observe_original_invalidity(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $owner = $this->managementActor('developer');
        $ownerId = app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $owner->id)->value('employee_id'));
        $body = array_replace($this->actionInput(), ['owner_id' => $ownerId]);
        $open = app(ManagementActionService::class)->open($this->actor(), $this->p, $this->managementEnvelope('action.open', $body, 'private-owner'), 'private-owner', 0);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('project_id', $this->p)->where('employee_id', $ownerId)->update(['valid_to' => now()]));
        $hidden = app(ManagementReadService::class)->history($this->actor(), $this->p, $open['action_id']);
        $this->assertSame([], $hidden['items']);
        $this->assertSame(0, $hidden['visible_record_count']);
        $this->assertArrayNotHasKey('action_id', $hidden);
        $this->assertNull($hidden['next_cursor']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('project_id', $this->p)->where('employee_id', $ownerId)->update(['valid_to' => null]));
        $restored = app(ManagementReadService::class)->queue($this->actor(), $this->p);
        $this->assertCount(1, $restored['items']);
        $this->assertSame(1, $restored['visible_record_count']);
    }

    public function test_observed_original_purpose_revocation_survives_exact_restoration(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $open = app(ManagementActionService::class)->open($this->actor(), $this->p, $this->managementEnvelope('action.open', $this->actionInput(), 'observed-original'), 'observed-original', 0);
        $grant = app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'management.action.manage')->value('id'));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
        $hidden = app(ManagementReadService::class)->history($this->actor(), $this->p, $open['action_id']);
        $this->assertSame([], $hidden['items']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => null]));
        $this->assertSame([], app(ManagementReadService::class)->queue($this->actor(), $this->p)['items']);
    }

    public function test_expiry_at_final_clock_never_returns_a_half_page(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'management.read')->update(['valid_to' => CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->addSeconds(3)]));
        $this->seedManagementBoundary();
        $active = true;
        $waited = false;
        DB::listen(function ($query) use (&$active, &$waited) {
            if ($active && ! $waited && $query->sql === "select set_config('app.management_required_keys',?,true)") {
                $waited = true;
                usleep(3200000);
            }
        });
        try {
            app(ManagementReadService::class)->queue($this->actor(), $this->p);
            $this->fail('Final expiry must withhold the entire selected result.');
        } catch (AccessDeniedHttpException) {
            $this->assertTrue($waited, 'Expiry must occur after delivery selection.');
        } finally {
            $active = false;
        }
    }

    public function test_every_current_source_purpose_is_fenced_at_the_final_clock(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $this->seedManagementBoundary();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'requirements.read')->update(['valid_to' => CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->addSeconds(3)]));
        $active = true;
        $selected = false;
        $waited = false;
        DB::listen(function ($query) use (&$active, &$selected, &$waited) {
            if (! $active) {
                return;
            }
            if ($query->sql === "select set_config('app.management_required_keys',?,true)") {
                $selected = true;
            }
            if ($selected && ! $waited && $query->sql === "select current_setting('app.management_required_keys')") {
                $waited = true;
                usleep(3200000);
            }
        });
        try {
            app(ManagementReadService::class)->queue($this->actor(), $this->p);
            $this->fail('Source-purpose expiry after the final SQL predicate must still withhold the page.');
        } catch (AccessDeniedHttpException) {
            $this->assertTrue($waited);
        } finally {
            $active = false;
        }
    }

    public function test_hidden_optional_old_review_does_not_poison_fresh_current_projection(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $old = $this->seedManagementConfirmation($this->seedManagementBoundary(exactSource: true));
        $oldReviewer = app(TenantContext::class)->run($this->tenant, fn () => DB::table('management_reviews')->where('id', $old['review'])->value('reviewer_id'));
        $new = $this->seedManagementReassignment($old);
        $new = $this->seedManagementSourceSubject($new, 1, object: 'fresh-series');
        $new = $this->seedManagementConfirmation($new);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('project_id', $this->p)->where('employee_id', $oldReviewer)->update(['valid_to' => now()]));
        $page = app(ManagementReadService::class)->queue($this->actor(), $this->p);
        $this->assertSame('complete', $page['coverage_status']);
        $this->assertCount(1, $page['items']);
        $this->assertSame($new['support'], $page['items'][0]['subject_support']['id']);
        $this->assertSame('partial', $page['items'][0]['current_outcome']);
    }

    public function test_candidate_cap_cannot_publish_an_unproved_short_prefix(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $private = $this->managementActor('developer');
        $employee = app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $private->id)->value('employee_id'));
        for ($i = 0; $i < 20; $i++) {
            $this->seedManagementBoundary(object: 'private-'.$i, helper: $employee);
        }
        $this->seedManagementBoundary(object: 'after-probe');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('project_id', $this->p)->where('employee_id', $employee)->update(['valid_to' => now()]));
        $page = app(ManagementReadService::class)->queue($this->actor(), $this->p, ['limit' => 1]);
        $this->assertSame([], $page['items']);
        $this->assertSame('unknown', $page['coverage_status']);
        $this->assertNull($page['visible_record_count']);
        $this->assertNull($page['next_cursor']);
        $this->assertArrayNotHasKey('scanned_count', $page);
        $this->assertArrayNotHasKey('position', $page);
    }

    public function test_source_revocation_between_pages_withholds_the_record(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $this->seedManagementBoundary(object: 'page-one');
        $this->seedManagementBoundary(object: 'page-two');
        $first = app(ManagementReadService::class)->queue($this->actor(), $this->p, ['limit' => 1]);
        $this->assertCount(1, $first['items']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_connectors')->where('project_id', $this->p)->where('source_instance_id', $this->managementSource)->update(['status' => 'disabled', 'revision' => DB::raw('revision+1')]));
        $second = app(ManagementReadService::class)->queue($this->actor(), $this->p, ['limit' => 1, 'cursor' => $first['next_cursor']]);
        $this->assertSame([], $second['items']);
        $this->assertNull($second['next_cursor']);
        $this->assertSame(0, $second['visible_record_count']);
    }

    public function test_current_actor_mapping_reuse_is_exact_and_reference_local(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $ba = $this->managementBaFixture();
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'ba_request_version', 'ba_request_version_id' => $ba['request_version_id']]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref, $ref]);
        $eligibility = app(ManagementReadEligibility::class);
        $mappings = $eligibility->actorMappings($graph['references'][0], $graph);
        $this->assertCount(2, $mappings);
        $this->assertCount(2, $eligibility->actorMappings($graph['references'][1], $graph));
        $slotIndex = array_search('ba_request_versions', array_column($graph['proof_slots'], 'subject_kind'), true);
        $this->assertNotFalse($slotIndex);
        foreach (['revision', 'valid_from', 'valid_to', 'department', 'roles', 'format'] as $difference) {
            $changed = $graph;
            $proof = &$changed['proof_slots'][$slotIndex]['pinned_proof'];
            if ($difference === 'revision') {
                $proof['membership']['revision']++;
            } elseif ($difference === 'department') {
                $proof['department_id'] = (string) Str::uuid();
            } elseif ($difference === 'roles') {
                $proof['roles'][0]['revision']++;
            } elseif ($difference === 'format') {
                $changed['proof_slots'][$slotIndex]['format'] = 'qa-authority-v1';
            } else {
                $proof['membership'][$difference] = '2026-10-12T00:00:00.000000Z';
            }
            unset($proof);
            $this->assertCount(3, $eligibility->actorMappings($changed['references'][0], $changed), $difference);
        }
    }

    public function test_current_department_does_not_replace_the_saved_original_department(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $fixture = $this->workflowAssigned();
        $department = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($fixture, $department) {
            DB::table('departments')->insert(['id' => $department, 'tenant_id' => $this->tenant, 'name' => 'Synthetic current read department']);
            foreach ([$this->employee, DB::table('users')->where('id', $fixture['head']->id)->value('employee_id'), DB::table('users')->where('id', $fixture['helper']->id)->value('employee_id')] as $employee) {
                DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
            }
        });
        $this->managementGrantActor($fixture['head'], ['management.read', 'management.department.read'], $department);
        $page = app(ManagementReadService::class)->queue($fixture['head'], $this->p, ['department_id' => $department]);
        $this->assertCount(1, $page['items']);
        $headId = app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $fixture['head']->id)->value('employee_id'));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('department_memberships')->where('department_id', $fixture['department'])->where('employee_id', $headId)->update(['valid_to' => now()]));
        $hidden = app(ManagementReadService::class)->queue($fixture['head'], $this->p, ['department_id' => $department]);
        $this->assertSame([], $hidden['items']);
        $this->assertSame(0, $hidden['visible_record_count']);
    }

    public function test_hidden_people_before_middle_and_after_do_not_consume_public_limit(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $hidden = $this->managementActor('developer');
        $employee = app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $hidden->id)->value('employee_id'));
        $a = $this->seedManagementBoundary(object: 'hidden-before', helper: $employee);
        $b = $this->seedManagementBoundary(object: 'visible-first');
        $c = $this->seedManagementBoundary(object: 'hidden-middle', helper: $employee);
        $d = $this->seedManagementBoundary(object: 'visible-second');
        $e = $this->seedManagementBoundary(object: 'hidden-after', helper: $employee);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('project_id', $this->p)->where('employee_id', $employee)->update(['valid_to' => now()]));
        $page = app(ManagementReadService::class)->queue($this->actor(), $this->p, ['limit' => 2]);
        $this->assertSame([$b['action'], $d['action']], array_column($page['items'], 'action_id'));
        $this->assertSame(2, $page['visible_record_count']);
        $this->assertSame('complete', $page['coverage_status']);
        $this->assertNotNull($page['next_cursor']);
        foreach ([$a, $c, $e] as $private) {
            $this->assertStringNotContainsString($private['action'], json_encode($page, JSON_THROW_ON_ERROR));
        }
        $continuation = app(ManagementReadService::class)->queue($this->actor(), $this->p, ['limit' => 2, 'cursor' => $page['next_cursor']]);
        $this->assertSame([], $continuation['items']);
        $this->assertSame(0, $continuation['visible_record_count']);
    }

    public function test_empty_page_does_not_skip_current_authorization(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'management.read')->update(['revoked_at' => now()]));
        $this->expectException(AccessDeniedHttpException::class);
        app(ManagementReadService::class)->queue($this->actor(), $this->p);
    }
}
