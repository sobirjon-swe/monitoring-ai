<?php

namespace Tests\Feature;

use App\Domain\Ba\BaContract;
use InvalidArgumentException;
use Tests\TestCase;

class BaContractTest extends TestCase
{
    public function test_canonical_preserves_json_object_and_array_distinction(): void
    {
        $this->assertSame('{"empty":{},"list":[]}', BaContract::canonical((object) ['list' => [], 'empty' => new \stdClass]));
        $this->assertSame('{"a":1,"b":2}', BaContract::canonical((object) ['b' => 2, 'a' => 1]));
        $parsed = app(BaContract::class)->parse('{"nested":{"0":"value"}}');
        $this->assertSame('{"nested":{"0":"value"}}', BaContract::canonical($parsed));
    }

    public function test_unknown_shape_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(BaContract::class)->validate('unknown', (object) []);
    }

    public function test_party_shape_rejects_unknown_identity_fields_secrets_controls_and_byte_overflow(): void
    {
        $party = ['party_reference' => 'synthetic-customer', 'party_kind' => 'customer', 'label' => 'Synthetic customer', 'predecessor_party_version_id' => null];
        $this->assertSame($party, app(BaContract::class)->validate('party', $party));
        foreach ([$party + ['employee_id' => 'spoof'], array_replace($party, ['label' => 'password=synthetic-secret']), array_replace($party, ['label' => "invalid\0label"]), array_replace($party, ['party_reference' => str_repeat('é', 101)]), []] as $bad) {
            try {
                app(BaContract::class)->validate('party', $bad);
                $this->fail('Invalid party accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_strict_parser_rejects_decoded_duplicate_keys_utf8_and_depth_overflow(): void
    {
        foreach (['{"body":{"x":1,"\\u0078":2}}', "{\"x\":\"\xff\"}", '{"x":'.str_repeat('[', 16).'0'.str_repeat(']', 16).'}', str_repeat(' ', 65537).'{}'] as $raw) {
            try {
                app(BaContract::class)->parse($raw);
                $this->fail('Invalid raw contract accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertInstanceOf(\stdClass::class, app(BaContract::class)->parse('{"empty":{}}')['empty']);
    }

    public function test_envelope_integer_fields_reject_float_and_boolean_substitution(): void
    {
        $party = ['party_reference' => 'customer', 'party_kind' => 'customer', 'label' => 'Synthetic', 'predecessor_party_version_id' => null];
        $envelope = ['schema_version' => 'ba-evidence-v1', 'operation' => 'party.register', 'source_instance_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'object_id' => 'customer', 'source_revision' => 1, 'occurred_at' => '2026-10-06T00:00:00.000000Z', 'source_updated_at' => '2026-10-06T00:00:00.000000Z', 'body' => $party];
        $this->assertSame(1, app(BaContract::class)->validate('envelope', $envelope)['source_revision']);
        foreach ([1.0, true, '1'] as $bad) {
            $copy = $envelope;
            $copy['source_revision'] = $bad;
            try {
                app(BaContract::class)->validate('envelope', $copy);
                $this->fail('Noninteger revision accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
