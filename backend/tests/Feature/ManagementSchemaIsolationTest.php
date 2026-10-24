<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementContract;
use App\Domain\Management\ManagementTerminal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ManagementTestCase;

class ManagementSchemaIsolationTest extends ManagementTestCase
{
    public function test_generation_is_server_assigned_and_cannot_bind_a_foreign_project_event(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementBoundary();
        $other = $this->project();
        $this->managementRun(function () use ($ids, $other): void {
            $event = DB::table('management_events')->where('action_id', $ids['action'])->orderByDesc('revision')->first();
            foreach (['forged', 'wrong_project', 'wrong_generation'] as $case) {
                try {
                    DB::transaction(function () use ($case, $event, $other): void {
                        if ($case === 'forged') {
                            DB::table('management_terminal_applicability')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'kind' => 'proof', 'subject_id' => (string) Str::uuid(), 'proof_hash' => str_repeat('a', 64), 'reason_code' => 'access_revoked', 'project_generation' => 100]);
                        } else {
                            DB::table('management_project_state')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $other, 'generation' => $case === 'wrong_generation' ? 100 : $event->project_generation, 'current_event_id' => $event->id]);
                        }
                        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                    });
                    $this->fail('Forged or foreign generation head admitted.');
                } catch (QueryException $e) {
                    $this->assertContains($e->errorInfo[0], ['23503', '23514']);
                }
            }
        });
    }

    public function test_existing_source_receipt_lookup_and_alias_do_not_advance_generation(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementBoundary();
        $this->managementRun(function () use ($ids): void {
            $before = DB::table('management_project_state')->where('project_id', $this->p)->value('generation');
            $receipt = DB::table('management_receipts')->where('project_id', $this->p)->where('source_instance_id', $this->managementSource)->where('family', 'support')->where('object_id', 'support.record')->where('source_revision', 1)->first();
            $this->assertSame($ids['support'], $receipt->support_id);
            DB::table('management_request_bindings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'requester_id' => $this->user, 'operation' => 'support.record', 'key' => 'synthetic-replay', 'input_hash' => $receipt->input_hash, 'receipt_id' => $receipt->id]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->assertSame($before, DB::table('management_project_state')->where('project_id', $this->p)->value('generation'));
            $this->assertSame(3, DB::table('management_events')->where('action_id', $ids['action'])->count());
        });
    }

    public function test_two_context_events_advance_contiguously_in_one_transaction(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementConfirmation($this->seedManagementBoundary());
        $this->managementRun(function ($proofs) use ($ids): void {
            for ($revision = 5; $revision <= 6; $revision++) {
                $previous = (array) DB::table('management_events')->where('action_id', $ids['action'])->where('revision', $revision - 1)->first();
                unset($previous['project_generation']);
                $receipt = (string) Str::uuid();
                $event = (string) Str::uuid();
                $body = ['operation' => 'context.add', 'expected_revision' => $revision - 1, 'context' => 'Synthetic sequential context'];
                $auth = ['actor_id' => $this->user, 'authority_proof' => ManagementContract::canonical($proofs['current']['proof'])];
                DB::table('management_receipts')->insert(['id' => $receipt, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'variant' => 'human', 'operation' => 'context.add', 'input_hash' => $ids['hash'], 'envelope' => ManagementContract::canonical($body), 'action_id' => $ids['action'], 'event_id' => $event] + $auth);
                DB::table('management_events')->insert(array_replace($previous, ['id' => $event, 'revision' => $revision, 'operation' => 'context.add', 'receipt_id' => $receipt, 'context' => $body['context'], 'reason' => null, 'subject_support_id' => null], $auth));
                DB::table('management_actions')->where('id', $ids['action'])->update(['revision' => $revision, 'current_event_id' => $event]);
            }
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $state = DB::table('management_project_state')->where('project_id', $this->p)->first();
            $this->assertSame(6, $state->generation);
            $this->assertSame($event, $state->current_event_id);
            $this->assertNull($state->current_terminal_id);
        });
    }

    public function test_exact_reject_retains_subject_but_clears_current_support(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementConfirmation($this->seedManagementBoundary());
        $this->managementRun(function () use ($ids): void {
            $this->writeOutcomeDecision($ids, 'outcome.reject');
            $root = DB::table('management_actions')->where('id', $ids['action'])->first();
            $this->assertNull($root->current_support_id);
            $this->assertNull($root->current_outcome);
            $this->assertSame('rejected', $root->review_status);
        });
    }

    public function test_exact_revoke_projects_its_own_review(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementConfirmation($this->seedManagementBoundary());
        $this->managementRun(function () use ($ids): void {
            $this->writeOutcomeDecision($ids, 'outcome.revoke');
            $this->assertSame('revoked', DB::table('management_actions')->where('id', $ids['action'])->value('review_status'));
        });
    }

    public function test_reject_cannot_substitute_another_subject_support(): void
    {
        $this->managementInputs();
        $first = $this->seedManagementBoundary();
        $ids = $this->seedManagementConfirmation($this->seedManagementSourceSubject($first, 2));
        $this->expectException(QueryException::class);
        $this->managementRun(fn () => $this->writeOutcomeDecision($ids, 'outcome.reject', $first['support']));
    }

    private function writeOutcomeDecision(array $ids, string $operation, ?string $subject = null): void
    {
        $old = (array) DB::table('management_reviews')->where('id', $ids['review'])->first();
        $previous = (array) DB::table('management_events')->where('action_id', $ids['action'])->orderByDesc('revision')->first();
        unset($previous['project_generation']);
        $receipt = (string) Str::uuid();
        $review = (string) Str::uuid();
        $event = (string) Str::uuid();
        $body = array_intersect_key($old, array_flip(['action_version_id', 'action_hash', 'assignment_id', 'assignment_hash', 'support_id', 'support_hash', 'references_hash']));
        $body += ['operation' => $operation, 'expected_revision' => $previous['revision'], 'reason' => 'Synthetic review decision'];
        if ($operation === 'outcome.revoke') {
            $body['predecessor_review_id'] = $ids['review'];
        }
        DB::table('management_receipts')->insert(['id' => $receipt, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'variant' => 'human', 'operation' => $operation, 'input_hash' => $ids['hash'], 'envelope' => ManagementContract::canonical($body), 'action_id' => $ids['action'], 'review_id' => $review, 'event_id' => $event, 'actor_id' => $old['actor_id'], 'authority_proof' => $old['authority_proof']]);
        DB::table('management_reviews')->insert(array_replace($old, ['id' => $review, 'operation' => $operation, 'receipt_id' => $receipt, 'reason' => $body['reason'], 'predecessor_review_id' => $body['predecessor_review_id'] ?? null]));
        $reject = $operation === 'outcome.reject';
        $new = array_replace($previous, ['id' => $event, 'revision' => $previous['revision'] + 1, 'operation' => $operation, 'receipt_id' => $receipt, 'state' => $reject ? 'assigned' : 'awaiting_review', 'support_status' => $reject ? 'none' : 'recorded', 'review_status' => $reject ? 'rejected' : 'revoked', 'current_outcome' => null, 'reason' => $body['reason'], 'review_id' => $review, 'support_id' => $reject ? null : $ids['support'], 'subject_support_id' => $reject ? ($subject ?? $ids['support']) : null]);
        unset($new['project_generation']);
        DB::table('management_events')->insert($new);
        DB::table('management_actions')->where('id', $ids['action'])->update(['revision' => $new['revision'], 'current_event_id' => $event, 'state' => $new['state'], 'support_status' => $new['support_status'], 'review_status' => $new['review_status'], 'current_outcome' => null, 'current_review_id' => $review, 'current_support_id' => $new['support_id']]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function test_terminal_observation_advances_generation_once_and_rolls_back_on_failure(): void
    {
        $this->managementInputs();
        $subject = (string) Str::uuid();
        $observe = fn () => app(ManagementTerminal::class)->observeLocked($this->p, 'proof', $subject, str_repeat('a', 64), 'access_revoked');
        $this->managementRun(function () use ($observe): void {
            $observe();
            $observe();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->assertSame(1, (int) DB::table('management_project_state')->where('project_id', $this->p)->value('generation'));
            $this->assertSame(1, DB::table('management_terminal_applicability')->count());
            $this->assertSame(0, DB::table('management_events')->count());
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });
        try {
            $this->managementRun(function (): void {
                app(ManagementTerminal::class)->observeLocked($this->p, 'proof', (string) Str::uuid(), str_repeat('b', 64), 'source_revoked');
                throw new \LogicException('Synthetic rollback');
            });
        } catch (\LogicException $e) {
            $this->assertSame('Synthetic rollback', $e->getMessage());
        }
        $this->managementRun(fn () => $this->assertSame(1, (int) DB::table('management_project_state')->where('project_id', $this->p)->value('generation')));
    }

    public function test_outcome_receipt_cannot_substitute_an_old_confirm_review(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementConfirmation($this->seedManagementBoundary());
        $this->expectException(QueryException::class);
        $this->managementRun(function () use ($ids): void {
            $old = (array) DB::table('management_reviews')->where('id', $ids['review'])->first();
            $receipt = (string) Str::uuid();
            $review = (string) Str::uuid();
            $event = (string) Str::uuid();
            $body = array_intersect_key($old, array_flip(['action_version_id', 'action_hash', 'assignment_id', 'assignment_hash', 'support_id', 'support_hash', 'references_hash']));
            $body += ['operation' => 'outcome.revoke', 'expected_revision' => 4, 'reason' => 'Synthetic revocation', 'predecessor_review_id' => $ids['review']];
            DB::table('management_receipts')->insert(['id' => $receipt, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'variant' => 'human', 'operation' => 'outcome.revoke', 'input_hash' => $ids['hash'], 'envelope' => ManagementContract::canonical($body), 'action_id' => $ids['action'], 'review_id' => $review, 'event_id' => $event, 'actor_id' => $old['actor_id'], 'authority_proof' => $old['authority_proof']]);
            DB::table('management_reviews')->insert(array_replace($old, ['id' => $review, 'operation' => 'outcome.revoke', 'receipt_id' => $receipt, 'reason' => $body['reason'], 'predecessor_review_id' => $ids['review']]));
            $previous = (array) DB::table('management_events')->where('action_id', $ids['action'])->orderByDesc('revision')->first();
            unset($previous['project_generation']);
            unset($previous['project_generation']);
            DB::table('management_events')->insert(array_replace($previous, ['id' => $event, 'revision' => 5, 'operation' => 'outcome.revoke', 'receipt_id' => $receipt, 'state' => 'awaiting_review', 'review_status' => 'revoked', 'current_outcome' => null, 'reason' => $body['reason']]));
            DB::table('management_actions')->where('id', $ids['action'])->update(['revision' => 5, 'current_event_id' => $event, 'state' => 'awaiting_review', 'review_status' => 'revoked', 'current_outcome' => null]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public function test_new_partial_support_cannot_project_old_completed_support(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementBoundary();
        $this->expectException(QueryException::class);
        $this->seedManagementSourceSubject($ids, 2, false, true);
    }

    public function test_orphan_event_cannot_leave_the_root_unchanged(): void
    {
        $this->assertContextAtomicRejection(false, 1);
    }

    public function test_event_revision_cannot_skip_its_predecessor(): void
    {
        $this->assertContextAtomicRejection(false, 2);
    }

    public function test_events_advance_generation_without_missing_invalidation(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementBoundary();
        $this->managementRun(function () use ($ids): void {
            $this->assertSame(3, (int) DB::table('management_project_state')->where('project_id', $this->p)->value('generation'));
            $this->assertSame(3, DB::table('management_events')->where('action_id', $ids['action'])->count());
        });
    }

    public function test_generation_cannot_advance_without_an_event(): void
    {
        $this->managementInputs();
        $this->seedManagementBoundary();
        $this->expectException(QueryException::class);
        $this->managementRun(function (): void {
            if (! DB::table('management_project_state')->where('project_id', $this->p)->exists()) {
                DB::table('management_project_state')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p]);
            }
            DB::table('management_project_state')->where('project_id', $this->p)->increment('generation');
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    private function assertContextAtomicRejection(bool $advance, int $delta): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementConfirmation($this->seedManagementBoundary());
        $this->expectException(QueryException::class);
        $this->managementRun(function ($proofs) use ($ids, $advance, $delta): void {
            $previous = (array) DB::table('management_events')->where('action_id', $ids['action'])->orderByDesc('revision')->first();
            unset($previous['project_generation']);
            $receipt = (string) Str::uuid();
            $event = (string) Str::uuid();
            $body = $delta === 1 ? ['operation' => 'context.add', 'expected_revision' => 4, 'context' => 'Synthetic orphan context'] : ['operation' => 'action.withdraw', 'expected_revision' => 4, 'reason' => 'Synthetic skipped revision'];
            $auth = ['actor_id' => $this->user, 'authority_proof' => ManagementContract::canonical($proofs['current']['proof'])];
            DB::table('management_receipts')->insert(['id' => $receipt, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'variant' => 'human', 'operation' => $body['operation'], 'input_hash' => $ids['hash'], 'envelope' => ManagementContract::canonical($body), 'action_id' => $ids['action'], 'event_id' => $event] + $auth);
            DB::table('management_events')->insert(array_replace($previous, ['id' => $event, 'revision' => 4 + $delta, 'operation' => $body['operation'], 'receipt_id' => $receipt, 'context' => $body['context'] ?? null, 'reason' => $body['reason'] ?? null, 'subject_support_id' => null], $delta === 2 ? ['state' => 'withdrawn', 'current_outcome' => null] : [], $auth));
            if ($advance) {
                DB::table('management_actions')->where('id', $ids['action'])->update(['revision' => 4 + $delta, 'current_event_id' => $event]);
            }
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public function test_force_rls_and_runtime_permissions_protect_all_management_tables(): void
    {
        $tables = DB::select("select c.relname,c.relrowsecurity,c.relforcerowsecurity,r.rolname as owner from pg_class c join pg_namespace n on n.oid=c.relnamespace join pg_roles r on r.oid=c.relowner where n.nspname='public' and c.relkind='r' and c.relname like 'management_%' order by c.relname");
        $this->assertCount(13, $tables, 'The management foundation is absent.');
        foreach ($tables as $table) {
            $this->assertTrue($table->relrowsecurity && $table->relforcerowsecurity);
            $this->assertNotSame('monitoring_ai_app', $table->owner);
            $this->assertFalse(DB::scalar('select has_table_privilege(current_user,?,?)', [$table->relname, 'DELETE,TRUNCATE']));
            $this->assertSame(0, DB::table($table->relname)->count());
        }
    }

    public function test_complete_preallocated_output_and_reference_graph_commits(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementBoundary();
        $this->managementRun(function () use ($ids): void {
            $this->assertSame(3, DB::table('management_events')->where('action_id', $ids['action'])->count());
            $this->assertSame('awaiting_review', DB::table('management_actions')->where('id', $ids['action'])->value('state'));
            $this->assertNull(DB::table('management_actions')->where('id', $ids['action'])->value('current_outcome'));
        });
    }

    public function test_runtime_cannot_update_delete_or_truncate_immutable_facts(): void
    {
        $this->managementInputs();
        $id = (string) Str::uuid();
        $this->managementRun(fn () => app(ManagementTerminal::class)->observeLocked($this->p, 'proof', $id, str_repeat('a', 64), 'access_revoked'));
        foreach (['UPDATE management_terminal_applicability SET reason_code=\'source_revoked\'', 'DELETE FROM management_terminal_applicability', 'TRUNCATE management_terminal_applicability'] as $sql) {
            try {
                $this->managementRun(fn () => DB::statement($sql));
                $this->fail('Immutable management fact changed.');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
        }
        $this->managementRun(fn () => $this->assertSame(1, DB::table('management_terminal_applicability')->count()));
    }

    public function test_direct_query_builder_wrong_lineage_and_cross_tenant_are_rejected(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementBoundary();
        $this->managementRun(function () use ($ids): void {
            $reference = (array) DB::table('management_references')->where('reference_set_id', $ids['action_set'])->first();
            foreach (['wrong_tuple', 'cross_tenant'] as $case) {
                $bad = $reference;
                $bad['id'] = (string) Str::uuid();
                $bad['ordinal'] = 2;
                if ($case === 'wrong_tuple') {
                    $bad['criterion_id'] = $this->criteria[1]['id'];
                    $bad['criterion_version_id'] = $this->criteria[1]['version_id'];
                } else {
                    $bad['tenant_id'] = (string) Str::uuid();
                }
                try {
                    DB::transaction(fn () => DB::table('management_references')->insert($bad));
                    $this->fail('Invalid scoped tuple admitted.');
                } catch (QueryException $e) {
                    $this->assertContains($e->errorInfo[0], $case === 'wrong_tuple' ? ['23503'] : ['23503', '42501']);
                }
            }
        });
    }

    public function test_same_employee_cannot_review_through_second_account(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementBoundary();
        $reviewer = $this->managementActor('pm', $this->employee);
        $this->managementRun(function ($proofs) use ($ids, $reviewer): void {
            $proof = $proofs['current']['proof'];
            $proof['actor_id'] = $reviewer->id;
            $body = ['operation' => 'outcome.confirm', 'expected_revision' => 3, 'action_version_id' => $ids['version'], 'action_hash' => $ids['hash'], 'assignment_id' => $ids['assignment'], 'assignment_hash' => $ids['hash'], 'support_id' => $ids['support'], 'support_hash' => $ids['hash'], 'references_hash' => $ids['hash'], 'reason' => 'Synthetic review'];
            try {
                DB::transaction(function () use ($proof, $body, $ids, $reviewer): void {
                    $receipt = (string) Str::uuid();
                    $review = (string) Str::uuid();
                    $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
                    DB::table('management_receipts')->insert(['id' => $receipt, 'variant' => 'human', 'operation' => 'outcome.confirm', 'input_hash' => $ids['hash'], 'envelope' => ManagementContract::canonical($body), 'actor_id' => $reviewer->id, 'authority_proof' => ManagementContract::canonical($proof), 'action_id' => $ids['action'], 'review_id' => $review, 'event_id' => (string) Str::uuid()] + $scope);
                    unset($body['expected_revision']);
                    DB::table('management_reviews')->insert(['id' => $review, 'action_id' => $ids['action'], 'reviewer_id' => $this->employee, 'receipt_id' => $receipt, 'review_hash' => $ids['hash'], 'actor_id' => $reviewer->id, 'authority_proof' => ManagementContract::canonical($proof)] + $body + $scope);
                });
                $this->fail('A second account bypassed employee independence.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
                $this->assertStringContainsString('independent employee', $e->getMessage());
            }
        });
    }

    public function test_context_preserves_a_confirmed_projection(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementConfirmation($this->seedManagementBoundary());
        $this->managementRun(function ($proofs) use ($ids): void {
            $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
            $auth = ['actor_id' => $this->user, 'authority_proof' => ManagementContract::canonical($proofs['current']['proof'])];
            $body = ['operation' => 'context.add', 'expected_revision' => 4, 'context' => 'Synthetic additional context'];
            $receipt = (string) Str::uuid();
            $event = (string) Str::uuid();
            DB::table('management_receipts')->insert(['id' => $receipt, 'variant' => 'human', 'operation' => 'context.add', 'input_hash' => $ids['hash'], 'envelope' => ManagementContract::canonical($body), 'action_id' => $ids['action'], 'event_id' => $event] + $auth + $scope);
            DB::table('management_events')->insert(['id' => $event, 'action_id' => $ids['action'], 'revision' => 5, 'episode' => 1, 'operation' => 'context.add', 'state' => 'reviewed', 'support_status' => 'recorded', 'review_status' => 'confirmed', 'current_outcome' => 'completed', 'action_version_id' => $ids['version'], 'assignment_id' => $ids['assignment'], 'support_id' => $ids['support'], 'review_id' => $ids['review'], 'receipt_id' => $receipt, 'context' => $body['context']] + $auth + $scope);
            DB::table('management_actions')->where('id', $ids['action'])->update(['revision' => 5, 'current_event_id' => $event]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->assertSame('completed', DB::table('management_actions')->where('id', $ids['action'])->value('current_outcome'));
        });
    }

    public function test_strictly_late_source_subject_preserves_exact_confirmed_projection(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementSourceSubject($this->seedManagementBoundary(), 3);
        $ids = $this->seedManagementSourceSubject($ids, 1, false, false, 'independent-complete');
        $ids = $this->seedManagementConfirmation($ids);
        $late = $this->seedManagementSourceSubject($ids, 2, true);
        $this->managementRun(function () use ($late, $ids): void {
            $event = DB::table('management_events')->where('action_id', $ids['action'])->orderByDesc('revision')->first();
            $this->assertSame($late['subject'], $event->subject_support_id);
            $this->assertSame($ids['support'], $event->support_id);
            $this->assertNotSame($event->subject_support_id, $event->support_id);
            $this->assertSame($ids['review'], $event->review_id);
            $this->assertSame('partial', $event->current_outcome);
            $heads = DB::table('management_source_heads')->where('project_id', $this->p)->get()->keyBy('object_id');
            $this->assertSame(3, $heads['support.record']->source_revision);
            $this->assertFalse($heads['support.record']->complete);
            $this->assertSame(1, $heads['independent-complete']->source_revision);
            $this->assertTrue($heads['independent-complete']->complete);
        });
    }

    public function test_gapped_fresh_subject_is_unverified_and_cannot_be_confirmed(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementSourceSubject($this->seedManagementBoundary(), 3);
        $this->managementRun(function () use ($ids): void {
            $root = DB::table('management_actions')->where('id', $ids['action'])->first();
            $this->assertSame($ids['subject'], $root->current_support_id);
            $this->assertSame('awaiting_review', $root->state);
            $this->assertNull($root->current_outcome);
            $head = DB::table('management_source_heads')->where('project_id', $this->p)->where('source_instance_id', $this->managementSource)->where('family', 'support')->where('object_id', 'support.record')->first();
            $this->assertNotNull($head);
            $this->assertSame(3, $head->source_revision);
            $this->assertFalse($head->complete);
            $this->assertTrue(DB::table('management_source_heads')->where('project_id', $this->p)->where('source_instance_id', $this->managementSource)->where('family', 'action')->where('object_id', 'action.open')->value('complete'));
        });
        $this->expectException(QueryException::class);
        $this->seedManagementConfirmation($ids);
    }

    public function test_context_cannot_change_confirmed_pointer_state_or_outcome(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementConfirmation($this->seedManagementBoundary());
        $this->managementRun(function ($proofs) use ($ids): void {
            foreach ([['state' => 'assigned', 'review_status' => 'unverified', 'current_outcome' => null, 'review_id' => null], ['current_outcome' => 'partial'], ['support_id' => null, 'review_id' => null, 'current_outcome' => null, 'state' => 'assigned', 'support_status' => 'none', 'review_status' => 'unverified']] as $change) {
                try {
                    DB::transaction(function () use ($proofs, $ids, $change): void {
                        $previous = (array) DB::table('management_events')->where('action_id', $ids['action'])->orderByDesc('revision')->first();
                        unset($previous['project_generation']);
                        $receipt = (string) Str::uuid();
                        $event = (string) Str::uuid();
                        $body = ['operation' => 'context.add', 'expected_revision' => 4, 'context' => 'Synthetic invalid projection edit'];
                        $auth = ['actor_id' => $this->user, 'authority_proof' => ManagementContract::canonical($proofs['current']['proof'])];
                        DB::table('management_receipts')->insert(['id' => $receipt, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'variant' => 'human', 'operation' => 'context.add', 'input_hash' => $ids['hash'], 'envelope' => ManagementContract::canonical($body), 'action_id' => $ids['action'], 'event_id' => $event] + $auth);
                        $new = array_replace($previous, ['id' => $event, 'revision' => 5, 'operation' => 'context.add', 'receipt_id' => $receipt, 'context' => $body['context'], 'reason' => null, 'subject_support_id' => null], $auth, $change);
                        DB::table('management_events')->insert($new);
                        $projection = ['revision' => 5, 'current_event_id' => $event];
                        foreach ($change as $field => $value) {
                            $projection[match ($field) {
                                'support_id' => 'current_support_id', 'review_id' => 'current_review_id', default => $field
                            }] = $value;
                        }
                        DB::table('management_actions')->where('id', $ids['action'])->update($projection);
                        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                    });
                    $this->fail('Context changed the exact confirmed projection.');
                } catch (QueryException $e) {
                    $this->assertSame('23514', $e->errorInfo[0]);
                }
            }
        });
    }
}
