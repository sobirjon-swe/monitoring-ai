<?php

namespace App\Domain\Qa;

use App\Domain\DeveloperAnalysis\SecretGate;
use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;

final class QaContract
{
    public static function canonical(array $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (is_object($v)) {
                $v = get_object_vars($v);
                if (! $v) {
                    return new \stdClass;
                }
            }
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($sort, $v);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    public function parse(string $raw): array
    {
        try {
            if (strlen($raw) > 65536 || ! mb_check_encoding($raw, 'UTF-8')) {
                throw new InvalidArgumentException;
            }
            $value = json_decode($raw, false, 17, JSON_THROW_ON_ERROR);
            if (! is_object($value)) {
                throw new InvalidArgumentException;
            }
            // Tokenize valid JSON once so escaped spellings of the same member also collide.
            preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|true|false|null|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/s', $raw, $matches);
            $stack = [];
            $tokens = $matches[0];
            foreach ($tokens as $i => $token) {
                if ($token === '{' || $token === '[') {
                    $stack[] = ['type' => $token, 'keys' => []];
                    if (count($stack) > 16) {
                        throw new InvalidArgumentException;
                    }
                } elseif ($token === '}' || $token === ']') {
                    array_pop($stack);
                } elseif (str_starts_with($token, '"') && ($tokens[$i + 1] ?? '') === ':' && ($stack[array_key_last($stack)]['type'] ?? '') === '{') {
                    $key = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
                    $top = array_key_last($stack);
                    if (isset($stack[$top]['keys'][$key])) {
                        throw new InvalidArgumentException;
                    }
                    $stack[$top]['keys'][$key] = true;
                }
            }
            $convert = function ($v) use (&$convert) {
                if (is_object($v)) {
                    $vars = get_object_vars($v);

                    return $vars ? array_map($convert, $vars) : new \stdClass;
                }
                if (is_array($v)) {
                    return array_map($convert, $v);
                }

                return $v;
            };
            $data = array_map($convert, get_object_vars($value));

            return $data;
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid QA JSON contract.');
        }
    }

    public function validate(string $operation, array $data): array
    {
        try {
            $json = self::canonical($data);
            $schema = json_decode(file_get_contents(base_path('../docs/contracts/qa-evidence-v1.schema.json')), false, 32, JSON_THROW_ON_ERROR);
            if (! isset($schema->{'$defs'}->{$operation}) || strlen($json) > 65536) {
                throw new InvalidArgumentException;
            }
            $selected = (object) ['$defs' => $schema->{'$defs'}, '$ref' => '#/$defs/'.$operation];
            if (! (new CompliantValidator)->validate(json_decode($json, false, 17, JSON_THROW_ON_ERROR), $selected)->isValid()) {
                throw new InvalidArgumentException;
            }
            $scan = function ($value, string $key = '') use (&$scan): void {
                if (is_array($value) || is_object($value)) {
                    foreach ($value as $childKey => $child) {
                        $scan($child, (string) $childKey);
                    }
                } elseif (is_string($value)) {
                    $limit = in_array($key, ['reason', 'exclusion_reason'], true) ? 2000 : (in_array($key, ['steps', 'expected_result', 'reproduction', 'blocked_reason'], true) ? 4000 : ($key === 'title' ? 500 : null));
                    if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) || ($limit !== null && strlen($value) > $limit)) {
                        throw new InvalidArgumentException;
                    }
                    app(SecretGate::class)->safeText($value);
                    if (in_array($key, ['reason', 'exclusion_reason'], true)) {
                        $this->reason($value);
                    }
                    if (in_array($key, ['occurred_at', 'source_updated_at'], true)) {
                        $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new \DateTimeZone('UTC'));
                        if (! $at || $at->format('Y-m-d\TH:i:s.u\Z') !== $value) {
                            throw new InvalidArgumentException;
                        }
                    }
                }
            };
            $scan($data);
            if ($operation === 'envelope') {
                $this->validate($data['operation'], $data['body']);
            }

            $normalize = function ($v) use (&$normalize) {
                if (is_object($v)) {
                    $v = get_object_vars($v);
                }

                return is_array($v) ? array_map($normalize, $v) : $v;
            };

            return $normalize($data);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid QA contract.');
        }
    }

    public function reason(string $value): string
    {
        if (strlen($value) > 2000 || trim($value) === '' || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Invalid QA reason.');
        }

        return app(SecretGate::class)->safeText($value);
    }
}
