<?php

namespace Tests\Feature;

use App\Domain\Lifecycle\LifecycleChangeWriter;
use App\Domain\Lifecycle\ProjectCoordination;
use App\Domain\Qa\QaChangeWriter;
use App\Domain\Qa\QaContract;
use App\Domain\Qa\QaReceiptWriter;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\QaTestCase;

class QaSchemaIsolationTest extends QaTestCase
{
    public function test_actual_runtime_is_nonowner_and_all_qa_tables_force_rls(): void
    {
        $this->assertFalse($this->runtimeOwnsAnyQaTable());
        $this->assertTrue($this->allQaTablesForceRls());
        $this->assertFalse((bool) DB::scalar('select rolsuper or rolbypassrls from pg_roles where rolname=current_user'));
        $this->assertSame(0, DB::table('qa_receipts')->count());
    }

    public function test_review_heads_cannot_be_inserted_as_reviewed_without_a_matching_review(): void
    {
        $this->qaInputs();
        $this->assertSqlState('23514', fn () => $this->qaRun(fn ($r) => $this->insertQaPlan($r['current']['proof'], 'approved')));
        $this->assertSqlState('23514', fn () => $this->seedQaLineage(false, 'reviewed'));
    }

    public function test_real_review_event_and_head_update_enable_selection_and_reject_wrong_decision(): void
    {
        $this->qaInputs();
        $ids = $this->seedQaLineage();
        $this->qaRun(function ($r) use ($ids) {
            $plan = $this->insertQaPlan($r['current']['proof']);
            $this->assertSqlState('23514', fn () => $this->insertQaSelection($plan, $ids, $r['current']['proof']));
            $review = (string) Str::uuid();
            DB::table('qa_reviews')->insert(['id' => $review, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'plan_id' => $plan, 'revision' => 1, 'decision' => 'approved', 'subject_hash' => str_repeat('a', 64), 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($r['current']['proof']), 'reason' => 'Synthetic approval']);
            $this->assertSqlState('23514', fn () => DB::table('qa_plan_versions')->where('id', $plan)->update(['review_revision' => 1, 'current_review_id' => $review, 'review_state' => 'rejected']));
            DB::table('qa_plan_versions')->where('id', $plan)->update(['review_revision' => 1, 'current_review_id' => $review, 'review_state' => 'approved']);
            $definitionReview = (string) Str::uuid();
            DB::table('qa_reviews')->insert(['id' => $definitionReview, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'definition_id' => $ids['definition'], 'revision' => 1, 'decision' => 'reviewed', 'subject_hash' => str_repeat('a', 64), 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($r['current']['proof']), 'reason' => 'Synthetic review']);
            DB::table('qa_definition_versions')->where('id', $ids['definition'])->update(['review_revision' => 1, 'current_review_id' => $definitionReview, 'review_state' => 'reviewed']);
            $this->insertQaSelection($plan, $ids, $r['current']['proof'], $review);
            $this->assertSame('approved', DB::table('qa_plan_versions')->where('id', $plan)->value('review_state'));
            $this->assertSame('reviewed', DB::table('qa_definition_versions')->where('id', $ids['definition'])->value('review_state'));
        });
    }

    public function test_defect_closure_rejects_another_defects_same_pin_retest_and_missing_references(): void
    {
        $this->qaInputs();
        $ids = $this->seedQaLineage();
        $this->qaRun(function ($r) use ($ids) {
            $fixture = $this->seedDefectRetest($ids, $r['current']['proof']);
            $this->assertSqlState('23503', fn () => $this->insertDefectClosure($fixture['a'], $ids, $r['current']['proof'], $fixture['execution'], $fixture['request_a']));
            $this->assertSqlState('23514', fn () => $this->insertDefectClosure($fixture['b'], $ids, $r['current']['proof'], null, $fixture['request_b']));
            $this->assertSqlState('23514', fn () => $this->insertDefectClosure($fixture['b'], $ids, $r['current']['proof'], $fixture['execution'], null));
            $event = $this->insertDefectClosure($fixture['b'], $ids, $r['current']['proof'], $fixture['execution'], $fixture['request_b']);
            DB::table('qa_defects')->where('id', $fixture['b'])->update(['revision' => 4, 'current_event_id' => $event, 'state' => 'verified_closed']);
            $this->assertSame('verified_closed', DB::table('qa_defects')->where('id', $fixture['b'])->value('state'));
        });
    }

    public function test_entry_definition_review_pin_rejects_wrong_subject_and_revoked_decision(): void
    {
        $this->qaInputs();
        $ids = $this->seedQaLineage();
        $this->qaRun(function ($r) use ($ids) {
            $proof = $r['current']['proof'];
            $this->assertSqlState('23514', fn () => DB::table('qa_reviews')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'definition_id' => $ids['definition'], 'revision' => 1, 'decision' => 'reviewed', 'subject_hash' => str_repeat('b', 64), 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($proof), 'reason' => 'Synthetic wrong hash']));
            $review = $this->qaReview('definition', $ids['definition'], 'reviewed', $proof);
            $plan = $this->insertQaPlan($proof);
            $planReview = $this->qaReview('plan', $plan, 'approved', $proof);
            $this->assertSqlState('23503', fn () => $this->insertQaEntry($ids, $proof, $planReview));
            $entry = $this->insertQaEntry($ids, $proof, $review);
            $this->assertSame($review, DB::table('qa_plan_entry_definitions')->where('entry_id', $entry)->value('definition_review_id'));
            $revoked = $this->qaReview('definition', $ids['definition'], 'revoked', $proof);
            $this->assertSqlState('23503', fn () => $this->insertQaEntry($ids, $proof, $revoked));
            $this->assertSqlState('23503', fn () => $this->insertQaEntry($ids, $proof, $review));
        });
    }

    public function test_selection_plan_review_pin_rejects_wrong_subject_and_old_approved_review(): void
    {
        $this->qaInputs();
        $ids = $this->seedQaLineage();
        $this->qaRun(function ($r) use ($ids) {
            $proof = $r['current']['proof'];
            $definitionReview = $this->qaReview('definition', $ids['definition'], 'reviewed', $proof);
            $plan = $this->insertQaPlan($proof);
            $review = $this->qaReview('plan', $plan, 'approved', $proof);
            $this->assertSqlState('23503', fn () => $this->insertQaSelection($plan, $ids, $proof, $definitionReview));
            $selection = $this->insertQaSelection($plan, $ids, $proof, $review);
            $this->assertSame($review, DB::table('qa_selection_events')->where('id', $selection)->value('plan_review_id'));
            $this->qaReview('plan', $plan, 'revoked', $proof);
            $replacement = $this->qaReview('plan', $plan, 'approved', $proof);
            $this->assertSqlState('23503', fn () => $this->insertQaSelection($plan, $ids, $proof, $review, 2));
            $this->insertQaSelection($plan, $ids, $proof, $replacement, 2);
        });
    }

    public function test_review_pin_history_and_canonical_graph_survive_revocation_and_new_reviews(): void
    {
        $this->qaInputs();
        $ids = $this->seedQaLineage();
        $this->qaRun(function ($r) use ($ids) {
            $proof = $r['current']['proof'];
            $definitionReview = $this->qaReview('definition', $ids['definition'], 'reviewed', $proof);
            $entry = $this->insertQaEntry($ids, $proof, $definitionReview);
            $plan = $this->insertQaPlan($proof);
            DB::table('qa_plan_entries')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'plan_id' => $plan, 'baseline_id' => $this->baselineId, 'criterion_version_id' => $ids['criterion_version_id'], 'entry_id' => $entry]);
            $planReview = $this->qaReview('plan', $plan, 'approved', $proof);
            $selection = $this->insertQaSelection($plan, $ids, $proof, $planReview);
            $immutableVersion = function (string $table, string $id): array {
                $row = (array) DB::table($table)->where('id', $id)->first();
                unset($row['review_revision'], $row['current_review_id'], $row['review_state']);

                return $row;
            };
            $canonical = fn () => QaContract::canonical([
                'entry' => (array) DB::table('qa_plan_entry_snapshots')->where('id', $entry)->first(),
                'definitions' => DB::table('qa_plan_entry_definitions')->where('entry_id', $entry)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
                'definition' => $immutableVersion('qa_definition_versions', $ids['definition']),
                'definition_review' => (array) DB::table('qa_reviews')->where('id', $definitionReview)->first(),
                'plan' => $immutableVersion('qa_plan_versions', $plan),
                'plan_review' => (array) DB::table('qa_reviews')->where('id', $planReview)->first(),
                'plan_entries' => DB::table('qa_plan_entries')->where('plan_id', $plan)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
                'selection' => (array) DB::table('qa_selection_events')->where('id', $selection)->first(),
            ]);
            $before = $canonical();
            $this->qaReview('definition', $ids['definition'], 'revoked', $proof);
            $newDefinitionReview = $this->qaReview('definition', $ids['definition'], 'reviewed', $proof);
            $this->qaReview('plan', $plan, 'revoked', $proof);
            $newPlanReview = $this->qaReview('plan', $plan, 'approved', $proof);
            $this->assertNotSame($definitionReview, $newDefinitionReview);
            $this->assertNotSame($planReview, $newPlanReview);
            $this->assertSame($before, $canonical(), 'Original immutable canonical graph must be reconstructible without current heads.');
            $this->assertSame($definitionReview, DB::table('qa_plan_entry_definitions')->where('entry_id', $entry)->value('definition_review_id'));
            $this->assertSame($planReview, DB::table('qa_selection_events')->where('id', $selection)->value('plan_review_id'));
            $this->assertSqlState('42501', fn () => DB::table('qa_plan_entry_definitions')->where('entry_id', $entry)->update(['definition_review_id' => $newDefinitionReview]));
            $this->assertSqlState('42501', fn () => DB::table('qa_selection_events')->where('id', $selection)->update(['plan_review_id' => $newPlanReview]));
            $this->assertFalse((bool) DB::scalar('select d.current_review_id=e.definition_review_id from qa_plan_entry_definitions e join qa_definition_versions d on d.id=e.definition_id where e.entry_id=?', [$entry]));
            $this->assertFalse((bool) DB::scalar('select p.current_review_id=s.plan_review_id from qa_selection_events s join qa_plan_versions p on p.id=s.plan_id where s.id=?', [$selection]));
        });
    }

    public function test_request_aliases_require_operation_output_and_receipt_kind_in_helper_and_storage(): void
    {
        $this->qaInputs();
        $task = $this->qaTask();
        $ids = $this->seedQaLineage();
        $this->qaRun(function ($r) use ($ids, $task) {
            $receipt = DB::table('qa_build_versions')->where('id', $ids['build'])->value('receipt_id');
            $environmentReceipt = DB::table('qa_environment_versions')->where('id', $ids['environment'])->value('receipt_id');
            $plan = $this->insertQaPlan($r['current']['proof']);
            $writer = app(QaReceiptWriter::class);
            try {
                $writer->bindRequestLocked($this->actor(), $this->p, 'build', 'bad-helper', str_repeat('a', 64), ['plan_id' => $plan]);
                $this->fail('Expected typed helper rejection.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
            $base = ['tenant_id' => $this->tenant, 'project_id' => $this->p, 'requester_id' => $this->user, 'payload_hash' => str_repeat('a', 64)];
            $this->assertSqlState('23514', fn () => DB::table('qa_request_keys')->insert(['id' => (string) Str::uuid(), 'operation' => 'build', 'key' => 'bad-column', 'plan_id' => $plan] + $base));
            $this->assertSqlState('23514', fn () => DB::table('qa_request_keys')->insert(['id' => (string) Str::uuid(), 'operation' => 'unknown', 'key' => 'bad-operation', 'receipt_id' => $receipt] + $base));
            $this->assertSqlState('23503', fn () => DB::table('qa_request_keys')->insert(['id' => (string) Str::uuid(), 'operation' => 'build', 'key' => 'bad-kind', 'receipt_id' => $environmentReceipt] + $base));
            $writer->bindRequestLocked($this->actor(), $this->p, 'build', 'valid-build', str_repeat('a', 64), ['receipt_id' => $receipt]);
            $writer->bindRequestLocked($this->actor(), $this->p, 'plan.compose', 'valid-plan', str_repeat('a', 64), ['plan_id' => $plan]);
            $this->assertSame(['receipt_id' => $receipt], $writer->requestLocked($this->actor(), $this->p, 'build', 'valid-build', str_repeat('a', 64)));
            $this->assertSame(['plan_id' => $plan], $writer->requestLocked($this->actor(), $this->p, 'plan.compose', 'valid-plan', str_repeat('a', 64)));
            $fixture = $this->seedDefectRetest($ids, $r['current']['proof']);
            $defectReceipt = DB::table('qa_defects')->where('id', $fixture['a'])->value('receipt_id');
            $handoffBody = ['task_version_id' => $task['version_id'], 'criterion_version_id' => $ids['criterion_version_id'], 'owner_id' => $this->employee, 'handoff_type' => 'verification_blocked', 'reason' => 'Synthetic', 'occurred_at' => '2026-10-06T00:00:00.000000Z'];
            $handoffReceipt = $this->receipt($r['current']['proof'], 'handoff', $handoffBody, 'handoff');
            $handoff = (string) Str::uuid();
            DB::table('qa_handoffs')->insert(['id' => $handoff, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'receipt_id' => $handoffReceipt['receipt_id'], 'task_version_id' => $task['version_id'], 'criterion_id' => $ids['criterion_id'], 'criterion_version_id' => $ids['criterion_version_id'], 'handoff_type' => 'verification_blocked', 'responsibility' => '{}', 'opened_at' => $handoffBody['occurred_at'], 'owner_id' => $this->employee]);
            foreach ([['defect.report', ['receipt_id' => $defectReceipt]], ['defect.report', ['defect_id' => $fixture['a']]], ['handoff.open', ['receipt_id' => $handoffReceipt['receipt_id']]], ['handoff.open', ['handoff_id' => $handoff]]] as $i => [$operation, $output]) {
                $writer->bindRequestLocked($this->actor(), $this->p, $operation, 'alternative-'.$i, str_repeat('a', 64), $output);
                $this->assertSame($output, $writer->requestLocked($this->actor(), $this->p, $operation, 'alternative-'.$i, str_repeat('a', 64)));
            }
        });
    }

    public function test_runtime_cannot_rewrite_review_history(): void
    {
        $this->assertSqlState('42501', fn () => $this->rewriteQaReviewHistory());
    }

    public function test_contract_accepts_bounded_reason_and_rejects_oversize_or_credentials(): void
    {
        $contract = app(QaContract::class);
        $this->assertSame(str_repeat('é', 1000), $contract->reason(str_repeat('é', 1000)));
        foreach ([str_repeat('a', 2001), ' ', 'password=synthetic-value', "hello\0world"] as $bad) {
            try {
                $contract->reason($bad);
                $this->fail('Unsafe reason accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_parser_rejects_nested_duplicate_keys_invalid_utf8_and_deep_json(): void
    {
        foreach (['{"body":{"x":1,"x":2}}', '{"body":{"x":1,"\\u0078":2}}', "{\"x\":\"\xff\"}", str_repeat('[', 17).'0'.str_repeat(']', 17)] as $bad) {
            try {
                app(QaContract::class)->parse($bad);
                $this->fail('Invalid JSON accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unknown_operation_and_spoofed_actor_are_rejected(): void
    {
        foreach ([['actor_id' => 'caller'], ['unexpected' => true]] as $bad) {
            try {
                app(QaContract::class)->validate('build', $bad);
                $this->fail('Unknown body accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_real_semantic_constraint_rejects_same_project_cross_criterion_execution(): void
    {
        $this->qaInputs();
        $this->assertSqlState('23503', fn () => $this->crossCriterionExecutionWrite());
    }

    public function test_typed_build_rejects_mismatched_receipt_content(): void
    {
        $this->qaInputs();
        $this->assertSqlState('23514', fn () => $this->qaRun(function ($r) {
            $body = ['logical_key' => 'build', 'revision' => 1, 'title' => 'Original', 'content_hash' => str_repeat('a', 64)];
            $receipt = $this->receipt($r['current']['proof'], 'build', $body, 'build');
            $body['title'] = 'Substituted';
            DB::table('qa_build_versions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'receipt_id' => $receipt['receipt_id']] + $body);
        }));
    }

    public function test_missing_qa_authority_version_is_rejected_by_database(): void
    {
        $this->qaInputs();
        $this->assertSqlState('23514', fn () => $this->qaRun(function () {
            DB::table('qa_receipts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'source_instance_id' => $this->envelopeSource(), 'object_id' => 'bad-proof', 'source_revision' => 1, 'kind' => 'build', 'payload_hash' => str_repeat('a', 64), 'envelope' => '{}', 'actor_id' => $this->user, 'authority_proof' => '{}', 'occurred_at' => '2026-10-06T00:00:00Z', 'source_updated_at' => '2026-10-06T00:00:00Z']);
        }));
    }

    public function test_source_replay_and_new_alias_create_no_new_fact_audit_or_outbox(): void
    {
        $this->qaInputs();
        $this->qaRun(function ($r) {
            $writer = app(QaReceiptWriter::class);
            $body = ['logical_key' => 'build', 'revision' => 1, 'title' => 'Synthetic build', 'content_hash' => str_repeat('a', 64)];
            $envelope = $this->qaEnvelope('build', $body, 'build');
            $a = $writer->recordLocked($this->actor(), $this->p, $envelope, 'first', 0, $r['current']['proof']);
            DB::table('qa_build_versions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'receipt_id' => $a['receipt_id']] + $body);
            $writer->bindRequestLocked($this->actor(), $this->p, 'build', 'first', $a['input_hash'], ['receipt_id' => $a['receipt_id']]);
            app(QaChangeWriter::class)->appendLocked($this->actor(), $this->p, 'source_recorded', ['receipt_id' => $a['receipt_id']], $a['input_hash'], $r['current']['proof']);
            $before = [DB::table('qa_receipts')->count(), DB::table('qa_changes')->count(), DB::table('qa_outbox')->count(), DB::table('audit_events')->count()];
            $b = $writer->recordLocked($this->actor(), $this->p, $envelope, 'alias', 99, $r['current']['proof']);
            $writer->bindRequestLocked($this->actor(), $this->p, 'build', 'alias', $b['input_hash'], ['receipt_id' => $b['receipt_id']]);
            $this->assertSame($a['receipt_id'], $b['receipt_id']);
            $this->assertTrue($b['replayed']);
            $this->assertSame($before, [DB::table('qa_receipts')->count(), DB::table('qa_changes')->count(), DB::table('qa_outbox')->count(), DB::table('audit_events')->count()]);
            $this->assertSame(2, DB::table('qa_request_keys')->count());
        });
    }

    public function test_receipt_reads_are_tenant_isolated_and_missing_context_denies(): void
    {
        $this->qaInputs();
        $this->seedQaLineage();
        $this->assertSame(3, app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_receipts')->count()));
        $this->assertSame(0, DB::table('qa_receipts')->count());
        $this->assertSame(0, app(TenantContext::class)->run((string) Str::uuid(), fn () => DB::table('qa_receipts')->count()));
    }

    public function test_database_rejects_same_tenant_wrong_project_receipt_reference(): void
    {
        $this->qaInputs();
        $ids = $this->seedQaLineage();
        $other = $this->project();
        $this->assertSqlState('23503', fn () => app(TenantContext::class)->run($this->tenant, function () use ($ids, $other) {
            $receipt = DB::table('qa_build_versions')->where('id', $ids['build'])->value('receipt_id');
            DB::table('qa_build_versions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $other, 'receipt_id' => $receipt, 'logical_key' => 'wrong-project', 'revision' => 1, 'title' => 'Synthetic', 'content_hash' => str_repeat('a', 64)]);
        }));
    }

    public function test_definition_pointer_cannot_skip_revision(): void
    {
        $this->qaInputs();
        $ids = $this->seedQaLineage();
        $this->assertSqlState('23514', fn () => app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_definition_identities')->where('current_definition_id', $ids['definition'])->update(['revision' => 3])));
    }

    public function test_valid_envelope_parses_without_coercing_boolean_and_invalid_dates_are_rejected(): void
    {
        $this->qaInputs();
        app(TenantContext::class)->run($this->tenant, function () {
            $envelope = $this->qaEnvelope('definition', ['logical_key' => 'test-a', 'revision' => 1, 'criterion_version_id' => $this->criteria[0]['version_id'], 'title' => 'Synthetic', 'steps' => 'Run', 'expected_result' => 'Pass', 'test_type' => 'functional', 'release_critical' => false]);
            $this->assertFalse(app(QaContract::class)->parse(QaContract::canonical($envelope))['body']['release_critical']);
            foreach (['bool' => 0, 'date' => false] as $key => $bad) {
                $copy = $envelope;
                if ($key === 'bool') {
                    $copy['body']['release_critical'] = $bad;
                } else {
                    $copy['occurred_at'] = '2026-02-31T00:00:00.000000Z';
                }
                try {
                    app(QaContract::class)->validate('envelope', app(QaContract::class)->parse(QaContract::canonical($copy)));
                    $this->fail('Invalid typed envelope accepted.');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        });
    }

    public function test_shared_decoder_preserves_empty_object_for_body_validation_and_rejects_array_substitution(): void
    {
        $contract = app(QaContract::class);
        $valid = $contract->parse('{"expected_revision":0,"kind":"triaged","reason":"Synthetic","pins":{}}');
        $this->assertSame([], $contract->validate('defect.transition', $valid)['pins']);
        $bad = $contract->parse('{"expected_revision":0,"kind":"triaged","reason":"Synthetic","pins":[]}');
        $this->expectException(InvalidArgumentException::class);
        $contract->validate('defect.transition', $bad);
    }

    public function test_body_validation_rejects_blank_human_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(QaContract::class)->validate('definition.review', ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => ' ']);
    }

    public function test_lifecycle_origin_binds_typed_criterion_to_exact_live_mutation(): void
    {
        $this->qaInputs();
        $r = app(ProjectCoordination::class)->run($this->actor(), $this->p, 'requirements.approve', $this->generation, function () {
            $cv = DB::table('criterion_versions')->where('id', $this->criteria[0]['version_id'])->first();
            $mutation = app(LifecycleChangeWriter::class)->record($this->actor(), $this->p, 'requirements.approve', 'criterion_replaced', $cv->criterion_id, $cv->id, 'Synthetic mutation');
            $id = (string) Str::uuid();
            DB::table('qa_changes')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'kind' => 'lifecycle_observed', 'criterion_version_id' => $cv->id, 'lifecycle_mutation_id' => $mutation, 'origin' => 'lifecycle', 'input_hash' => str_repeat('a', 64), 'actor_id' => $this->user]);
            $this->assertSame($mutation, DB::table('qa_changes')->where('id', $id)->value('lifecycle_mutation_id'));

            return $mutation;
        });
        $this->generation = $r['generation'];
        $this->assertSqlState('23514', fn () => app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_changes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'kind' => 'lifecycle_observed', 'criterion_version_id' => $this->criteria[0]['version_id'], 'lifecycle_mutation_id' => $r['result'], 'origin' => 'lifecycle', 'input_hash' => str_repeat('a', 64), 'actor_id' => $this->user])));
    }

    public function test_lifecycle_origin_rejects_same_project_unrelated_criterion_and_cross_project_mutation(): void
    {
        $this->qaInputs();
        $this->assertSqlState('23503', fn () => app(ProjectCoordination::class)->run($this->actor(), $this->p, 'requirements.approve', $this->generation, function () {
            $cv = DB::table('criterion_versions')->where('id', $this->criteria[0]['version_id'])->first();
            $mutation = app(LifecycleChangeWriter::class)->record($this->actor(), $this->p, 'requirements.approve', 'criterion_replaced', $cv->criterion_id, $cv->id, 'Synthetic mutation');
            DB::table('qa_changes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'kind' => 'lifecycle_observed', 'criterion_version_id' => $this->criteria[1]['version_id'], 'lifecycle_mutation_id' => $mutation, 'origin' => 'lifecycle', 'input_hash' => str_repeat('a', 64), 'actor_id' => $this->user]);
        }));
        $other = $this->project();
        $this->assertSqlState('23503', fn () => app(ProjectCoordination::class)->run($this->actor(), $this->p, 'requirements.approve', $this->generation, function () use ($other) {
            $cv = DB::table('criterion_versions')->where('id', $this->criteria[0]['version_id'])->first();
            $mutation = app(LifecycleChangeWriter::class)->record($this->actor(), $this->p, 'requirements.approve', 'criterion_replaced', $cv->criterion_id, $cv->id, 'Synthetic mutation');
            DB::table('qa_changes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $other, 'kind' => 'lifecycle_observed', 'lifecycle_change_id' => $mutation, 'lifecycle_mutation_id' => $mutation, 'origin' => 'lifecycle', 'input_hash' => str_repeat('a', 64), 'actor_id' => $this->user]);
        }));
    }

    public function test_task_origin_binds_current_new_version_to_same_stable_affected_task(): void
    {
        $this->qaInputs();
        $task = $this->qaTask();
        $r = app(ProjectCoordination::class)->run($this->actor(), $this->p, 'requirements.manage', $this->generation, function () use ($task) {
            $origin = $this->revisedTaskOrigin($task);
            $id = (string) Str::uuid();
            DB::table('qa_changes')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'kind' => 'lifecycle_observed', 'task_version_id' => $task['version_id'], 'task_mutation_version_id' => $origin, 'origin' => 'lifecycle', 'input_hash' => str_repeat('a', 64), 'actor_id' => $this->user]);
            $this->assertSame($origin, DB::table('qa_changes')->where('id', $id)->value('task_mutation_version_id'));
        });
        $this->generation = $r['generation'];
    }

    public function test_task_origin_rejects_wrong_actor_wrong_task_and_wrong_project(): void
    {
        $this->qaInputs();
        $task = $this->qaTask();
        $unrelated = $this->qaTask();
        $other = $this->project();
        $otherActor = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->insert(['id' => $otherActor, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic other actor']));
        foreach (['actor', 'task', 'project'] as $bad) {
            $this->assertSqlState('23503', fn () => app(ProjectCoordination::class)->run($this->actor(), $this->p, 'requirements.manage', $this->generation, function () use ($task, $unrelated, $other, $otherActor, $bad) {
                $origin = $this->revisedTaskOrigin($task, $bad === 'actor' ? $otherActor : null);
                DB::table('qa_changes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $bad === 'project' ? $other : $this->p, 'kind' => 'lifecycle_observed', 'task_version_id' => $bad === 'task' ? $unrelated['version_id'] : $task['version_id'], 'task_mutation_version_id' => $origin, 'origin' => 'lifecycle', 'input_hash' => str_repeat('a', 64), 'actor_id' => $this->user]);
            }));
        }
    }
}
