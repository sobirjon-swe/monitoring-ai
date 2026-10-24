<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementContract;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ManagementContractTest extends TestCase
{
    private function action(): array
    {
        return ['owner_id' => (string) Str::uuid(), 'reason' => 'Synthetic reason', 'references' => [['kind' => 'lifecycle', 'task_version_id' => (string) Str::uuid(), 'criterion_id' => (string) Str::uuid(), 'criterion_version_id' => (string) Str::uuid(), 'relation' => 'blocks']]];
    }

    public function test_closed_operation_schema_and_byte_limits(): void
    {
        $contract = app(ManagementContract::class);
        $this->assertSame(4000, strlen($contract->validate('context.add', ['operation' => 'context.add', 'expected_revision' => 1, 'context' => str_repeat('x', 4000)])['context']));
        $body = $this->action();
        $body['reason'] = str_repeat('a', 2000);
        $this->assertSame(2000, strlen($contract->validate('action.open', $body)['reason']));
        foreach ([['context.add', ['operation' => 'context.add', 'expected_revision' => 1, 'context' => str_repeat('x', 4001)]], ['action.open', array_replace($body, ['reason' => str_repeat('a', 2001)])], ['support.record', $body], ['action.open', $body + ['unknown' => true]], ['action.open', array_replace($body, ['reason' => 'password=synthetic-secret'])]] as [$operation, $data]) {
            try {
                $contract->validate($operation, $data);
                $this->fail('Invalid management input was admitted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_duplicate_decoded_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ManagementContract::class)->parse('{"operation":"action.open","\u006fperation":"support.record"}');
    }

    public function test_reference_identity_and_relation_are_consistent(): void
    {
        $body = $this->action();
        $body['references'][] = $body['references'][0];
        $this->expectException(InvalidArgumentException::class);
        app(ManagementContract::class)->validate('action.open', $body);
    }

    public function test_content_hash_excludes_controls_but_retains_source_identity(): void
    {
        $contract = app(ManagementContract::class);
        $body = ['expected_revision' => 1, 'context' => 'Synthetic'];
        $this->assertSame($contract->contentHash('context.add', $body), $contract->contentHash('context.add', array_replace($body, ['expected_revision' => 2])));
        $this->assertNotSame($contract->contentHash('envelope', ['source_revision' => 1]), $contract->contentHash('envelope', ['source_revision' => 2]));
    }

    public function test_reference_set_order_does_not_change_content_identity(): void
    {
        $contract = app(ManagementContract::class);
        $a = $this->action();
        $second = $a['references'][0];
        $second['task_version_id'] = (string) Str::uuid();
        $a['references'][] = $second;
        $b = $a;
        $b['references'] = array_reverse($b['references']);
        $this->assertSame($contract->contentHash('action.open', $a), $contract->contentHash('action.open', $b));
    }

    public function test_stable_original_identity_retains_revisions_and_subjects(): void
    {
        $proof = ['version' => 'management-authority-v1', 'captured_at' => '2026-10-09T00:00:00.000001Z', 'membership' => ['id' => '11111111-1111-4111-8111-111111111111', 'revision' => 1]];
        $later = array_replace($proof, ['captured_at' => '2026-10-09T00:00:00.000002Z']);
        $this->assertSame(ManagementContract::proofHash($proof), ManagementContract::proofHash($later));
        $later['membership']['revision'] = 2;
        $this->assertNotSame(ManagementContract::proofHash($proof), ManagementContract::proofHash($later));
        $slot = ['format' => 'management-authority-v1', 'subject_kind' => 'management_receipts', 'subject_id' => '11111111-1111-4111-8111-111111111111', 'proof_hash' => ManagementContract::proofHash($proof), 'actor_id' => '11111111-1111-4111-8111-111111111111', 'purposes' => ['management.read']];
        $other = array_replace($slot, ['subject_id' => '22222222-2222-4222-8222-222222222222']);
        $this->assertNotSame(ManagementContract::proofKey('tenant', 'project', $slot), ManagementContract::proofKey('tenant', 'project', $other));
    }

    public function test_real_microsecond_dates_multibyte_limits_and_wrong_types_are_rejected(): void
    {
        $contract = app(ManagementContract::class);
        $envelope = ['schema_version' => 'management-evidence-v1', 'operation' => 'action.open', 'source_instance_id' => (string) Str::uuid(), 'object_id' => 'synthetic', 'source_revision' => 1, 'occurred_at' => '2026-10-09T00:00:00.000001Z', 'source_updated_at' => '2026-10-09T00:00:00.000001Z', 'body' => $this->action()];
        $this->assertSame(1, $contract->validate('envelope', $envelope)['source_revision']);
        foreach ([array_replace($envelope, ['occurred_at' => '2026-02-30T00:00:00.000001Z']), array_replace($envelope, ['source_revision' => true]), array_replace($envelope, ['body' => array_replace($envelope['body'], ['references' => (object) $envelope['body']['references']])]), array_replace($envelope, ['body' => array_replace($envelope['body'], ['reason' => str_repeat('é', 1001)])])] as $bad) {
            try {
                $contract->validate('envelope', $bad);
                $this->fail('Invalid typed envelope admitted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
