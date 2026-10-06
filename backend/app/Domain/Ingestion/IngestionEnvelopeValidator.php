<?php

namespace App\Domain\Ingestion;

use Illuminate\Validation\ValidationException;
use JsonException;
use Opis\JsonSchema\CompliantValidator;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class IngestionEnvelopeValidator
{
    public function validate(array $envelope): array
    {
        try {
            return $this->validateJson(json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        } catch (JsonException) {
            throw ValidationException::withMessages(['envelope' => 'Invalid envelope.']);
        }
    }

    public function validateJson(string $raw): array
    {
        if (strlen($raw) > 65536) {
            throw new HttpException(413);
        }
        try {
            // Decode objects as objects: PHP request normalization would erase object/array distinctions.
            $data = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
            $schema = json_decode(file_get_contents(base_path('../contracts/ingestion-envelope-v1.schema.json')), false, 16, JSON_THROW_ON_ERROR);
            if (! (new CompliantValidator)->validate($data, $schema)->isValid()) {
                throw ValidationException::withMessages(['envelope' => 'Invalid envelope.']);
            }
            // JSON permits NUL, but PostgreSQL text/JSONB cannot represent it. Reject before any writes.
            foreach ([$data, $data->payload] as $object) {
                foreach ($object as $value) {
                    if (is_string($value) && str_contains($value, "\0")) {
                        throw ValidationException::withMessages(['envelope' => 'Invalid envelope.']);
                    }
                }
            }
            $this->rejectDuplicateKeys($raw);

            return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['envelope' => 'Invalid envelope.']);
        }
    }

    public function canonical(array $envelope): string
    {
        ksort($envelope, SORT_STRING);
        ksort($envelope['payload'], SORT_STRING);

        return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function rejectDuplicateKeys(string $raw): void
    {
        // Grammar has already been checked. Track keys at each object depth, including escaped aliases.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}:,]|[^\\s{}:,]+/s', $raw, $matches);
        $stack = [];
        $expectKey = false;
        foreach ($matches[0] as $token) {
            if ($token === '{') {
                $stack[] = [];
                $expectKey = true;
            } elseif ($token === '}') {
                array_pop($stack);
                $expectKey = false;
            } elseif ($token === ',') {
                $expectKey = true;
            } elseif ($token === ':') {
                $expectKey = false;
            } elseif ($expectKey) {
                $key = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
                $index = count($stack) - 1;
                if (isset($stack[$index][$key])) {
                    throw ValidationException::withMessages(['envelope' => 'Invalid envelope.']);
                }
                $stack[$index][$key] = true;
                $expectKey = false;
            }
        }
    }
}
