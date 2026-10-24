<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementCoordination;
use App\Domain\Management\ManagementPins;
use App\Domain\Qa\QaMaintenanceProcessor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\ManagementTestCase;

final class ManagementBudgetTest extends ManagementTestCase
{
    public function test_late_distinct_subject_exceeds_ten_management_originals_without_truncation(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementSourceSubject($this->seedManagementBoundary(), 3);
        $ids = $this->seedManagementSourceSubject($ids, 1, object: 'independent-complete');
        $ids = $this->seedManagementConfirmation($ids);
        $this->seedManagementSourceSubject($ids, 2, late: true);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$ids['action']]);
        $this->assertSame(11, $graph['budget']['management_proofs']);
        $this->assertSame('unknown', $graph['coverage']);
        $this->assertSame('coverage_limit', $graph['reason_code']);
        $this->assertSame([], $graph['proof_slots']);
    }

    public function test_review_and_reviewed_context_keep_every_human_receipt_original(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementConfirmation($this->seedManagementBoundary());
        $review = app(ManagementPins::class)->collect($this->actor(), $this->p, [$ids['action']]);
        $this->assertSame('complete', $review['coverage']);
        $this->assertSame(9, $review['budget']['management_proofs']);
        $this->assertCount(4, array_filter($review['proof_slots'], fn ($s) => $s['subject_kind'] === 'management_receipts'));
        $ids = $this->seedManagementContext($ids);
        $context = app(ManagementPins::class)->collect($this->actor(), $this->p, [$ids['action']]);
        $this->assertSame('complete', $context['coverage']);
        $this->assertSame(10, $context['budget']['management_proofs']);
        $this->assertCount(5, array_filter($context['proof_slots'], fn ($s) => $s['subject_kind'] === 'management_receipts'));
        $guard = new \ReflectionMethod(ManagementPins::class, 'admitted');
        $this->assertFalse($guard->invoke(app(ManagementPins::class), array_replace($context['budget'], ['management_proofs' => 11])));
        $this->assertSame('complete', app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $context, fn () => app(ManagementPins::class)->loadLocked($this->p, $context))['coverage']);
        $this->assertCount(2, $context['refs']['management_source_heads']);
        $id = $context['refs']['management_source_heads'][0];
        $context['heads']['management_source_heads'][$id]['revision'] = 0;
        $this->expectException(ConflictHttpException::class);
        app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $context, fn () => true);
    }

    public function test_nested_saved_names_are_charged_once_without_duplicate_explicit_slots(): void
    {
        $this->managementInputs();
        $ba = $this->managementBaFixture();
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'ba_request_version', 'ba_request_version_id' => $ba['request_version_id']]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->assertSame('complete', $graph['coverage']);
        $charge = count($graph['named_slots']);
        foreach ($graph['proof_slots'] as $slot) {
            foreach (['named', 'coordinators', 'request_subjects'] as $field) {
                $charge += count($slot['pinned_proof'][$field] ?? []);
            }
        }
        $this->assertSame($charge, $graph['budget']['named_slots']);
        $this->assertLessThanOrEqual(8, count($graph['named_slots']));
        $this->assertGreaterThan(count($graph['named_slots']), $charge);
        $this->assertTrue(app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, fn () => true));
    }

    public function test_independent_numeric_guards_reject_each_ceiling_plus_one(): void
    {
        // This verifies arithmetic guards, not admission of a source closure.
        $limits = ['references' => 2000, 'management_proofs' => 400, 'source_rows' => 4000, 'source_proofs' => 2300, 'named_slots' => 2000, 'responsibility_slots' => 1000, 'receipts' => 200, 'source_instances' => 200, 'connector_heads' => 200, 'original_actors' => 100];
        $guard = new \ReflectionMethod(ManagementPins::class, 'admitted');
        $base = array_fill_keys(array_keys($limits), 0) + ['boundaries' => 50];
        $base['references'] = 2000;
        foreach ($limits as $field => $limit) {
            $at = array_replace($base, [$field => $limit]);
            $this->assertTrue($guard->invoke(app(ManagementPins::class), $at), $field);
            $this->assertFalse($guard->invoke(app(ManagementPins::class), array_replace($at, [$field => $limit + 1])), $field);
        }
    }

    public static function complexQaCases(): array
    {
        return [[5, 'complete'], [6, 'unknown']];
    }

    #[DataProvider('complexQaCases')]
    public function test_complex_qa_closure_is_admitted_whole_or_unknown(int $definitions, string $coverage): void
    {
        $this->managementInputs();
        $fixture = $this->managementQaExecutionFixture($definitions);
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_execution_version', 'qa_execution_version_id' => $fixture['execution']['record_id']]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->assertSame($coverage, $graph['coverage']);
        if ($coverage === 'complete') {
            $this->assertSame(60, $graph['budget']['source_rows']);
            $this->assertCount(5, $graph['refs']['qa_plan_entry_definitions']);
        } else {
            $this->assertSame('coverage_limit', $graph['reason_code']);
            $this->assertSame([], $graph['references']);
        }
        $this->assertLessThanOrEqual(200, count(DB::getQueryLog()));
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|truncate)\s+(?:into\s+)?"?(?:qa_|ba_|task_|requirement|criterion|lifecycle)/i', $query['query']);
        }
        DB::disableQueryLog();
    }

    public function test_reference_twenty_one_is_unknown_before_deduplication(): void
    {
        $this->managementInputs();
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], array_fill(0, 21, $this->lifecycleReference()));
        $this->assertSame('unknown', $graph['coverage']);
        $this->assertSame('coverage_limit', $graph['reason_code']);
    }

    public function test_prospective_boundary_keeps_all_current_and_new_proofs(): void
    {
        $this->managementInputs();
        $boundary = $this->seedManagementBoundary();
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$boundary['action']], [$this->lifecycleReference()]);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertSame(2, $graph['budget']['boundaries']);
        $this->assertSame(3, $graph['budget']['references']);
        $this->assertContains($boundary['support'], $graph['refs']['management_support_versions']);
        $this->assertContains($boundary['assignment'], $graph['refs']['management_assignments']);
        $this->assertGreaterThanOrEqual(5, $graph['budget']['management_proofs']);
        $this->assertGreaterThanOrEqual(1, count($graph['responsibility_slots']));
    }

    public function test_retained_twenty_action_twenty_support_and_twenty_prospective_are_all_charged(): void
    {
        $this->managementInputs();
        $refs = [];
        for ($i = 0; $i < 40; $i++) {
            $handoff = $this->managementQaHandoff('bounded-'.$i, configure: $i === 0);
            $event = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->where('id', $handoff['id'])->value('current_event_id'));
            $refs[] = array_replace($this->lifecycleReference(), ['kind' => 'qa_handoff_event', 'qa_handoff_event_id' => $event]);
        }
        $boundary = $this->seedManagementBoundary(array_slice($refs, 0, 20), array_slice($refs, 20, 20));
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$boundary['action']], array_slice($refs, 0, 20));
        $this->assertSame('complete', $graph['coverage']);
        $this->assertSame(60, $graph['budget']['references']);
        $this->assertSame(120, $graph['budget']['source_proofs']);
        $this->assertCount(80, array_filter($graph['proof_slots'], fn ($s) => $s['format'] === 'qa-authority-v1'));
        $this->assertCount(40, $graph['refs']['qa_handoff_events']);
    }

    public function test_original_actor_one_hundred_and_one_is_unknown_without_other_cap_binding(): void
    {
        $this->managementInputs();
        $this->grant('qa.process', $this->p);
        $refs = [];
        for ($i = 0; $i < 101; $i++) {
            if ($i > 0 && $i % 30 === 0) {
                $processor = app(QaMaintenanceProcessor::class);
                for ($drain = 0; $drain < 300 && ($claim = $processor->claim($this->actor(), $this->p)); $drain++) {
                    $processor->processClaim($this->actor(), $this->p, $claim);
                }
            }
            $actor = $i === 0 ? $this->actor() : $this->managementActor('qa');
            if ($i > 0) {
                app(TenantContext::class)->run($this->tenant, function () use ($actor) {
                    foreach (['qa.handoff.manage', 'qa.source.read', 'qa.read'] as $purpose) {
                        DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $actor->id, 'policy_version_id' => $this->policy, 'purpose' => $purpose, 'scope_type' => 'project', 'valid_from' => now()->subDay()]);
                    }
                });
            }
            $handoff = $this->managementQaHandoff('actor-'.$i, $actor, $i === 0);
            $event = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->where('id', $handoff['id'])->value('current_event_id'));
            $refs[] = array_replace($this->lifecycleReference(), ['kind' => 'qa_handoff_event', 'qa_handoff_event_id' => $event]);
        }
        $actions = [];
        foreach ([0, 40, 80] as $i) {
            $boundary = $this->seedManagementBoundary(array_slice($refs, $i, 20), $i === 80 ? [] : array_slice($refs, $i + 20, 20), 'actor-boundary-'.$i);
            $actions[] = $boundary['action'];
        }
        $accepted = app(ManagementPins::class)->collect($this->actor(), $this->p, $actions);
        $this->assertSame('complete', $accepted['coverage']);
        $this->assertSame(100, $accepted['budget']['original_actors']);
        $overflow = app(ManagementPins::class)->collect($this->actor(), $this->p, $actions, [$refs[100]]);
        $this->assertSame('unknown', $overflow['coverage']);
        $this->assertSame(101, $overflow['budget']['original_actors']);
        $this->assertLessThan(4000, $overflow['budget']['source_rows']);
        $this->assertLessThan(2300, $overflow['budget']['source_proofs']);
        $this->assertLessThan(2000, $overflow['budget']['named_slots']);
    }
}
