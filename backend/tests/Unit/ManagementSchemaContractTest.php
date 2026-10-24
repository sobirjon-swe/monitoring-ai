<?php

namespace Tests\Unit;

use App\Domain\Management\ManagementContract;
use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;
use Tests\TestCase;

class ManagementSchemaContractTest extends TestCase
{
    private function valid(array $value): bool
    {
        $schema = json_decode(file_get_contents(__DIR__.'/../../../docs/contracts/management-evidence-v1.schema.json'));

        return (new CompliantValidator)->validate(json_decode(json_encode($value)), $schema)->isValid();
    }

    public function test_standalone_schema_binds_every_source_operation_to_its_closed_body(): void
    {
        $id = '00000000-0000-4000-8000-000000000001';
        $reference = ['kind' => 'lifecycle', 'task_version_id' => $id, 'criterion_id' => $id, 'criterion_version_id' => $id, 'relation' => 'blocks'];
        $open = ['owner_id' => $id, 'reason' => 'Synthetic', 'references' => [$reference]];
        $support = ['action_id' => $id, 'expected_revision' => 2, 'action_version_id' => $id, 'action_hash' => str_repeat('a', 64), 'assignment_id' => $id, 'assignment_hash' => str_repeat('b', 64), 'references' => [$reference], 'predecessor_support_id' => null, 'text' => 'Synthetic', 'outcome' => 'completed'];
        $decline = array_diff_key($support, ['text' => true, 'outcome' => true]) + ['reason' => 'Synthetic'];
        $bodies = ['action.open' => $open, 'support.record' => $support, 'support.correct' => array_replace($support, ['predecessor_support_id' => $id]), 'support.retract' => array_replace($decline, ['predecessor_support_id' => $id]), 'support.decline' => $decline];
        $base = ['schema_version' => 'management-evidence-v1', 'source_instance_id' => $id, 'object_id' => 'synthetic', 'source_revision' => 1, 'occurred_at' => '2026-10-09T00:00:00.000000Z', 'source_updated_at' => '2026-10-09T00:00:00.000000Z'];
        foreach ($bodies as $operation => $body) {
            $this->assertTrue($this->valid($base + ['operation' => $operation, 'body' => $body]), $operation);
            foreach ([new \stdClass, [], ['unknown' => true], $body + ['unknown' => true]] as $invalid) {
                $this->assertFalse($this->valid($base + ['operation' => $operation, 'body' => $invalid]), $operation);
            }
            if ($operation !== 'action.open') {
                $this->assertFalse($this->valid($base + ['operation' => $operation, 'body' => $open]));
            }
        }
        $this->assertFalse($this->valid($base + ['operation' => 'support.correct', 'body' => $support]));
        $this->assertFalse($this->valid($base + ['operation' => 'support.record', 'body' => $bodies['support.correct']]));
    }

    public function test_boolean_revision_counter_is_rejected(): void
    {
        $c = app(ManagementContract::class);
        $this->expectException(InvalidArgumentException::class);
        $c->validate('context.add', ['operation' => 'context.add', 'expected_revision' => true, 'context' => 'Synthetic']);
    }
}
