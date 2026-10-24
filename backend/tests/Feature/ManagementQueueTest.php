<?php

namespace Tests\Feature;

use App\Domain\Access\TrustedActor;
use App\Domain\Lifecycle\BaselineService;
use App\Domain\Management\ManagementPagination;
use App\Domain\Management\ManagementPins;
use App\Domain\Management\ManagementReadEligibility;
use App\Domain\Management\ManagementReadService;
use App\Support\LocalManagementDatabase;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\ManagementTestCase;

final class ManagementQueueTest extends ManagementTestCase
{
    public function test_cursor_rejects_tampering_and_every_changed_scope_binding(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $pagination = app(ManagementPagination::class);
        $position = ['opened_at' => '2026-10-13T00:00:00.000000Z', 'id' => (string) Str::uuid()];
        $cursor = $pagination->encode($this->actor(), $this->p, 'queue', null, ['limit' => 20], 7, $position);
        $cases = [
            [$this->actor(), $this->p, 'queue', null, ['limit' => 20, 'cursor' => $cursor.'x'], 7],
            [new TrustedActor((string) Str::uuid(), $this->tenant), $this->p, 'queue', null, ['limit' => 20, 'cursor' => $cursor], 7],
            [$this->actor(), (string) Str::uuid(), 'queue', null, ['limit' => 20, 'cursor' => $cursor], 7],
            [$this->actor(), $this->p, 'history', (string) Str::uuid(), ['limit' => 20, 'cursor' => $cursor], 7],
            [$this->actor(), $this->p, 'queue', null, ['limit' => 20, 'department_id' => (string) Str::uuid(), 'cursor' => $cursor], 7],
            [$this->actor(), $this->p, 'queue', null, ['limit' => 50, 'cursor' => $cursor], 7],
            [$this->actor(), $this->p, 'queue', null, ['limit' => 20, 'cursor' => $cursor], 8],
        ];
        foreach ($cases as $case) {
            try {
                $pagination->decode(...$case);
                $this->fail('Changed cursor scope must be rejected.');
            } catch (ConflictHttpException) {
                $this->addToAssertionCount(1);
            }
        }
        $history = $pagination->encode($this->actor(), $this->p, 'history', $this->p, ['limit' => 20], 7, ['revision' => 1, 'id' => (string) Str::uuid()]);
        $this->expectException(ConflictHttpException::class);
        $pagination->decode($this->actor(), $this->p, 'history', (string) Str::uuid(), ['limit' => 20, 'cursor' => $history], 7);
    }

    public function test_limits_twenty_and_fifty_and_strict_query_bounds(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $pagination = app(ManagementPagination::class);
        $this->assertSame(20, $pagination->query([])['limit']);
        $this->assertSame(50, $pagination->query(['limit' => '50'])['limit']);
        foreach ([['limit' => 51], ['limit' => 0], ['limit' => false], ['limit' => 1.0], ['limit' => '01'], ['limit' => []], ['offset' => 1], ['cursor' => str_repeat('x', 4097)]] as $query) {
            try {
                $pagination->query($query);
                $this->fail('Invalid management query must be rejected.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_closed_queue_authorizes_first_and_history_remains_available(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $ids = $this->seedManagementBoundary();
        app(BaselineService::class)->close($this->actor(), $this->p, $this->generation, 'Synthetic closed project');
        $queue = app(ManagementReadService::class)->queue($this->actor(), $this->p);
        $this->assertSame('unavailable', $queue['coverage_status']);
        $this->assertSame(['project_closed'], $queue['reasons']);
        $history = app(ManagementReadService::class)->history($this->actor(), $this->p, $ids['action']);
        $this->assertSame('complete', $history['coverage_status']);
        $this->assertCount(3, $history['items']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'management.read')->update(['revoked_at' => now()]));
        $this->expectException(AccessDeniedHttpException::class);
        app(ManagementReadService::class)->queue($this->actor(), $this->p);
    }

    public function test_all_seven_typed_sources_are_read_without_source_writes(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $qa = $this->managementQaExecutionFixture();
        $this->grant('qa.handoff.manage', $this->p);
        $handoff = $this->managementQaHandoff(configure: false);
        $ba = $this->managementBaReviewedFixture();
        $handoffEvent = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->where('id', $handoff['id'])->value('current_event_id'));
        $subjects = ['lifecycle' => null, 'qa_handoff_event' => $handoffEvent, 'qa_defect_event' => $qa['defectEvent'], 'qa_execution_version' => $qa['execution']['record_id'], 'ba_request_version' => $ba['request']['request_version_id'], 'ba_answer_version' => $ba['answer']['answer_version_id'], 'ba_review' => $ba['review']['review_id']];
        $ids = [];
        foreach ($subjects as $kind => $subject) {
            $ref = array_replace($this->lifecycleReference(), ['kind' => $kind]);
            if ($subject !== null) {
                $ref[$kind.'_id'] = $subject;
            }
            $ids[] = $this->seedManagementBoundary([$ref], [$ref], object: 'seven-'.$kind, exactSource: true)['action'];
        }
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, $ids);
        $certificates = app(ManagementReadEligibility::class)->certificates($graph);
        $mappingProfile = [];
        foreach ($certificates as $i => $certificate) {
            $mappingProfile[] = ['kind' => array_keys($subjects)[$i], 'actor_mapping_occurrences' => $certificate['actor_mapping_charge'], 'per_reference_named_counts' => $certificate['reference_named_counts'], 'boundary_named_occurrences' => $certificate['boundary_named_occurrences']];
            foreach ($certificate['reference_named_counts'] as $named) {
                $this->assertLessThanOrEqual(8, $named);
            }
        }
        file_put_contents(base_path('../.local/management-t4-seven-source-mapping-profile.json'), json_encode(['budget' => $graph['budget'], 'sources' => $mappingProfile], JSON_THROW_ON_ERROR));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = app(ManagementReadService::class)->queue($this->actor(), $this->p);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $actualKinds = [];
        foreach ($ids as $i => $id) {
            if (in_array($id, array_column($page['items'], 'action_id'), true)) {
                $actualKinds[] = array_keys($subjects)[$i];
            }
        }
        file_put_contents(base_path('../.local/management-t4-seven-source-diagnostic.json'), json_encode(['delivered_kinds' => $actualKinds, 'coverage' => $page['coverage_status']], JSON_THROW_ON_ERROR));
        $this->assertSame('complete', $page['coverage_status']);
        $this->assertSame($ids, array_column($page['items'], 'action_id'));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|truncate)\s+(?:into\s+)?"?(?:qa_|ba_|task_|requirement|criterion|lifecycle)/i', $query['query']);
        }
    }

    public function test_cursor_binds_exact_delivery_and_management_generation(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $pagination = app(ManagementPagination::class);
        $last = ['opened_at' => '2026-10-13T00:00:00.000000Z', 'id' => (string) Str::uuid()];
        $cursor = $pagination->encode($this->actor(), $this->p, 'queue', null, ['limit' => 20], 7, $last);
        $this->assertLessThanOrEqual(4096, strlen($cursor));
        $this->assertSame($last, $pagination->decode($this->actor(), $this->p, 'queue', null, ['limit' => 20, 'cursor' => $cursor], 7));
        $this->expectException(ConflictHttpException::class);
        $pagination->decode($this->actor(), $this->p, 'queue', null, ['limit' => 20, 'cursor' => $cursor], 8);
    }

    public function test_empty_queue_is_authorized_and_synthetic(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $page = app(ManagementReadService::class)->queue($this->actor(), $this->p);
        $this->assertSame([], $page['items']);
        $this->assertSame(0, $page['visible_record_count']);
        $this->assertNull($page['next_cursor']);
        $this->assertSame('complete', $page['coverage_status']);
        $this->assertTrue($page['synthetic']);
        $this->assertSame('unavailable', $page['ai_interpretation']);
    }
}
