<?php

namespace Tests\Feature;

use App\Domain\Ba\BaCoordination;
use App\Domain\Ba\BaReadService;
use App\Domain\Ba\BaTerminal;
use App\Domain\Lifecycle\RequirementService;
use App\Domain\Management\ManagementContract;
use App\Domain\Management\ManagementCoordination;
use App\Domain\Management\ManagementPins;
use App\Domain\Management\ManagementSourceProofs;
use App\Domain\Management\ManagementSupportService;
use App\Domain\Qa\QaExecutionService;
use App\Domain\Qa\QaHandoffService;
use App\Domain\Qa\QaReadService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\ManagementTestCase;

final class ManagementSourceProofTest extends ManagementTestCase
{
    public function test_metadata_cap_is_checked_before_a_proof_materialization_query(): void
    {
        $this->managementInputs();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = app(ManagementSourceProofs::class)->metadata($this->p, 'management_receipts', array_fill(0, 4001, (string) Str::uuid()));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame([], $rows);
        $this->assertSame(0, $count);
    }

    public function test_original_source_budget_is_reserved_before_frontier_proofs_are_loaded(): void
    {
        $this->managementInputs();
        $handoff = $this->managementQaHandoff();
        $event = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->where('id', $handoff['id'])->value('current_event_id'));
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_handoff_event', 'qa_handoff_event_id' => $event]);
        $budget = ['boundaries' => 1, 'references' => 1, 'source_rows' => 0, 'source_proofs' => 32, 'named_slots' => 0, 'responsibility_slots' => 0, 'receipts' => 0];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = app(TenantContext::class)->run($this->tenant, fn () => app(ManagementSourceProofs::class)->qa($this->p, [$ref], $budget));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame('unknown', $result['coverage']);
        $this->assertSame('coverage_limit', $result['reason_code']);
        foreach ($queries as $query) {
            $this->assertFalse(str_contains($query['query'], 'jsonb_build_object') && str_contains($query['query'], '"qa_handoff_events"'));
        }
    }

    public function test_native_qa_terminal_originals_are_admitted_before_proof_json_is_loaded(): void
    {
        $this->managementInputs();
        $qa = $this->managementQaExecutionFixture();
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_execution_version', 'qa_execution_version_id' => $qa['execution']['record_id']]);
        $before = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'qa.fixture.manage')->update(['revoked_at' => now(), 'revision' => DB::raw('revision+1')]));
        app(QaReadService::class)->verification($this->actor(), $this->p);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'qa.fixture.manage')->update(['revoked_at' => null, 'revision' => DB::raw('revision-1')]));
        $budget = ['boundaries' => 1, 'references' => 1, 'source_rows' => 0, 'source_proofs' => 32 - $before['budget']['source_proofs'], 'named_slots' => 0, 'responsibility_slots' => 0, 'receipts' => 0];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = app(TenantContext::class)->run($this->tenant, fn () => app(ManagementSourceProofs::class)->qa($this->p, [$ref], $budget));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame('unknown', $result['coverage']);
        $this->assertSame('coverage_limit', $result['reason_code']);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('to_jsonb(c)::text AS change', $query['query']);
        }
    }

    public function test_captured_native_terminal_absence_is_rechecked_before_private_payload_fetch(): void
    {
        $this->managementInputs();
        $qa = $this->managementQaExecutionFixture();
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_execution_version', 'qa_execution_version_id' => $qa['execution']['record_id']]);
        $before = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'qa.fixture.manage')->update(['revoked_at' => now(), 'revision' => DB::raw('revision+1')]));
        app(QaReadService::class)->verification($this->actor(), $this->p);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'qa.fixture.manage')->update(['revoked_at' => null, 'revision' => DB::raw('revision-1')]));
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $before, fn () => app(ManagementPins::class)->loadLocked($this->p, $before));
            $this->fail('New native invalidity must invalidate captured absence.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/select \* from "qa_(?:receipts|execution_versions|definition_versions|plan_versions)"/i', $query['query']);
        }
    }

    public function test_ba_terminal_points_bind_kind_and_full_native_hash_and_charge_each_reference(): void
    {
        $this->managementInputs();
        $ba = $this->managementBaReviewedFixture();
        $reference = array_replace($this->lifecycleReference(), ['kind' => 'ba_review', 'ba_review_id' => $ba['review']['review_id']]);
        $references = [$this->lifecycleReference(), $reference];
        $f = $this->workflowAssigned($references, false);
        $body = $this->workflowSupportBody($f);
        $body['references'] = $references;
        app(ManagementSupportService::class)->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $body, 'terminal-charge-support'), 'terminal-charge-support', 0);
        $request = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ba_request_versions')->where('id', $ba['request']['request_version_id'])->first());
        $nativeHash = hash('sha256', $request->authority_proof);
        $stableHash = ManagementContract::proofHash(json_decode($request->authority_proof, true, 32, JSON_THROW_ON_ERROR));
        $this->assertNotSame($nativeHash, $stableHash);
        app(BaCoordination::class)->run($this->actor(), $this->p, ['ba.read', 'ba.source.read'], false, function () use ($request, $nativeHash, $stableHash) {
            $terminal = app(BaTerminal::class);
            try {
                DB::transaction(fn () => $terminal->observeLocked($this->p, 'answer', $request->id, $nativeHash, 'access_revoked'));
                $this->fail('Native typed guard accepted a request as an answer.');
            } catch (QueryException $e) {
                $this->assertSame('23503', $e->errorInfo[0]);
            }
            $terminal->observeLocked($this->p, 'request', $request->id, str_repeat('0', 64), 'access_revoked');
            $terminal->observeLocked($this->p, 'request', $request->id, $stableHash, 'access_revoked');
        });
        $pins = app(ManagementPins::class);
        $before = $pins->collect($this->actor(), $this->p, [$f['open']['action_id']]);
        $this->assertSame('complete', $before['coverage']);
        $this->assertSame([], $before['refs']['ba_terminal_applicability'] ?? []);
        $evaluated = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $before, function ($proofs) use ($before) {
            $rows = app(ManagementPins::class)->loadLocked($this->p, $before)['rows'];

            return app(ManagementSourceProofs::class)->evaluateLocked($this->p, $before, $rows, $proofs);
        });
        $this->assertSame('current', $evaluated['applicability']);
        $grant = app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'ba.request.manage')->value('id'));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
        app(BaReadService::class)->history($this->actor(), $this->p, $ba['request']['clarification_id']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => null]));
        $after = $pins->collect($this->actor(), $this->p, [$f['open']['action_id']]);
        $this->assertSame('complete', $after['coverage']);
        $terminalRows = count($after['refs']['ba_terminal_applicability']);
        $this->assertGreaterThan(0, $terminalRows);
        $this->assertSame($before['budget']['source_rows'] + 2 * $terminalRows, $after['budget']['source_rows']);
        $this->assertSame($before['budget']['source_proofs'], $after['budget']['source_proofs']);
        app(TenantContext::class)->run($this->tenant, function () use ($after) {
            $bounded = app(ManagementSourceProofs::class)->baTerminalClosure($this->p, $after['refs'], 0);
            $this->assertTrue($bounded['overflow']);
            $this->assertSame([], $bounded['rows']);
        });
    }

    public function test_ba_terminal_captured_absence_is_rechecked_under_management_fence(): void
    {
        $this->managementInputs();
        $ba = $this->managementBaReviewedFixture();
        $reference = array_replace($this->lifecycleReference(), ['kind' => 'ba_review', 'ba_review_id' => $ba['review']['review_id']]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$this->lifecycleReference(), $reference]);
        $this->assertSame('complete', $graph['coverage']);
        $grant = app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'ba.request.manage')->value('id'));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
        app(BaReadService::class)->history($this->actor(), $this->p, $ba['request']['clarification_id']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => null]));
        $this->expectException(ConflictHttpException::class);
        app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, fn () => app(ManagementPins::class)->loadLocked($this->p, $graph));
    }

    public function test_superseded_execution_attempt_is_history_not_current(): void
    {
        $this->managementInputs();
        $qa = $this->managementQaExecutionFixture();
        $old = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_execution_versions')->where('id', $qa['execution']['record_id'])->first());
        $body = array_replace($qa['body'], ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $old->attempt_id]);
        $new = app(QaExecutionService::class)->register($this->actor(), $this->p, $this->managementSourceEnvelope('qa-evidence-v1', 'execution', $body, 'execution-2'), 'execution-2', 0, 1);
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_execution_version', 'qa_execution_version_id' => $old->id]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertFalse($graph['references'][0]['source_current']);
        $ref['qa_execution_version_id'] = $new['record_id'];
        $this->assertTrue(app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref])['references'][0]['source_current']);
    }

    public function test_native_qa_observed_revocation_survives_exact_grant_restoration(): void
    {
        $this->managementInputs();
        $qa = $this->managementQaExecutionFixture();
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_execution_version', 'qa_execution_version_id' => $qa['execution']['record_id']]);
        $before = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->assertTrue($before['references'][0]['source_current']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('project_id', $this->p)->where('purpose', 'qa.fixture.manage')->update(['revoked_at' => now(), 'revision' => DB::raw('revision+1')]));
        app(QaReadService::class)->verification($this->actor(), $this->p);
        $this->assertTrue(app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_applicability')->where('project_id', $this->p)->where('reason_code', 'access_revoked')->exists()));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('project_id', $this->p)->where('purpose', 'qa.fixture.manage')->update(['revoked_at' => null, 'revision' => DB::raw('revision-1')]));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertFalse($graph['references'][0]['source_current']);
        $this->assertNotEmpty($graph['refs']['qa_changes']);
        $evaluation = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, function ($proofs) use ($graph) {
            $loaded = app(ManagementPins::class)->loadLocked($this->p, $graph);

            return app(ManagementSourceProofs::class)->evaluateLocked($this->p, $graph, $loaded['rows'], $proofs);
        });
        $this->assertNull($evaluation['current_outcome']);
        $this->assertSame('stale', $evaluation['reference_results'][0]['applicability']);
        $changedClock = $graph;
        $state = app(TenantContext::class)->run($this->tenant, fn () => app(ManagementSourceProofs::class)->metadata($this->p, 'qa_applicability', [$graph['refs']['qa_applicability'][0]])[0]);
        $state['observed_at'] = CarbonImmutable::parse($state['observed_at'])->addMicrosecond()->format('Y-m-d\TH:i:s.uP');
        $changedClock['row_hashes']['qa_applicability'][$state['id']] = hash('sha256', ManagementContract::canonical($state));
        try {
            app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $changedClock, fn () => app(ManagementPins::class)->loadLocked($this->p, $changedClock));
            $this->fail('Observed timestamp must remain bound by exact metadata hash.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
        try {
            app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $before, fn () => app(ManagementPins::class)->loadLocked($this->p, $before));
            $this->fail('New durable invalidity after capture must conflict.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|truncate)\s+(?:into\s+)?"?(?:qa_|ba_|task_|requirement|criterion|lifecycle)/i', $query['query']);
        }
        DB::disableQueryLog();
    }

    public function test_wrong_proof_format_and_mismatched_subject_are_denied(): void
    {
        $this->managementInputs();
        $first = $this->managementQaHandoff();
        $second = $this->managementQaHandoff('second', configure: false);
        $ids = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->whereIn('id', [$first['id'], $second['id']])->pluck('current_event_id', 'id')->all());
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_handoff_event', 'qa_handoff_event_id' => $ids[$first['id']]]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        foreach (['format', 'subject'] as $invalid) {
            $bad = $graph;
            foreach ($bad['proof_slots'] as &$slot) {
                if ($slot['subject_kind'] === 'qa_handoff_events') {
                    if ($invalid === 'format') {
                        $slot['format'] = 'management-authority-v1';
                    } else {
                        $slot['subject_id'] = $ids[$second['id']];
                    }
                    $slot['key'] = ManagementContract::proofKey($this->tenant, $this->p, $slot);
                }
            }
            unset($slot);
            try {
                app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $bad, fn () => app(ManagementPins::class)->loadLocked($this->p, $bad));
                $this->fail('Wrong format or typed subject must be denied.');
            } catch (\LogicException|ConflictHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_reviewed_human_receipt_original_is_bound_and_hidden_review_actor_denies(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementConfirmation($this->seedManagementBoundary());
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$ids['action']]);
        $original = collect($graph['proof_slots'])->first(fn ($s) => $s['subject_kind'] === 'management_receipts' && $s['actor_id'] !== $this->user);
        $this->assertNotNull($original);
        $changed = $graph;
        foreach ($changed['proof_slots'] as &$slot) {
            if ($slot['key'] === $original['key']) {
                $slot['pinned_proof']['captured_at'] = CarbonImmutable::parse($slot['pinned_proof']['captured_at'])->subMicrosecond()->format('Y-m-d H:i:s.uP');
            }
        }
        unset($slot);
        try {
            app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $changed, fn () => app(ManagementPins::class)->loadLocked($this->p, $changed));
            $this->fail('Human receipt original must bind exactly.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $original['actor_id'])->where('project_id', $this->p)->where('purpose', 'management.outcome.review')->update(['valid_to' => now(), 'revision' => DB::raw('revision+1')]));
        $this->expectException(AccessDeniedHttpException::class);
        app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, fn () => $this->fail('Hidden human receipt must not reach delivery.'));
    }

    public function test_unrelated_handoff_does_not_invalidate_and_exact_source_transition_does(): void
    {
        $this->managementInputs();
        $handoff = $this->managementQaHandoff();
        $event = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->where('id', $handoff['id'])->value('current_event_id'));
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_handoff_event', 'qa_handoff_event_id' => $event]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->managementQaHandoff('unrelated', configure: false);
        $this->assertSame($graph['references'][0]['source_snapshot_hash'], app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref])['references'][0]['source_snapshot_hash']);
        app(QaHandoffService::class)->transition($this->actor(), $this->p, $handoff['id'], ['expected_revision' => 1, 'kind' => 'acknowledged', 'pins' => new \stdClass, 'reason' => 'Synthetic acknowledgement']);
        $current = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->assertSame('complete', $current['coverage']);
        $this->assertFalse($current['references'][0]['source_current']);
        $this->expectException(ConflictHttpException::class);
        app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, fn () => true);
    }

    public static function nestedPrivacyCases(): array
    {
        return [['requester'], ['coordinator']];
    }

    #[DataProvider('nestedPrivacyCases')]
    public function test_saved_ba_named_membership_revocation_withholds_delivery(string $field): void
    {
        $this->managementInputs();
        $named = $this->managementActor($field === 'coordinator' ? 'ba' : 'developer');
        $employee = app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $named->id)->value('employee_id'));
        $ba = $this->managementBaFixture($field === 'requester' ? $employee : null, $field === 'coordinator' ? $employee : null);
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'ba_request_version', 'ba_request_version_id' => $ba['request_version_id']]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->assertSame('complete', $graph['coverage']);
        $category = $field === 'coordinator' ? 'coordinators' : 'request_subjects';
        $this->assertNotEmpty(array_filter($graph['proof_slots'], fn ($s) => in_array($employee, array_column($s['pinned_proof'][$category] ?? [], 'employee_id'), true)));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('project_id', $this->p)->where('employee_id', $employee)->update(['valid_to' => now(), 'revision' => DB::raw('revision+1')]));
        $this->expectException(AccessDeniedHttpException::class);
        app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, fn () => $this->fail('Hidden saved name must not reach delivery.'));
    }

    public function test_lifecycle_only_graph_admits_its_exact_reference(): void
    {
        $this->managementInputs();
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$this->lifecycleReference()]);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertSame(1, $graph['budget']['references']);
        $this->assertSame(['lifecycle'], array_column($graph['references'], 'kind'));
    }

    public function test_same_project_wrong_tuple_does_not_link(): void
    {
        $this->managementInputs();
        $ref = array_replace($this->lifecycleReference(), ['criterion_id' => $this->criteria[1]['id'], 'criterion_version_id' => $this->criteria[1]['version_id']]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->assertSame('unknown', $graph['coverage']);
        $this->assertSame('source_tuple_invalid', $graph['reason_code']);
    }

    public function test_qa_handoff_retains_open_receipt_and_exact_original_proofs(): void
    {
        $this->managementInputs();
        $handoff = $this->managementQaHandoff();
        $event = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->where('id', $handoff['id'])->value('current_event_id'));
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_handoff_event', 'qa_handoff_event_id' => $event]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertCount(1, $graph['refs']['qa_receipts']);
        $this->assertCount(2, $graph['proof_slots']);
        $this->assertSame([true, true], array_column($graph['proof_slots'], 'mandatory'));
        $evaluation = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, function ($proofs) use ($graph) {
            $rows = app(ManagementPins::class)->loadLocked($this->p, $graph);

            return app(ManagementSourceProofs::class)->evaluateLocked($this->p, $graph, $rows['rows'], $proofs);
        });
        $this->assertSame('current', $evaluation['applicability']);
    }

    public function test_ba_request_retains_party_and_receipt_originals(): void
    {
        $this->managementInputs();
        $request = $this->managementBaFixture();
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'ba_request_version', 'ba_request_version_id' => $request['request_version_id']]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertCount(1, $graph['refs']['ba_source_party_versions']);
        $this->assertCount(2, $graph['refs']['ba_receipts']);
        $this->assertNotEmpty($graph['proof_slots']);
    }

    public function test_qa_execution_retains_selected_plan_entry_and_definition_review(): void
    {
        $this->managementInputs();
        $fixture = $this->managementQaExecutionFixture();
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_execution_version', 'qa_execution_version_id' => $fixture['execution']['record_id']]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertContains($fixture['plan']['id'], $graph['refs']['qa_plan_versions'] ?? []);
        $this->assertCount(3, $graph['refs']['qa_plan_entry_snapshots']);
        $this->assertCount(1, $graph['refs']['qa_plan_entry_definitions']);
        $this->assertContains($fixture['planReview']['id'], $graph['refs']['qa_reviews']);
    }

    public function test_singleton_project_state_graph_uses_project_identity(): void
    {
        $this->managementInputs();
        $head = app(TenantContext::class)->run($this->tenant, fn () => (array) DB::table('lifecycle_project_state')->where('project_id', $this->p)->first(['current_baseline_id']));
        $graph = ['coverage' => 'complete', 'refs' => ['lifecycle_project_state' => [$this->p]], 'heads' => ['lifecycle_project_state' => [$this->p => $head]]];
        $result = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, fn () => true);
        $this->assertTrue($result);
    }

    public function test_ba_scope_origin_proofs_remain_mandatory_and_dependency_unresolved(): void
    {
        $this->managementInputs();
        $fixture = $this->managementBaReviewedFixture();
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'ba_review', 'ba_review_id' => $fixture['review']['review_id']]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertCount(1, $graph['refs']['ba_scope_obligations']);
        $evaluation = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, function ($proofs) use ($graph) {
            $rows = app(ManagementPins::class)->loadLocked($this->p, $graph);

            return app(ManagementSourceProofs::class)->evaluateLocked($this->p, $graph, $rows['rows'], $proofs);
        });
        $this->assertSame('scope_change_required', $evaluation['reference_results'][0]['underlying_dependency_status']);
    }

    public function test_same_project_wrong_tuple_and_unapproved_highest_version_do_not_link(): void
    {
        $this->managementInputs();
        $handoff = $this->managementQaHandoff();
        $handoffEvent = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->where('id', $handoff['id'])->value('current_event_id'));
        $qa = $this->managementQaExecutionFixture();
        $ba = $this->managementBaReviewedFixture();
        $subjects = ['lifecycle' => null, 'qa_handoff_event' => $handoffEvent, 'qa_defect_event' => $qa['defectEvent'], 'qa_execution_version' => $qa['execution']['record_id'], 'ba_request_version' => $ba['request']['request_version_id'], 'ba_answer_version' => $ba['answer']['answer_version_id'], 'ba_review' => $ba['review']['review_id']];
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($subjects as $kind => $id) {
            $ref = array_replace($this->lifecycleReference(), ['kind' => $kind]);
            if ($id) {
                $ref[$kind.'_id'] = $id;
            }
            $valid = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
            $this->assertSame('complete', $valid['coverage'], $kind);
            $wrong = array_replace($ref, ['criterion_id' => $this->criteria[1]['id'], 'criterion_version_id' => $this->criteria[1]['version_id']]);
            $this->assertSame('source_tuple_invalid', app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$wrong])['reason_code'], $kind);
        }
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|truncate)\s+(?:into\s+)?"?(?:qa_|ba_|task_|requirement|criterion|lifecycle)/i', $query['query']);
        }
        DB::disableQueryLog();
        // A newer draft remains outside the approved-current association.
        app(RequirementService::class)->updateDraft($this->actor(), $this->p, $this->draft['id'], $this->requirement(), 1);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$this->lifecycleReference()]);
        $this->assertSame('complete', $graph['coverage']);
    }

    public function test_qa_selection_change_and_cross_project_singleton_identity_are_denied(): void
    {
        $this->managementInputs();
        $qa = $this->managementQaExecutionFixture();
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_execution_version', 'qa_execution_version_id' => $qa['execution']['record_id']]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $bad = $graph;
        $bad['refs']['qa_project_state'] = [(string) Str::uuid()];
        try {
            app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $bad, fn () => true);
            $this->fail('Cross-project singleton must be denied.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $graph['heads']['qa_project_state'][$this->p]['selection_revision'] = 0;
        try {
            app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, fn () => true);
            $this->fail('Changed selected head must conflict.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_original_full_proof_snapshot_cannot_be_rebound_by_changing_capture_time(): void
    {
        $this->managementInputs();
        $handoff = $this->managementQaHandoff();
        $event = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->where('id', $handoff['id'])->value('current_event_id'));
        $ref = array_replace($this->lifecycleReference(), ['kind' => 'qa_handoff_event', 'qa_handoff_event_id' => $event]);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$ref]);
        $graph['proof_slots'][0]['pinned_proof']['captured_at'] = CarbonImmutable::parse($graph['proof_slots'][0]['pinned_proof']['captured_at'])->subMicrosecond()->format('Y-m-d H:i:s.uP');
        $this->expectException(ConflictHttpException::class);
        app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, fn () => app(ManagementPins::class)->loadLocked($this->p, $graph));
    }

    public function test_missing_e03_right_or_named_membership_withholds_the_entire_graph(): void
    {
        $this->managementInputs();
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$this->lifecycleReference()]);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('project_id', $this->p)->where('purpose', 'progress.read')->update(['valid_to' => now(), 'revision' => DB::raw('revision+1')]));
        $this->expectException(AccessDeniedHttpException::class);
        app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, fn () => app(ManagementPins::class)->loadLocked($this->p, $graph));
    }

    public function test_lifecycle_closed_graph_reloads_exact_association_and_has_no_source_writes(): void
    {
        $this->managementInputs();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$this->lifecycleReference()]);
        $loaded = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, fn ($proofs) => app(ManagementPins::class)->loadLocked($this->p, $graph));
        $this->assertSame('complete', $loaded['coverage']);
        $this->assertCount(1, $graph['refs']['task_criterion_links']);
        $this->assertCount(1, $graph['refs']['requirement_version_criteria']);
        $this->assertLessThanOrEqual(200, count(DB::getQueryLog()));
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|truncate)\s+(?:into\s+)?(?:"?)(?:qa_|ba_|task_|requirement|criterion|lifecycle)/i', $query['query']);
        }
        DB::disableQueryLog();
    }
}
