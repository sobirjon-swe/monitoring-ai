<?php

namespace Tests\Unit;

use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;

class BaSchemaContractTest extends TestCase
{
    private function valid(array $value): bool
    {
        $schema = json_decode(file_get_contents(__DIR__.'/../../../docs/contracts/ba-evidence-v1.schema.json'));

        return (new CompliantValidator)->validate(json_decode(json_encode($value)), $schema)->isValid();
    }

    public function test_schema_alone_rejects_arbitrary_and_wrong_operation_bodies(): void
    {
        $envelope = ['schema_version' => 'ba-evidence-v1', 'operation' => 'party.register', 'source_instance_id' => '00000000-0000-4000-8000-000000000001', 'object_id' => 'synthetic', 'source_revision' => 1, 'occurred_at' => '2026-10-06T00:00:00.000000Z', 'source_updated_at' => '2026-10-06T00:00:00.000000Z', 'body' => ['party_reference' => 'customer', 'party_kind' => 'customer', 'label' => 'Synthetic', 'predecessor_party_version_id' => null]];
        $this->assertTrue($this->valid($envelope));
        foreach (['party.register', 'request.open', 'answer.record', 'answer.correct', 'answer.retract'] as $operation) {
            $this->assertFalse($this->valid(array_replace($envelope, ['operation' => $operation, 'body' => new \stdClass])));
            $this->assertFalse($this->valid(array_replace($envelope, ['operation' => $operation, 'body' => ['arbitrary' => true]])));
            $this->assertFalse($this->valid(array_replace($envelope, ['operation' => $operation, 'body' => []])));
            if ($operation !== 'party.register') {
                $this->assertFalse($this->valid(array_replace($envelope, ['operation' => $operation])));
            }
        }
    }

    public function test_schema_alone_validates_each_body_and_answer_predecessor_alternative(): void
    {
        $id = '00000000-0000-4000-8000-000000000001';
        $at = '2026-10-06T00:00:00.000000Z';
        $request = ['question' => 'Synthetic?', 'requester_id' => $id, 'coordinator_id' => $id, 'decision_party' => 'customer', 'decision_owner_id' => null, 'decision_owner_party_version_id' => $id, 'requested_at' => $at, 'links' => [['task_version_id' => $id, 'criterion_id' => $id, 'criterion_version_id' => $id, 'relation' => 'blocks']]];
        $answer = ['clarification_id' => $id, 'expected_revision' => 1, 'request_version_id' => $id, 'request_hash' => str_repeat('a', 64), 'links_hash' => str_repeat('b', 64), 'author_party_version_id' => $id, 'answer' => 'Synthetic', 'response_at' => $at, 'predecessor_answer_version_id' => null];
        $retract = array_intersect_key($answer, array_flip(['clarification_id', 'expected_revision', 'request_version_id', 'request_hash', 'links_hash', 'predecessor_answer_version_id'])) + ['reason' => 'Synthetic correction'];
        $retract['predecessor_answer_version_id'] = $id;
        $envelope = ['schema_version' => 'ba-evidence-v1', 'source_instance_id' => $id, 'object_id' => 'synthetic', 'source_revision' => 1, 'occurred_at' => $at, 'source_updated_at' => $at];
        foreach (['request.open' => $request, 'answer.record' => $answer, 'answer.correct' => array_replace($answer, ['predecessor_answer_version_id' => $id]), 'answer.retract' => $retract] as $operation => $body) {
            $this->assertTrue($this->valid($envelope + ['operation' => $operation, 'body' => $body]), $operation);
            $this->assertFalse($this->valid($envelope + ['operation' => $operation, 'body' => $body + ['unknown' => 1]]));
        }
        $this->assertFalse($this->valid($envelope + ['operation' => 'answer.correct', 'body' => $answer]));
        $this->assertFalse($this->valid($envelope + ['operation' => 'answer.record', 'body' => array_replace($answer, ['predecessor_answer_version_id' => $id])]));
    }
}
