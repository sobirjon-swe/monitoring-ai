<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\CompliantValidator;

trait StrictLifecycleJson
{
    protected function integer(array $data, string $key, int $min): int
    {
        if (! is_int($data[$key] ?? null) || $data[$key] < $min) {
            $this->invalid();
        }

        return $data[$key];
    }

    protected function invalid(): never
    {
        throw ValidationException::withMessages(['payload' => 'Invalid lifecycle payload.']);
    }

    protected function json(string $raw): array
    {
        if (strlen($raw) > 65536) {
            abort(413);
        }
        try {
            $decoded = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->invalid();
        }
        if (! is_object($decoded)) {
            $this->invalid();
        }
        if (property_exists($decoded, 'criteria')) {
            $draft = clone $decoded;
            unset($draft->expected_revision);
            $schema = json_decode(file_get_contents(base_path('../contracts/lifecycle-requirement-v1.schema.json')), false, 16, JSON_THROW_ON_ERROR);
            if (! (new CompliantValidator)->validate($draft, $schema)->isValid()) {
                $this->invalid();
            }
        }
        $check = function ($v) use (&$check): void {
            if (is_string($v) && str_contains($v, "\0")) {
                $this->invalid();
            }if (is_object($v) || is_array($v)) {
                foreach ($v as $k => $item) {
                    if (is_string($k) && str_contains($k, "\0")) {
                        $this->invalid();
                    }$check($item);
                }
            }
        };
        $check($decoded);
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\\[\\]:,]|[^\\s{}\\[\\]:,]+/s', $raw, $matches);
        $stack = [];
        foreach ($matches[0] as $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'keys' => [], 'key' => $token === '{'];

                continue;
            }if ($token === '}' || $token === ']') {
                array_pop($stack);

                continue;
            }$i = count($stack) - 1;
            if ($i < 0) {
                continue;
            }if ($token === ',') {
                $stack[$i]['key'] = $stack[$i]['object'];

                continue;
            }if ($token === ':') {
                $stack[$i]['key'] = false;

                continue;
            }if ($stack[$i]['key']) {
                $key = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
                if (isset($stack[$i]['keys'][$key])) {
                    $this->invalid();
                }$stack[$i]['keys'][$key] = true;
                $stack[$i]['key'] = false;
            }
        }

        return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    }
}
