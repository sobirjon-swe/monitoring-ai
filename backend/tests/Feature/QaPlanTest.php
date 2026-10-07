<?php

namespace Tests\Feature;

use App\Domain\DeveloperAnalysis\SyntheticMrRegistry;
use App\Domain\Qa\QaChangeWriter;
use App\Domain\Qa\QaDefinitionService;
use App\Domain\Qa\QaFixtureService;
use App\Domain\Qa\QaPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\Support\QaTestCase;

class QaPlanTest extends QaTestCase
{
    public function test_compact_maximum_plan_preserves_two_thousand_definitions_with_batched_queries(): void
    {
        $definitions = $this->qaMaxSizeInputs();
        $entries = [];
        foreach ($definitions as $index => $tests) {
            $input = $this->entryInput($index, $tests);
            $this->assertLessThanOrEqual(65536, strlen(json_encode($input, JSON_THROW_ON_ERROR)));
            $entries[] = $this->planEntry($index, $tests);
        }
        $this->completeSyntheticQaJobs();
        $compact = ['baseline_id' => $this->baselineId, 'entry_snapshot_ids' => array_column($entries, 'id')];
        $this->assertLessThanOrEqual(65536, strlen(json_encode($compact, JSON_THROW_ON_ERROR)));
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $plan = app(QaPlanService::class)->compose($this->actor(), $this->p, $compact, 'maximum-plan');
            $queries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertCount(100, $plan['entries']);
        $this->assertSame(100, $plan['included_criterion_count']);
        $this->assertSame(2000, $plan['selected_definition_count']);
        $this->assertSame($plan['input_hash'], $this->assembledPlanHash($plan));
        // Original budget 80 plus four constant R5 replay-precheck context/lookup queries; actual maximum is 82.
        $this->assertLessThanOrEqual(84, $queries, 'Compose SQL must be bounded by table categories, not entries/definitions.');
        fwrite(STDOUT, 'QA_MAX compose_queries='.$queries.' compact_bytes='.strlen(json_encode($compact, JSON_THROW_ON_ERROR))." definitions=2000 criteria=100\n");
    }

    public function test_fixtures_are_immutable_replayed_and_definitions_start_designed(): void
    {
        $this->qaInputs();
        $body = $this->definitionBody();
        $envelope = $this->qaEnvelope('definition', $body, 'test-a');
        $first = app(QaFixtureService::class)->register($this->actor(), $this->p, $envelope, 'first', 0);
        $again = app(QaFixtureService::class)->register($this->actor(), $this->p, $envelope, 'alias', 999);
        $this->assertSame($first['record_id'], $again['record_id']);
        $this->assertTrue($again['replayed']);
        $this->qaRun(function () use ($first) {
            $definition = DB::table('qa_definition_versions')->find($first['record_id']);
            $this->assertSame('designed', $definition->review_state);
            $this->assertSame(0, $definition->review_revision);
            $this->assertFalse($definition->release_critical);
            $this->assertSame(1, DB::table('qa_changes')->where('project_id', $this->p)->where('receipt_id', $first['receipt_id'])->count());
            $this->assertSame(1, DB::table('qa_changes')->where('project_id', $this->p)->where('origin', 'lifecycle')->where('baseline_id', $this->baselineId)->whereNotNull('lifecycle_mutation_id')->count());
            $this->assertSame(2, DB::table('qa_request_keys')->count());
        });
    }

    public function test_new_definition_version_does_not_inherit_review_and_cannot_move_stable_criterion(): void
    {
        $this->qaInputs();
        $first = $this->reviewedDefinition();
        $body = $this->definitionBody(revision: 2);
        $next = app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('definition', $body, 'test-a', 2), 'definition-2', 1);
        $this->qaRun(fn () => $this->assertSame('designed', DB::table('qa_definition_versions')->find($next['record_id'])->review_state));
        $this->qaRun(fn () => $this->assertSame('reviewed', DB::table('qa_definition_versions')->find($first['id'])->review_state));
        $body['revision'] = 3;
        $body['criterion_version_id'] = $this->criteria[1]['version_id'];
        $this->expectException(ConflictHttpException::class);
        app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('definition', $body, 'test-a', 3), 'definition-3', 2);
    }

    public function test_definition_review_is_cas_and_original_source_authority_is_required(): void
    {
        $this->qaInputs();
        $definition = $this->reviewedDefinition();
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => app(QaDefinitionService::class)->review($this->actor(), $this->p, $definition['id'], ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => 'Synthetic review']));
        $this->assertSame($before, $this->qaCounts());
        $this->revokeQaGrant('qa.fixture.manage');
        $this->grant('qa.fixture.manage', $this->p);
        $this->assertQaConflict(fn () => app(QaDefinitionService::class)->review($this->actor(), $this->p, $definition['id'], ['expected_revision' => 1, 'decision' => 'reviewed', 'reason' => 'Replacement cannot repair source']));
    }

    public function test_plan_partition_retains_missing_definitions_and_full_hash(): void
    {
        $this->qaInputs();
        $definition = $this->reviewedDefinition();
        $entries = [$this->planEntry(0, [['definition_version_id' => $definition['id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(1), $this->planEntry(2, [], false)];
        $plan = $this->composeEntries($entries);
        $this->assertCount(3, $plan['entries']);
        $this->assertSame(2, $plan['included_criterion_count']);
        $this->assertSame(1, $plan['selected_definition_count']);
        $this->assertSame($this->assembledPlanHash($plan), $plan['input_hash']);
        $this->assertNotSame(hash('sha256', json_encode(array_column($entries, 'id'))), $plan['input_hash']);
        $again = $this->composeEntries(array_reverse($entries), 'compose-alias');
        $this->assertSame($plan['input_hash'], $again['input_hash']);
    }

    public function test_omitted_duplicate_and_foreign_baseline_entries_are_rejected_without_writes(): void
    {
        $this->qaInputs();
        $entry = $this->planEntry(0);
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => $this->composeEntries([$entry]));
        $this->assertSame($before, $this->qaCounts());
        $duplicate = $this->planEntry(0, key: 'duplicate');
        $this->assertQaConflict(fn () => $this->composeEntries([$entry, $duplicate, $this->planEntry(2)]));
        $old = $this->baselineId;
        $this->baseline($this->scope());
        $this->assertQaConflict(fn () => app(QaPlanService::class)->createEntry($this->actor(), $this->p, $this->entryInput(0, baseline: $old), 'foreign-baseline'));
    }

    public function test_exclusion_requires_reason_and_excluded_tests_are_rejected(): void
    {
        $this->qaInputs();
        foreach ([null, '', '   '] as $reason) {
            $input = $this->entryInput(0, included: false);
            $input['reason'] = $reason;
            try {
                app(QaPlanService::class)->createEntry($this->actor(), $this->p, $input, 'invalid-exclusion');
                $this->fail('Missing reason accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $definition = $this->reviewedDefinition();
        $input = $this->entryInput(0, [['definition_version_id' => $definition['id'], 'source_instance_id' => $this->sourceId(), 'required' => true]], false);
        $this->expectException(InvalidArgumentException::class);
        app(QaPlanService::class)->createEntry($this->actor(), $this->p, $input, 'excluded-tests');
    }

    public function test_entry_requires_exact_current_reviewed_definition_and_source(): void
    {
        $this->qaInputs();
        $registered = app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('definition', $this->definitionBody(), 'test-a'), 'designed', 0);
        $test = ['definition_version_id' => $registered['record_id'], 'source_instance_id' => $this->sourceId(), 'required' => true];
        $this->assertQaConflict(fn () => $this->planEntry(0, [$test]));
        app(QaDefinitionService::class)->review($this->actor(), $this->p, $registered['record_id'], ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => 'Synthetic review']);
        $this->assertQaConflict(fn () => $this->planEntry(1, [$test]));
        $entry = $this->planEntry(0, [$test]);
        app(QaDefinitionService::class)->review($this->actor(), $this->p, $registered['record_id'], ['expected_revision' => 1, 'decision' => 'revoked', 'reason' => 'Synthetic revocation']);
        app(QaDefinitionService::class)->review($this->actor(), $this->p, $registered['record_id'], ['expected_revision' => 2, 'decision' => 'reviewed', 'reason' => 'New review is a different pin']);
        $this->assertQaConflict(fn () => $this->composeEntries([$entry, $this->planEntry(1), $this->planEntry(2)]));
    }

    public function test_empty_included_scope_and_no_selection_are_distinct(): void
    {
        $this->qaInputs();
        $this->assertNull($this->currentSelection());
        $plan = $this->composeEntries([$this->planEntry(0, [], false), $this->planEntry(1, [], false), $this->planEntry(2, [], false)]);
        $review = app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Explicit empty synthetic scope']);
        $this->assertSame('approved', $review['state']);
        $this->assertSame(0, $plan['included_criterion_count']);
        $this->assertSame(0, $plan['selected_definition_count']);
        $this->assertNull($this->currentSelection());
    }

    public function test_overlapping_plan_reviewers_commit_one_event_and_one_change(): void
    {
        $this->qaInputs();
        $plan = $this->composeEntries([$this->planEntry(0), $this->planEntry(1), $this->planEntry(2)]);
        $this->contendQaOperation('plan-review', ['plan_id' => $plan['id'], 'decision' => ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Concurrent synthetic review']]);
        $this->qaRun(function () use ($plan) {
            $this->assertSame(1, DB::table('qa_reviews')->where('plan_id', $plan['id'])->count());
            $this->assertSame(1, DB::table('qa_changes')->where('project_id', $this->p)->where('kind', 'plan_reviewed')->count());
            $this->assertSame(1, DB::table('qa_plan_versions')->find($plan['id'])->review_revision);
        });
    }

    public function test_admission_failure_rolls_back_new_fixture_but_authorized_alias_replay_is_available(): void
    {
        $this->qaInputs();
        $first = $this->qaBuild();
        $this->qaRun(function ($proofs) use ($first) {
            foreach (range(1, 99) as $i) {
                app(QaChangeWriter::class)->appendLocked($this->actor(), $this->p, 'source_recorded', ['receipt_id' => $first['receipt_id']], hash('sha256', 'synthetic-job-'.$i), $proofs['current']['proof']);
            }
        });
        $before = $this->qaCounts();
        try {
            $this->qaBuild(2, 1);
            $this->fail('Admission overflow was accepted.');
        } catch (ServiceUnavailableHttpException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $this->qaCounts());
        $envelope = $this->qaEnvelope('build', ['logical_key' => 'build', 'revision' => 1, 'title' => 'Synthetic build', 'content_hash' => hash('sha256', 'build-1')], 'build');
        $replayed = app(QaFixtureService::class)->register($this->actor(), $this->p, $envelope, 'full-queue-alias', 999);
        $this->assertSame($first['record_id'], $replayed['record_id']);
        $this->assertTrue($replayed['replayed']);
        $after = $this->qaCounts();
        $this->assertSame(array_slice($before, 0, 7), array_slice($after, 0, 7));
        $this->assertSame($before[7] + 1, $after[7]);
    }

    public function test_request_bounds_unknown_fields_and_duplicate_tests_reject_before_writes(): void
    {
        $this->qaInputs();
        $definition = $this->reviewedDefinition();
        $test = ['definition_version_id' => $definition['id'], 'source_instance_id' => $this->sourceId(), 'required' => true];
        $before = $this->qaCounts();
        $inputs = [$this->entryInput(0, [$test, $test]), $this->entryInput(0, array_fill(0, 21, $test)), $this->entryInput(0) + ['tenant_id' => $this->tenant]];
        foreach ($inputs as $input) {
            try {
                app(QaPlanService::class)->createEntry($this->actor(), $this->p, $input, 'invalid-bound');
                $this->fail('Invalid request accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $compact = ['baseline_id' => $this->baselineId, 'entry_snapshot_ids' => array_map(fn () => (string) Str::uuid(), range(0, 100))];
        try {
            app(QaPlanService::class)->compose($this->actor(), $this->p, $compact, 'overflow');
            $this->fail('Plan criterion overflow accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $this->qaCounts());
    }

    public function test_plan_replay_retains_exact_snapshot_and_cas_conflicts_create_no_review(): void
    {
        $this->qaInputs();
        $entries = [$this->planEntry(0), $this->planEntry(1), $this->planEntry(2)];
        $plan = $this->composeEntries($entries);
        $before = $this->qaCounts();
        $again = $this->composeEntries(array_reverse($entries));
        $this->assertTrue($again['replayed']);
        $this->assertSame($plan['id'], $again['id']);
        $this->assertSame($plan['input_hash'], $again['input_hash']);
        $this->assertSame($before, $this->qaCounts());
        $this->assertQaConflict(fn () => app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 1, 'decision' => 'approved', 'reason' => 'Wrong CAS']));
        $this->assertSame($before, $this->qaCounts());
    }

    public function test_fresh_explicit_definition_review_does_not_consume_a_revoked_previous_review_proof(): void
    {
        $this->qaInputs();
        $definition = $this->reviewedDefinition();
        $test = ['definition_version_id' => $definition['id'], 'source_instance_id' => $this->sourceId(), 'required' => true];
        $oldEntry = $this->planEntry(0, [$test]);
        $this->revokeQaGrant('qa.plan.manage');
        $this->grant('qa.plan.manage', $this->p);
        $review = app(QaDefinitionService::class)->review($this->actor(), $this->p, $definition['id'], ['expected_revision' => 1, 'decision' => 'reviewed', 'reason' => 'Fresh explicit synthetic review']);
        $this->assertSame(2, $review['revision']);
        $this->assertNotSame($definition['review_id'], $review['id']);
        $newEntry = $this->planEntry(0, [$test], key: 'fresh-reviewed-entry');
        $this->assertSame($review['id'], $newEntry['tests'][0]['definition_review_id']);
        $this->assertNotSame($oldEntry['input_hash'], $newEntry['input_hash']);
        $this->assertQaConflict(fn () => $this->composeEntries([$oldEntry, $this->planEntry(1), $this->planEntry(2)]));
    }

    public function test_build_sha_pins_must_match_the_exact_scoped_synthetic_mr_snapshot(): void
    {
        $this->qaInputs();
        $this->grant('analysis.fixture.manage', $this->p);
        $mr = require __DIR__.'/../Fixtures/developer-mr.php';
        $registered = app(SyntheticMrRegistry::class)->register($this->actor(), $this->p, $mr, 0);
        $body = ['logical_key' => 'mr-build', 'revision' => 1, 'title' => 'Synthetic MR build', 'content_hash' => hash('sha256', 'synthetic-build-artifact'), 'mr_snapshot_id' => $registered['snapshot_id'], 'base_sha' => str_repeat('c', 40), 'head_sha' => $mr['head_sha']];
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('build', $body, 'mr-build'), 'mr-build', 0));
        $this->assertSame($before, $this->qaCounts());
        $body['base_sha'] = $mr['base_sha'];
        $build = app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('build', $body, 'mr-build'), 'mr-build', 0);
        $this->qaRun(function () use ($build, $mr, $registered) {
            $row = DB::table('qa_build_versions')->find($build['record_id']);
            $this->assertSame($registered['snapshot_id'], $row->mr_snapshot_id);
            $this->assertSame($mr['base_sha'], $row->base_sha);
            $this->assertSame($mr['head_sha'], $row->head_sha);
        });
    }

    public function test_synthetic_fixture_registration_denies_production_before_database_writes(): void
    {
        $this->qaInputs();
        $envelope = $this->qaEnvelope('definition', $this->definitionBody(), 'test-a');
        $before = $this->qaCounts();
        $this->app['env'] = 'production';
        try {
            app(QaFixtureService::class)->register($this->actor(), $this->p, $envelope, 'production-denial', 0);
            $this->fail('Production synthetic registration was enabled.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame($before, $this->qaCounts());
    }

    public function test_historical_definition_cannot_advance_the_stable_definition_head_and_is_atomic(): void
    {
        $this->qaInputs();
        $first = app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('definition', $this->definitionBody(), 'test-a', 3), 'source-3-definition-1', 0);
        app(QaDefinitionService::class)->review($this->actor(), $this->p, $first['record_id'], ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => 'Current synthetic definition']);
        $heads = $this->qaRun(fn () => [(array) DB::table('qa_source_heads')->where('object_id', 'test-a')->first(), (array) DB::table('qa_definition_identities')->where('logical_key', 'test-a')->first()]);
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('definition', $this->definitionBody(revision: 2), 'test-a', 2), 'source-2-definition-2', 1));
        $this->assertSame($before, $this->qaCounts());
        $this->qaRun(function () use ($heads, $first) {
            $this->assertSame($heads, [(array) DB::table('qa_source_heads')->where('object_id', 'test-a')->first(), (array) DB::table('qa_definition_identities')->where('logical_key', 'test-a')->first()]);
            $this->assertSame(1, DB::table('qa_definition_versions')->count());
            $this->assertSame($first['record_id'], DB::table('qa_definition_identities')->where('logical_key', 'test-a')->value('current_definition_id'));
        });
        $entry = $this->planEntry(0, [['definition_version_id' => $first['record_id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]);
        $this->assertSame($first['record_id'], $entry['tests'][0]['definition_version_id']);
    }

    public function test_fresh_plan_review_still_requires_exact_definition_reviewer_proof(): void
    {
        $this->qaInputs();
        $reviewer = $this->distinctQaReviewer();
        $definition = app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('definition', $this->definitionBody(), 'test-a'), 'definition', 0);
        app(QaDefinitionService::class)->review($reviewer, $this->p, $definition['record_id'], ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => 'Distinct synthetic definition review']);
        $plan = $this->composeEntries([$this->planEntry(0, [['definition_version_id' => $definition['record_id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(1), $this->planEntry(2)]);
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic approval']);
        $this->revokeDistinctQaReviewer($reviewer);
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 1, 'decision' => 'approved', 'reason' => 'Fresh review cannot borrow invalid definition review']));
        $this->assertSame($before, $this->qaCounts());
    }

    #[DataProvider('originalPlanPurposes')]
    public function test_fresh_plan_review_still_requires_original_creator_and_source_proofs(string $purpose): void
    {
        $this->qaInputs();
        $definition = $this->reviewedDefinition();
        $entries = [$this->planEntry(0, [['definition_version_id' => $definition['id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(1), $this->planEntry(2)];
        $creator = $this->distinctQaReviewer();
        $plan = app(QaPlanService::class)->compose($creator, $this->p, ['baseline_id' => $this->baselineId, 'entry_snapshot_ids' => array_column($entries, 'id')], 'distinct-creator-plan');
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic approval']);
        $reviewer = $this->distinctQaReviewer();
        if ($purpose === 'qa.plan.manage') {
            $this->revokeDistinctQaReviewer($creator);
        } else {
            $this->revokeQaGrant($purpose);
            $this->grant($purpose, $this->p);
        }
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => app(QaPlanService::class)->review($reviewer, $this->p, $plan['id'], ['expected_revision' => 1, 'decision' => 'revoked', 'reason' => 'Fresh review cannot repair original creator/source proof']));
        $this->assertSame($before, $this->qaCounts());
    }

    public static function originalPlanPurposes(): array
    {
        return ['source' => ['qa.source.read'], 'creator' => ['qa.plan.manage']];
    }
}
