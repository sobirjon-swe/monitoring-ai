<?php

namespace Tests\Feature;

use App\Domain\Ba\BaCoordination;
use App\Domain\Ba\BaPins;
use App\Domain\Ba\BaReceiptWriter;
use App\Domain\Ba\BaTerminal;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\BaTestCase;

class BaReceiptTest extends BaTestCase
{
    public function test_source_observation_denial_skips_work_callback_and_overflow_is_not_terminal(): void
    {
        $this->baInputs();
        $party = $this->party();
        $source = $this->baEnvelope()['source_instance_id'];
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, ['receipt' => [$party['receipt_id']]], [$source]);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_connectors')->where('id', $this->connector)->update(['status' => 'disabled', 'revision' => 2]));
        $called = false;
        $result = app(BaCoordination::class)->run($this->actor(), $this->p, ['ba.fixture.manage', 'ba.source.read'], true, function () use (&$called) {
            $called = true;

            return 'unsafe callback';
        }, $graph['requests'], observeOriginalSourceInvalidity: true);
        $this->assertFalse($called);
        $this->assertSame(['denied' => true], $result);
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(1, DB::table('ba_terminal_applicability')->count()));
        $this->expectException(\InvalidArgumentException::class);
        $this->baRun(fn () => app(BaTerminal::class)->observeLocked($this->p, 'receipt', $party['receipt_id'], str_repeat('a', 64), 'coverage_limit'));
    }

    public function test_observed_original_source_disable_remains_terminal_after_same_row_restore(): void
    {
        $this->baInputs();
        $party = $this->party();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_connectors')->where('id', $this->connector)->update(['status' => 'disabled', 'revision' => 2]));
        $this->assertBaDenied(fn () => $this->party(key: 'source-disabled', expected: 99));
        app(TenantContext::class)->run($this->tenant, function () use ($party) {
            $this->assertSame('source_revoked', DB::table('ba_terminal_applicability')->where('subject_id', $party['receipt_id'])->value('reason_code'));
            DB::table('ingestion_connectors')->where('id', $this->connector)->update(['status' => 'active', 'revision' => 3]);
        });
        $this->assertBaDenied(fn () => $this->party(key: 'source-restored', expected: 99));
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(1, DB::table('ba_request_bindings')->count()));
    }

    public function test_current_viewer_only_denial_cannot_mark_original_receipt_terminal(): void
    {
        $this->baInputs();
        $this->party();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'ba.source.read')->update(['revoked_at' => now(), 'revision' => 2]));
        $this->assertBaDenied(fn () => $this->party(key: 'viewer-denied', expected: 99));
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(0, DB::table('ba_terminal_applicability')->count()));
    }

    public function test_human_receipt_cannot_use_read_only_proof_for_request_action(): void
    {
        $this->baInputs();
        $this->baRole();
        $fixture = $this->seedBaRequest();
        $this->assertBaDenied(fn () => $this->baRun(fn () => app(BaReceiptWriter::class)->beginHumanLocked($this->actor(), $this->p, 'context.add', ['clarification_id' => $fixture['clarification_id'], 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'], 'context')));
    }

    public function test_receipt_writer_rejects_uncoordinated_human_write(): void
    {
        $this->inputs();
        $this->expectException(LogicException::class);
        app(BaReceiptWriter::class)->beginHumanLocked($this->actor(), $this->p, 'context.add', ['expected_revision' => 1, 'context' => 'Synthetic context'], 'sample');
    }

    public function test_party_registration_and_exact_replay_bind_the_same_typed_output(): void
    {
        $this->baInputs();
        $a = $this->party();
        $b = $this->party(key: 'alias', expected: 99);
        $this->assertFalse($a['replayed']);
        $this->assertTrue($b['replayed']);
        $this->assertSame($a['party_version_id'], $b['party_version_id']);
        $this->assertSame($a['receipt_id'], $b['receipt_id']);
        app(TenantContext::class)->run($this->tenant, function () {
            $this->assertSame(1, DB::table('ba_receipts')->count());
            $this->assertSame(1, DB::table('ba_source_party_versions')->count());
            $this->assertSame(2, DB::table('ba_request_bindings')->count());
            $this->assertSame(1, DB::table('audit_events')->where('action', 'ba.party_registered')->count());
            $this->assertSame(1, DB::table('employees')->count(), 'Synthetic customer must never become an employee.');
        });
    }

    public function test_conflicting_hash_and_obsolete_new_source_cas_are_atomic(): void
    {
        $this->baInputs();
        $this->party();
        $different = $this->baEnvelope();
        $different['body']['label'] = 'Different';
        try {
            $this->party($different, 'alias', 1);
            $this->fail('Conflicting natural replay accepted.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
        $new = $this->baEnvelope(revision: 2);
        $this->expectException(ConflictHttpException::class);
        $this->party($new, 'second', 0);
    }

    public function test_latest_source_gap_is_incomplete_and_late_versions_do_not_replace_it(): void
    {
        $this->baInputs();
        $a = $this->party();
        $gap = $this->baEnvelope(revision: 3);
        $gap['body']['predecessor_party_version_id'] = $a['party_version_id'];
        $b = $this->party($gap, 'third', 1);
        $late = $this->baEnvelope(revision: 2);
        $late['body']['predecessor_party_version_id'] = $a['party_version_id'];
        $c = $this->party($late, 'second', 2);
        $this->assertFalse($b['complete']);
        $this->assertFalse($c['current']);
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame($b['receipt_id'], DB::table('ba_source_heads')->value('receipt_id')));
    }

    public function test_replay_revalidates_original_grant_and_creates_no_alias_when_revoked(): void
    {
        $this->baInputs();
        $this->party();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'ba.fixture.manage')->update(['revoked_at' => now(), 'revision' => 2]));
        $this->grant('ba.fixture.manage', $this->p);
        $this->assertBaDenied(fn () => $this->party(key: 'revoked-alias', expected: 99));
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(1, DB::table('ba_request_bindings')->count()));
    }

    public function test_observed_receipt_revocation_stays_terminal_after_same_grant_is_restored(): void
    {
        $this->baInputs();
        $party = $this->party();
        $grant = app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'ba.fixture.manage')->value('id'));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now(), 'revision' => 2]));
        $replacement = $this->grant('ba.fixture.manage', $this->p);
        $this->assertBaDenied(fn () => $this->party(key: 'observed', expected: 99));
        app(TenantContext::class)->run($this->tenant, function () use ($grant, $replacement) {
            DB::table('access_grants')->where('id', $replacement)->update(['revoked_at' => now(), 'revision' => 2]);
            DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => null, 'revision' => 1]);
            $this->assertSame(1, DB::table('ba_terminal_applicability')->count());
        });
        $this->assertBaDenied(fn () => $this->party(key: 'restored', expected: 99));
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame($party['receipt_id'], DB::table('ba_request_bindings')->value('receipt_id')));
    }
}
