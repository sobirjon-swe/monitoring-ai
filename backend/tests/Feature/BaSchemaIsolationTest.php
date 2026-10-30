<?php

namespace Tests\Feature;

use App\Domain\Ba\BaContract;
use App\Domain\Ba\BaPins;
use App\Domain\Ba\BaReceiptWriter;
use App\Domain\Lifecycle\TaskService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\BaTestCase;

class BaSchemaIsolationTest extends BaTestCase
{
    public function test_source_answer_response_time_is_exact_receipt_content(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->seedBaRequest();
        $answer = $this->seedBaAnswer($request);
        $envelope = app(TenantContext::class)->run($this->tenant, fn () => json_decode(DB::table('ba_receipts')->where('id', $answer['receipt_id'])->value('envelope'), true));
        $envelope['object_id'] = 'second-answer-time';
        $envelope['body']['expected_revision'] = 2;
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, [$request['clarification_id']]);
        $this->baRun(function ($proofs) use ($envelope, $answer) {
            $proof = $proofs['current']['proof'];
            $proof['sources'] = $proofs['sources']['proof']['sources'];
            $ticket = app(BaReceiptWriter::class)->beginSourceLocked($this->actor(), $this->p, $envelope, 'answer-time', 0, $proof);
            $row = (array) DB::table('ba_answer_versions')->find($answer['answer_version_id']);
            $row['id'] = (string) Str::uuid();
            $row['receipt_id'] = $ticket['receipt_id'];
            $row['object_id'] = $envelope['object_id'];
            $row['authority_proof'] = BaContract::canonical($proof);
            $row['response_at'] = '2026-10-06T00:00:00.000000Z';
            $this->assertBaSqlState('23514', fn () => DB::table('ba_answer_versions')->insert($row));
            $row['response_at'] = $envelope['body']['response_at'];
            DB::table('ba_answer_versions')->insert($row);
            $this->assertSame($row['receipt_id'], DB::table('ba_answer_versions')->where('id', $row['id'])->value('receipt_id'));
        }, $graph['requests'], ['ba.answer.record', 'ba.source.read']);
    }

    public function test_human_event_envelope_root_content_and_revision_are_exact_sql_boundaries(): void
    {
        $this->baInputs();
        $this->baRole();
        foreach (['context.add', 'request.withdraw'] as $kind) {
            $a = $this->seedBaRequest('event-a-'.$kind);
            $b = $this->seedBaRequest('event-b-'.$kind);
            $this->baRun(function ($proofs) use ($a, $b, $kind) {
                $field = $kind === 'context.add' ? 'context' : 'reason';
                $body = ['clarification_id' => $a['clarification_id'], 'expected_revision' => 1, 'kind' => $kind, $field => 'Exact synthetic content'];
                $writer = app(BaReceiptWriter::class);
                $ticket = $writer->beginHumanLocked($this->actor(), $this->p, $kind, $body, $kind);
                $row = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'clarification_id' => $b['clarification_id'], 'revision' => 2, 'kind' => $kind, 'state' => $kind === 'context.add' ? 'open' : 'withdrawn', 'request_version_id' => $b['request_version_id'], $field => 'Exact synthetic content', 'receipt_id' => $ticket['receipt_id'], 'actor_id' => $this->user, 'authority_proof' => BaContract::canonical($proofs['current']['proof'])];
                $this->assertBaSqlState('23514', fn () => DB::table('ba_events')->insert($row));
                $row['clarification_id'] = $a['clarification_id'];
                $row['request_version_id'] = $a['request_version_id'];
                $row[$field] = 'Substituted content';
                $this->assertBaSqlState('23514', fn () => DB::table('ba_events')->insert($row));
                $row[$field] = 'Exact synthetic content';
                $row['revision'] = 3;
                $this->assertBaSqlState('23514', fn () => DB::table('ba_events')->insert($row));
                $row['revision'] = 2;
                DB::table('ba_events')->insert($row);
                $writer->bindOutputLocked($ticket, ['receipt_id' => $ticket['receipt_id'], 'clarification_id' => $a['clarification_id'], 'event_id' => $row['id']]);
                $this->assertSame($a['clarification_id'], DB::table('ba_request_bindings')->where('receipt_id', $ticket['receipt_id'])->value('clarification_id'));
            }, purposes: ['ba.request.manage']);
        }
    }

    public function test_human_revise_outer_root_and_nested_requested_time_are_sql_boundaries(): void
    {
        $this->baInputs();
        $this->baRole();
        $a = $this->seedBaRequest('revise-a');
        $b = $this->seedBaRequest('revise-b');
        $this->baRun(function ($proofs) use ($a, $b) {
            $body = ['clarification_id' => $a['clarification_id'], 'expected_revision' => 1, 'kind' => 'request.revise', 'request' => $a['body'], 'reason' => 'Synthetic revision'];
            $ticket = app(BaReceiptWriter::class)->beginHumanLocked($this->actor(), $this->p, 'request.revise', $body, 'revise');
            $row = (array) DB::table('ba_request_versions')->find($a['request_version_id']);
            $row['id'] = (string) Str::uuid();
            $row['receipt_id'] = $ticket['receipt_id'];
            $row['revision'] = 2;
            $row['clarification_id'] = $b['clarification_id'];
            $row['authority_proof'] = BaContract::canonical($proofs['current']['proof']);
            $this->assertBaSqlState('23514', fn () => DB::table('ba_request_versions')->insert($row));
            $row['clarification_id'] = $a['clarification_id'];
            $row['requested_at'] = '2026-10-06T00:00:00.000000Z';
            $this->assertBaSqlState('23514', fn () => DB::table('ba_request_versions')->insert($row));
            $row['requested_at'] = $a['body']['requested_at'];
            DB::table('ba_request_versions')->insert($row);
            $this->assertSame($a['clarification_id'], DB::table('ba_request_versions')->where('id', $row['id'])->value('clarification_id'));
        }, purposes: ['ba.request.manage']);
    }

    public function test_source_open_receipt_requires_exact_root_source_object_and_requested_time(): void
    {
        $this->baInputs();
        $this->baRole();
        $a = $this->seedBaRequest();
        $envelope = $this->baEnvelope('request.open', $a['body'], 'new-source-root');
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, [], [$envelope['source_instance_id']]);
        $this->baRun(function ($proofs) use ($a, $envelope) {
            $proof = $proofs['current']['proof'];
            $proof['sources'] = $proofs['sources']['proof']['sources'];
            $ticket = app(BaReceiptWriter::class)->beginSourceLocked($this->actor(), $this->p, $envelope, 'new-root', 0, $proof);
            $row = (array) DB::table('ba_request_versions')->find($a['request_version_id']);
            $row['id'] = (string) Str::uuid();
            $row['receipt_id'] = $ticket['receipt_id'];
            $row['revision'] = 2;
            $row['authority_proof'] = BaContract::canonical($proof);
            $this->assertBaSqlState('23514', fn () => DB::table('ba_request_versions')->insert($row));
            $root = (string) Str::uuid();
            DB::table('ba_clarifications')->insert(['id' => $root, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'source_instance_id' => $envelope['source_instance_id'], 'object_id' => $envelope['object_id'], 'original_requested_at' => $a['body']['requested_at']]);
            $row['clarification_id'] = $root;
            $row['revision'] = 1;
            $row['requested_at'] = '2026-10-06T00:00:00.000000Z';
            $this->assertBaSqlState('23514', fn () => DB::table('ba_request_versions')->insert($row));
            $row['requested_at'] = $a['body']['requested_at'];
            DB::table('ba_request_versions')->insert($row);
            $this->assertSame($root, DB::table('ba_request_versions')->where('id', $row['id'])->value('clarification_id'));
        }, $graph['requests'], ['ba.request.manage', 'ba.source.read']);
    }

    public function test_confirmed_root_and_retained_origin_admit_exact_proof_categories(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->seedBaRequest();
        $answer = $this->seedBaAnswer($request);
        $review = $this->seedBaReview($request, $answer);
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, [$request['clarification_id']]);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertLessThanOrEqual(9, count($graph['requests']));
        $this->assertSame($graph['proof_keys']['review:'.$review['review_id']], $graph['proof_keys']['event:'.$review['event_id']]);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('ba_scope_obligations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'clarification_id' => $request['clarification_id'], 'origin_review_id' => $review['review_id'], 'origin_request_version_id' => $request['request_version_id'], 'origin_answer_version_id' => $answer['answer_version_id'], 'request_hash' => $request['request_hash'], 'links_hash' => $request['links_hash'], 'answer_hash' => $answer['answer_hash']]));
        // A later boundary retains the old confirmation as bounded origin metadata.
        $this->baRun(function ($proofs) use ($request, $answer) {
            $ticket = app(BaReceiptWriter::class)->beginHumanLocked($this->actor(), $this->p, 'context.add', ['clarification_id' => $request['clarification_id'], 'expected_revision' => 3, 'kind' => 'context.add', 'context' => 'Later synthetic boundary'], 'later-context');
            $event = (string) Str::uuid();
            DB::table('ba_events')->insert(['id' => $event, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'clarification_id' => $request['clarification_id'], 'revision' => 4, 'kind' => 'context.add', 'state' => 'resolved', 'request_version_id' => $request['request_version_id'], 'answer_version_id' => $answer['answer_version_id'], 'context' => 'Later synthetic boundary', 'receipt_id' => $ticket['receipt_id'], 'actor_id' => $this->user, 'authority_proof' => BaContract::canonical($proofs['current']['proof'])]);
            DB::table('ba_clarifications')->where('id', $request['clarification_id'])->update(['revision' => 4, 'current_event_id' => $event, 'current_review_id' => null]);
        }, purposes: ['ba.request.manage']);
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, [$request['clarification_id']]);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertCount(1, $graph['scope_origins']);
        $this->assertSame(['review:'.$review['review_id']], $graph['origin_refs']);
        $originKey = $graph['proof_keys']['review:'.$review['review_id']];
        $this->assertTrue($this->baRun(fn ($p) => $p[$originKey]['valid'], $graph['requests']));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->where('employee_id', '<>', $this->employee)->where('role', 'pm')->update(['valid_to' => now(), 'revision' => 2]));
        $this->assertFalse($this->baRun(fn ($p) => $p[$originKey]['valid'], $graph['requests']));
        $this->assertCount(1, $graph['scope_origins'], 'Invalid origin proof never clears immutable obligation metadata.');
    }

    public function test_legal_fifty_root_page_admits_separate_current_request_answer_and_receipt_categories(): void
    {
        $this->baInputs();
        $this->baRole();
        $task = $this->baTask();
        $party = $this->party();
        $roots = [];
        for ($i = 0; $i < 50; $i++) {
            $request = $this->seedBaRequest('maximum-'.$i, $task, $party);
            $this->seedBaAnswer($request);
            $roots[] = $request['clarification_id'];
        }
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, $roots);
        $this->assertSame('complete', $graph['coverage'], 'Receipt/category rows must never be mistaken for additional roots.');
        $this->assertCount(50, $graph['refs']['request']);
        $this->assertCount(50, $graph['refs']['answer']);
        $this->assertGreaterThanOrEqual(100, count($graph['refs']['receipt']));
        $this->assertGreaterThan(100, count($graph['requests']));
        $this->assertTrue($this->baRun(fn ($p) => $p['current']['valid'], $graph['requests']));
    }

    public function test_scope_origin_is_exact_immutable_and_unique_for_the_root(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->seedBaRequest();
        $answer = $this->seedBaAnswer($request);
        $review = $this->seedBaReview($request, $answer);
        app(TenantContext::class)->run($this->tenant, function () use ($request, $answer, $review) {
            $row = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'clarification_id' => $request['clarification_id'], 'origin_review_id' => $review['review_id'], 'origin_request_version_id' => $request['request_version_id'], 'origin_answer_version_id' => $answer['answer_version_id'], 'request_hash' => $request['request_hash'], 'links_hash' => $request['links_hash'], 'answer_hash' => $answer['answer_hash']];
            $bad = $row;
            $bad['links_hash'] = str_repeat('a', 64);
            $this->assertBaSqlState('23514', fn () => DB::table('ba_scope_obligations')->insert($bad));
            DB::table('ba_scope_obligations')->insert($row);
            $this->assertBaSqlState('42501', fn () => DB::table('ba_scope_obligations')->update(['origin_review_id' => (string) Str::uuid()]));
            $this->assertBaSqlState('42501', fn () => DB::table('ba_scope_obligations')->delete());
            $row['id'] = (string) Str::uuid();
            $this->assertBaSqlState('23505', fn () => DB::table('ba_scope_obligations')->insert($row));
            $this->assertSame($review['review_id'], DB::table('ba_scope_obligations')->value('origin_review_id'));
            $this->assertNull(DB::table('ba_receipts')->where('id', $review['receipt_id'])->value('source_revision'), 'Human review must never fabricate a source revision.');
        });
    }

    public function test_binding_cannot_substitute_a_different_event_boundary_same_root(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->seedBaRequest();
        $answer = $this->seedBaAnswer($request);
        app(TenantContext::class)->run($this->tenant, function () use ($request, $answer) {
            $row = (array) DB::table('ba_request_bindings')->where('operation', 'answer.record')->first();
            $row['id'] = (string) Str::uuid();
            $row['key'] = 'wrong-event';
            $row['event_id'] = $request['event_id'];
            $this->assertBaSqlState('23503', fn () => DB::table('ba_request_bindings')->insert($row));
            $this->assertSame($answer['event_id'], DB::table('ba_request_bindings')->where('operation', 'answer.record')->value('event_id'));
        });
    }

    public function test_actual_task_criterion_association_and_root_boundary_are_sql_enforced(): void
    {
        $this->baInputs();
        $this->baRole();
        $fixture = $this->seedBaRequest();
        app(TenantContext::class)->run($this->tenant, function () use ($fixture) {
            $row = (array) DB::table('ba_request_links')->first();
            $row['id'] = (string) Str::uuid();
            $row['criterion_id'] = $this->criteria[1]['id'];
            $row['criterion_version_id'] = $this->criteria[1]['version_id'];
            $this->assertBaSqlState('23503', fn () => DB::table('ba_request_links')->insert($row));
            $row['clarification_id'] = (string) Str::uuid();
            $this->assertBaSqlState('23503', fn () => DB::table('ba_request_links')->insert($row));
            $this->assertBaSqlState('23514', fn () => DB::table('ba_clarifications')->where('id', $fixture['clarification_id'])->update(['revision' => 3]));
            $this->assertSame(1, DB::table('ba_request_links')->count());
        });
    }

    public function test_captured_link_heads_conflict_when_task_successor_changes_before_fence(): void
    {
        $this->baInputs();
        $this->baRole();
        $fixture = $this->seedBaRequest();
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, [$fixture['clarification_id']]);
        $data = ['title' => 'Successor task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic only', 'remaining_effort' => null, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => [['criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id']]]];
        app(TaskService::class)->revise($this->actor(), $this->p, $fixture['task']['id'], $data, 1, $this->generation);
        $this->expectException(ConflictHttpException::class);
        $this->baRun(fn () => app(BaPins::class)->loadLocked($this->p, $graph), $graph['requests']);
    }

    public function test_fact_authority_must_match_exact_receipt_actor_and_proof(): void
    {
        $this->baInputs();
        $this->baRole();
        $fixture = $this->seedBaRequest();
        app(TenantContext::class)->run($this->tenant, function () use ($fixture) {
            $event = (array) DB::table('ba_events')->find($fixture['event_id']);
            $event['id'] = (string) Str::uuid();
            $event['revision'] = 2;
            $proof = json_decode($event['authority_proof'], true);
            $proof['employee_id'] = (string) Str::uuid();
            $event['authority_proof'] = BaContract::canonical($proof);
            $this->assertBaSqlState('23514', fn () => DB::table('ba_events')->insert($event));
        });
    }

    public function test_source_gap_completeness_cannot_be_repaired_by_query_builder_head_update(): void
    {
        $this->baInputs();
        $party = $this->party();
        $gap = $this->baEnvelope(revision: 3);
        $gap['body']['predecessor_party_version_id'] = $party['party_version_id'];
        $this->party($gap, 'gap', 1);
        app(TenantContext::class)->run($this->tenant, function () {
            $this->assertBaSqlState('23514', fn () => DB::table('ba_source_heads')->update(['complete' => true, 'revision' => 3]));
            $this->assertFalse(DB::table('ba_source_heads')->value('complete'));
        });
    }

    public function test_party_predecessor_must_be_older_within_exact_source_object(): void
    {
        $this->baInputs();
        $a = $this->party();
        $wrong = $this->baEnvelope(object: 'other', revision: 2);
        $wrong['body']['predecessor_party_version_id'] = $a['party_version_id'];
        $this->expectException(ConflictHttpException::class);
        $this->party($wrong, 'other', 0);
    }

    public function test_all_ba_tables_force_rls_and_runtime_owns_none(): void
    {
        $tables = DB::select("select c.relname,c.relrowsecurity,c.relforcerowsecurity from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and left(c.relname,3)='ba_' and c.relkind='r' order by c.relname");
        $this->assertSame([
            'ba_answer_versions', 'ba_clarifications', 'ba_demo_receipts', 'ba_events',
            'ba_receipts', 'ba_request_bindings', 'ba_request_links', 'ba_request_versions',
            'ba_reviews', 'ba_scope_obligations', 'ba_source_heads', 'ba_source_party_versions',
            'ba_terminal_applicability',
        ], array_column($tables, 'relname'));
        foreach ($tables as $table) {
            $this->assertTrue($table->relrowsecurity && $table->relforcerowsecurity, $table->relname.' must force RLS');
        }
        $this->assertFalse((bool) DB::scalar("select exists(select 1 from pg_class c join pg_roles r on r.oid=c.relowner where left(c.relname,3)='ba_' and c.relkind='r' and r.rolname=current_user)"));
        $this->assertFalse((bool) DB::scalar('select rolsuper or rolbypassrls from pg_roles where rolname=current_user'));
    }

    public function test_missing_tenant_context_never_delivers_ba_receipts(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('ba_receipts'), 'BA receipt foundation is missing.');
        $this->assertSame(0, DB::table('ba_receipts')->count());
    }

    public function test_party_history_is_immutable_and_cross_project_receipt_reference_rejects(): void
    {
        $this->baInputs();
        $party = $this->party();
        $other = $this->project();
        app(TenantContext::class)->run($this->tenant, function () use ($party, $other) {
            $this->assertBaSqlState('42501', fn () => DB::table('ba_source_party_versions')->update(['label' => 'Changed']));
            $this->assertBaSqlState('42501', fn () => DB::table('ba_source_party_versions')->delete());
            $row = (array) DB::table('ba_source_party_versions')->find($party['party_version_id']);
            $row['id'] = (string) Str::uuid();
            $row['project_id'] = $other;
            $this->assertBaSqlState('23503', fn () => DB::table('ba_source_party_versions')->insert($row));
        });
        $this->assertSame(0, DB::table('ba_source_party_versions')->count());
        $this->assertSame(0, app(TenantContext::class)->run((string) Str::uuid(), fn () => DB::table('ba_source_party_versions')->count()));
    }
}
