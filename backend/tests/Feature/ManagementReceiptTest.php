<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementActionService;
use App\Domain\Management\ManagementReceiptWriter;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\ManagementTestCase;

class ManagementReceiptTest extends ManagementTestCase
{
    public function test_natural_alias_replay_preserves_one_immutable_receipt_and_event(): void
    {
        $this->managementInputs();
        $envelope = $this->managementEnvelope('action.open', $this->actionInput());
        $service = app(ManagementActionService::class);
        $first = $service->open($this->actor(), $this->p, $envelope, 'first', 0);
        $alias = $service->open($this->actor(), $this->p, $envelope, 'alias', 0);
        $this->assertSame($first['receipt_id'], $alias['receipt_id']);
        $this->assertTrue($alias['replayed']);
        app(TenantContext::class)->run($this->tenant, function () use ($first) {
            $receipt = DB::table('management_receipts')->first();
            $this->assertSame($first['action_version_id'], $receipt->action_version_id);
            $this->assertSame($first['event_id'], $receipt->event_id);
            $this->assertSame(1, DB::table('management_receipts')->count());
            $this->assertSame(1, DB::table('management_events')->count());
            $this->assertSame(2, DB::table('management_request_bindings')->count());
            $this->assertSame(1, DB::table('audit_events')->where('action', 'management.event_recorded')->count());
        });
    }

    public function test_callback_crash_rolls_back_preallocated_receipt_head_and_output(): void
    {
        $this->managementInputs();
        $envelope = $this->managementEnvelope('action.open', $this->actionInput());
        $writer = app(ManagementReceiptWriter::class);
        try {
            $writer->run($this->actor(), $this->p, null, 'action.open', $envelope, 'crash', ['management.action.manage', 'management.source.read', 'requirements.read', 'progress.read'], $envelope['body']['references'], [$this->employee], null, function ($proof) use ($writer, $envelope) {
                $ticket = $writer->beginSourceLocked($this->actor(), $this->p, $envelope, 'crash', 0, $proof);
                $this->assertArrayHasKey('action_version_id', $ticket['output']);
                throw new \RuntimeException('Synthetic callback failure');
            });
            $this->fail('Callback must fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic callback failure', $e->getMessage());
        }
        app(TenantContext::class)->run($this->tenant, function () {
            foreach (['management_receipts', 'management_actions', 'management_events', 'management_request_bindings', 'management_source_heads', 'management_project_state'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
        });
    }
}
