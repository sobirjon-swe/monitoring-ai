<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementCoordination;
use App\Domain\Management\ManagementPins;
use App\Domain\Management\ManagementReadEligibility;
use App\Domain\Management\ManagementReadService;
use App\Domain\Management\ManagementReviewService;
use App\Domain\Management\ManagementSupportService;
use App\Support\LocalManagementDatabase;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\ManagementTestCase;

final class ManagementReadBudgetTest extends ManagementTestCase
{
    public static function actorMappingCaps(): array
    {
        return [[3, 8, 'complete'], [4, 9, 'unknown']];
    }

    #[DataProvider('actorMappingCaps')]
    public function test_real_source_actor_named_cap_and_cap_plus_one(int $definitions, int $named, string $coverage): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $actors = [];
        for ($i = 0; $i < $definitions; $i++) {
            $producer = $this->managementActor('qa');
            $reviewer = $i === 3 ? $producer : $this->managementActor('qa');
            foreach (array_column([$producer, $reviewer], null, 'id') as $actor) {
                $this->managementGrantActor($actor, ['qa.fixture.manage', 'qa.plan.manage', 'qa.source.read']);
            }
            $actors[] = compact('producer', 'reviewer');
        }
        $qa = $this->managementQaExecutionFixture($definitions, $actors);
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_execution_version', 'qa_execution_version_id' => $qa['execution']['record_id']]);
        $ids = $this->seedManagementBoundary([$ref], [$ref], exactSource: true);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$ids['action']]);
        $this->assertSame('complete', $graph['coverage'], 'This is an actual admitted source/proof graph before the independent current named cap.');
        $certificate = app(ManagementReadEligibility::class)->certificates($graph)[0];
        $this->assertSame([$named, $named], $certificate['reference_named_counts']);
        $page = app(ManagementReadService::class)->queue($this->actor(), $this->p);
        $this->assertSame($coverage, $page['coverage_status']);
        if ($coverage === 'complete') {
            $this->assertCount(1, $page['items']);
        } else {
            $this->assertSame([], $page['items']);
            $this->assertNull($page['visible_record_count']);
            $this->assertNull($page['next_cursor']);
        }
        file_put_contents(base_path('../.local/management-t4-real-actor-cap-'.$named.'.json'), json_encode(['definitions' => $definitions, 'budget' => $graph['budget'], 'per_reference_named_counts' => $certificate['reference_named_counts'], 'actor_mapping_charge' => $certificate['actor_mapping_charge'], 'coverage' => $page['coverage_status']], JSON_THROW_ON_ERROR));
    }

    public function test_fifty_reviewed_boundaries_overflow_before_original_proof_materialization(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = $this->seedManagementConfirmation($this->seedManagementBoundary(object: 'reviewed-'.$i))['action'];
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, $ids);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame('unknown', $graph['coverage']);
        $this->assertSame(450, $graph['budget']['management_proofs']);
        foreach ($queries as $query) {
            $this->assertFalse(str_contains($query['query'], 'jsonb_build_object') && str_contains($query['query'], '"management_'), 'Over-budget originals must not be materialized.');
        }
        $active = true;
        $revoked = false;
        DB::listen(function ($query) use (&$active, &$revoked) {
            if ($active && str_starts_with($query->sql, 'WITH selected AS')) {
                $active = false;
                $revoked = true;
                DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'management.read')->update(['revoked_at' => now()]);
            }
        });
        try {
            app(ManagementReadService::class)->queue($this->actor(), $this->p, ['limit' => 50]);
            $this->fail('Coverage overflow must still recheck caller authorization before returning.');
        } catch (AccessDeniedHttpException) {
            $this->assertTrue($revoked);
        } finally {
            $active = false;
        }
    }

    public function test_ordinary_reviewed_boundary_retains_actor_people_and_source_slots(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $fixture = $this->workflowAssigned();
        $support = app(ManagementSupportService::class)->record($fixture['helper'], $this->p, $fixture['open']['action_id'], $this->managementEnvelope('support.record', $this->workflowSupportBody($fixture), 'ordinary-support'), 'ordinary-support', 0);
        $this->grant('management.outcome.review', $this->p);
        app(ManagementReviewService::class)->review($this->actor(), $this->p, $fixture['open']['action_id'], $this->workflowReviewBody($support), 'ordinary-review');
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$fixture['open']['action_id']]);
        $this->assertSame('complete', $graph['coverage']);
        $certificates = app(ManagementReadEligibility::class)->certificates($graph);
        $actors = array_unique(array_column(array_column($graph['proof_slots'], 'pinned_proof'), 'employee_id'));
        $this->assertSame([], array_diff($actors, $certificates[0]['employee_ids']));
        $this->assertContains($this->managementSource, $certificates[0]['source_ids']);
        $nested = 0;
        foreach ($graph['proof_slots'] as $slot) {
            foreach (['named', 'coordinators', 'request_subjects'] as $field) {
                $nested += count($slot['pinned_proof'][$field] ?? []);
            }
        }
        $evidence = ['budget' => $graph['budget'], 'direct_named_occurrences' => count($graph['named_slots']), 'nested_original_named_occurrences' => $nested, 'distinct_original_actor_employees' => count($actors), 'distinct_current_named_subjects' => count($certificates[0]['employee_ids']), 'current_source_instances' => count($certificates[0]['source_ids'])];
        $evidence['canonical_boundary_named_occurrences'] = $certificates[0]['boundary_named_occurrences'];
        $evidence['management_boundary_direct_occurrences'] = 7;
        $evidence['lifecycle_reference_named_occurrences'] = 2;
        $evidence['additional_assignment_actor_occurrences'] = 1;
        $evidence['read_named_charge'] = $graph['budget']['named_slots'] + count(array_filter($certificates[0]['named_occurrences'], fn ($n) => $n['role'] === 'actor'));
        $this->assertSame(21, $evidence['read_named_charge']);
        $this->assertSame(8, $certificates[0]['boundary_named_occurrences']);
        file_put_contents(base_path('../.local/management-t4-ordinary-slot-accounting.json'), json_encode($evidence, JSON_THROW_ON_ERROR));
        $page = app(ManagementReadService::class)->queue($this->actor(), $this->p);
        $this->assertSame('complete', $page['coverage_status']);
        $this->assertCount(1, $page['items']);
        $this->assertSame('completed', $page['items'][0]['current_outcome']);
        foreach (['receipt', 'role', 'proof-origin'] as $badAlias) {
            $changed = $graph;
            $event = $graph['boundaries'][0]['event_id'];
            $supportId = $graph['boundaries'][0]['support_id'];
            $fact = &$changed['boundary_origins'][$event]['facts']['management_support_versions'][$supportId];
            if ($badAlias === 'receipt') {
                $fact['receipt_id'] = (string) Str::uuid();
            } elseif ($badAlias === 'role') {
                $fact['recorder_id'] = $this->employee;
            } else {
                $fact['authority_proof']['membership']['id'] = (string) Str::uuid();
            }
            unset($fact);
            $certificate = app(ManagementReadEligibility::class)->certificates($changed)[0];
            $this->assertGreaterThan(8, $certificate['boundary_named_occurrences'], $badAlias.' must not share the captured recorder-origin charge.');
        }
    }

    public function test_equal_shape_one_twenty_and_fifty_read_graphs_have_bounded_query_growth(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $profiles = [];
        foreach ([1, 20, 50] as $count) {
            $this->tenant = (string) Str::uuid();
            $this->user = (string) Str::uuid();
            $this->employee = (string) Str::uuid();
            $this->policy = (string) Str::uuid();
            $this->baselineId = null;
            $this->managementInputs();
            for ($i = 0; $i < $count; $i++) {
                $this->seedManagementBoundary(object: 'shape-'.$i);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            memory_reset_peak_usage();
            $memory = memory_get_usage(true);
            $start = hrtime(true);
            $page = app(ManagementReadService::class)->queue($this->actor(), $this->p, ['limit' => 50]);
            $elapsed = (hrtime(true) - $start) / 1000000;
            $queries = count(DB::getQueryLog());
            $peak = max(0, memory_get_peak_usage(true) - $memory);
            DB::disableQueryLog();
            $profiles[] = ['boundaries' => $count, 'queries' => $queries, 'elapsed_ms' => round($elapsed, 3), 'peak_delta_bytes' => $peak, 'coverage' => $page['coverage_status'], 'delivered' => count($page['items'])];
            $this->assertSame('complete', $page['coverage_status']);
            $this->assertCount($count, $page['items']);
        }
        file_put_contents(base_path('../.local/management-t4-equal-shape-profile.json'), json_encode($profiles, JSON_THROW_ON_ERROR));
        $this->assertSame($profiles[0]['queries'], $profiles[1]['queries']);
        $this->assertSame($profiles[0]['queries'], $profiles[2]['queries']);
    }

    public function test_fixed_root_reference_and_source_depth_profiles_are_finite(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $profiles = [];
        foreach (['references' => [1, 5, 20], 'definitions' => [1, 3, 5]] as $shape => $sizes) {
            foreach ($sizes as $size) {
                $this->tenant = (string) Str::uuid();
                $this->user = (string) Str::uuid();
                $this->employee = (string) Str::uuid();
                $this->policy = (string) Str::uuid();
                $this->baselineId = null;
                $this->managementInputs();
                $refs = [];
                if ($shape === 'references') {
                    for ($i = 0; $i < $size; $i++) {
                        $handoff = $this->managementQaHandoff('profile-'.$i, configure: $i === 0);
                        $event = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->where('id', $handoff['id'])->value('current_event_id'));
                        $refs[] = array_replace($this->lifecycleReference(), ['kind' => 'qa_handoff_event', 'qa_handoff_event_id' => $event]);
                    }
                } else {
                    $qa = $this->managementQaExecutionFixture($size);
                    $refs[] = array_replace($this->lifecycleReference(), ['kind' => 'qa_execution_version', 'qa_execution_version_id' => $qa['execution']['record_id']]);
                }
                $ids = $this->seedManagementBoundary($refs, $refs, exactSource: true);
                $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$ids['action']]);
                $this->assertSame('complete', $graph['coverage']);
                DB::flushQueryLog();
                DB::enableQueryLog();
                memory_reset_peak_usage();
                $memory = memory_get_usage(true);
                $start = hrtime(true);
                $page = app(ManagementReadService::class)->queue($this->actor(), $this->p);
                $elapsed = (hrtime(true) - $start) / 1000000;
                $queries = count(DB::getQueryLog());
                DB::disableQueryLog();
                $profiles[] = ['shape' => $shape, 'size' => $size, 'budget' => $graph['budget'], 'queries' => $queries, 'elapsed_ms' => round($elapsed, 3), 'peak_delta_bytes' => max(0, memory_get_peak_usage(true) - $memory), 'coverage' => $page['coverage_status'], 'delivered' => count($page['items'])];
                $this->assertSame('complete', $page['coverage_status']);
                $this->assertCount(1, $page['items']);
            }
        }
        file_put_contents(base_path('../.local/management-t4-fixed-root-profile.json'), json_encode($profiles, JSON_THROW_ON_ERROR));
        $this->assertSame($profiles[0]['queries'], $profiles[1]['queries']);
        $this->assertSame($profiles[0]['queries'], $profiles[2]['queries']);
    }

    public function test_equal_shape_heads_do_not_add_one_reload_per_root(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $one = $this->seedManagementBoundary(object: 'one');
        $two = $this->seedManagementBoundary(object: 'two');
        $counts = [];
        foreach ([[$one['action']], [$one['action'], $two['action']]] as $ids) {
            $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, $ids);
            $this->assertSame('complete', $graph['coverage']);
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, fn () => null);
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertSame($counts[0], $counts[1], 'Equal-shape captured heads must reload in fixed table batches.');
    }
}
