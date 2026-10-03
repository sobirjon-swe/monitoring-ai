<?php

namespace App\Domain\Analysis;

use InvalidArgumentException;
use JsonException;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Exceptions\SchemaException;
use RuntimeException;

final class AnalysisSchemaValidator
{
    private readonly CompliantValidator $validator;

    private array $schemas = [];

    public function __construct()
    {
        // Direct local schemas only. No remote resolver, extension features or network loader.
        $this->validator = new CompliantValidator;
        foreach (['context', 'output'] as $kind) {
            $source = file_get_contents(base_path('../contracts/analysis-'.$kind.'-v1.schema.json'));
            if ($source === false) {
                throw new RuntimeException('Local analysis schema is unavailable.');
            }
            $schema = json_decode($source, false, 64, JSON_THROW_ON_ERROR);
            $this->assertLocalReferences($schema);
            $this->schemas[$kind] = $schema;
        }
    }

    public function validate(string $kind, array $payload): void
    {
        if (! isset($this->schemas[$kind])) {
            throw new InvalidArgumentException('Invalid analysis contract.');
        }
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            if (strlen($encoded) > 1000000) {
                throw new InvalidArgumentException('Invalid analysis contract.');
            }
            $data = json_decode($encoded, false, 64, JSON_THROW_ON_ERROR);
            if (! $this->validator->validate($data, $this->schemas[$kind])->isValid()) {
                throw new InvalidArgumentException('Invalid analysis contract.');
            }
        } catch (JsonException|SchemaException) {
            // Do not expose untrusted output values or schema diagnostics in errors/logs.
            throw new InvalidArgumentException('Invalid analysis contract.');
        }
    }

    private function assertLocalReferences(mixed $node): void
    {
        if (is_object($node) || is_array($node)) {
            foreach ($node as $key => $value) {
                if ($key === '$ref' && (! is_string($value) || ! str_starts_with($value, '#/'))) {
                    throw new RuntimeException('Analysis schemas must use local references.');
                }
                $this->assertLocalReferences($value);
            }
        }
    }
}
