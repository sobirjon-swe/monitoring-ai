<?php

namespace App\Domain\Management;

use App\Domain\DeveloperAnalysis\SecretGate;
use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;

final class ManagementContract
{
    public static function canonical(mixed $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (is_object($v)) {
                $vars = get_object_vars($v);
                ksort($vars, SORT_STRING);
                $out = new \stdClass;
                foreach ($vars as $key => $child) {
                    $out->{$key} = $sort($child);
                }

                return $out;
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
                    $object = new \stdClass;
                    foreach ($vars as $key => $child) {
                        $object->{$key} = $convert($child);
                    }

                    return $object;
                }
                if (is_array($v)) {
                    return array_map($convert, $v);
                }

                return $v;
            };
            $data = array_map($convert, get_object_vars($value));

            return $data;
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid management JSON contract.');
        }
    }

    public function validate(string $operation, array|object $data): array
    {
        try {
            $json = self::canonical($data);
            $schema = json_decode(file_get_contents(base_path('../docs/contracts/management-evidence-v1.schema.json')), false, 32, JSON_THROW_ON_ERROR);
            if (! isset($schema->{'$defs'}->{$operation}) || strlen($json) > 65536) {
                throw new InvalidArgumentException;
            }
            $selected = (object) ['$defs' => $schema->{'$defs'}, '$ref' => '#/$defs/'.$operation];
            if (! (new CompliantValidator)->validate(json_decode($json, false, 17, JSON_THROW_ON_ERROR), $selected)->isValid()) {
                throw new InvalidArgumentException;
            }
            $scan = function ($v, string $key = '', int $depth = 0) use (&$scan): void {
                if ($depth > 16 || (in_array($key, ['expected_revision', 'source_revision'], true) && ! is_int($v))) {
                    throw new InvalidArgumentException;
                }
                if (is_array($v) || is_object($v)) {
                    foreach ($v as $k => $child) {
                        $scan($child, (string) $k, $depth + 1);
                    }
                } elseif (is_string($v)) {
                    $limit = match ($key) {
                        'reason' => 2000, 'text', 'context' => 4000, 'object_id' => 200, default => null
                    };
                    if (! mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $v) || ($limit !== null && (strlen($v) > $limit || trim($v) === ''))) {
                        throw new InvalidArgumentException;
                    }
                    app(SecretGate::class)->safeText($v);
                    if (in_array($key, ['occurred_at', 'source_updated_at'], true)) {
                        $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $v, new \DateTimeZone('UTC'));
                        if (! $at || $at->format('Y-m-d\TH:i:s.u\Z') !== $v) {
                            throw new InvalidArgumentException;
                        }
                    }
                }
            };
            $scan($data);
            if ($operation === 'envelope') {
                $outer = is_object($data) ? get_object_vars($data) : $data;
                // Validate the selected body while JSON object/array identity is
                // still intact; normalization must never turn an object into a list.
                $outer['body'] = $this->validate($outer['operation'], $outer['body']);
                $data = $outer;
            }
            $normalize = function ($v) use (&$normalize) {
                if (is_object($v)) {
                    $v = get_object_vars($v);
                }

                return is_array($v) ? array_map($normalize, $v) : $v;
            };
            $data = $normalize($data);
            if ($operation === 'envelope') {
                if ($data['operation'] === 'action.open' && $data['source_revision'] !== 1) {
                    throw new InvalidArgumentException;
                }
            }
            if (isset($data['action'])) {
                $data['action'] = $this->validate('action.open', $data['action']);
            }
            if (isset($data['references'])) {
                $seen = $relations = [];
                foreach ($data['references'] as &$ref) {
                    foreach ($ref as $k => &$v) {
                        if (str_ends_with($k, '_id')) {
                            $v = strtolower($v);
                        }
                    }
                    unset($v);
                    $tuple = $ref['task_version_id'].':'.$ref['criterion_id'].':'.$ref['criterion_version_id'];
                    $subject = $ref['kind'] === 'lifecycle' ? '' : $ref[$ref['kind'].'_id'];
                    $key = $ref['kind'].':'.$subject.':'.$tuple;
                    if (isset($seen[$key]) || (isset($relations[$tuple]) && $relations[$tuple] !== $ref['relation'])) {
                        throw new InvalidArgumentException;
                    }
                    $seen[$key] = true;
                    $relations[$tuple] = $ref['relation'];
                }
                unset($ref);
                usort($data['references'], fn ($a, $b) => strcmp(self::canonical($a), self::canonical($b)));
            }

            return $data;
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid management contract.');
        }
    }

    public function contentHash(string $operation, array $data): string
    {
        unset($data['expected_revision']);
        if (isset($data['body']) && is_array($data['body'])) {
            unset($data['body']['expected_revision']);
        }

        $normalize = function ($v, string $key = '') use (&$normalize) {
            if (is_object($v)) {
                $v = get_object_vars($v);
            }
            if (! is_array($v)) {
                return $v;
            }
            foreach ($v as $k => &$child) {
                $child = $normalize($child, (string) $k);
            }
            unset($child);
            if ($key === 'references' && array_is_list($v)) {
                usort($v, fn ($a, $b) => strcmp(self::canonical($a), self::canonical($b)));
            }

            return $v;
        };

        return hash('sha256', self::canonical(['operation' => $operation, 'content' => $normalize($data)]));
    }

    /** Stable authority identity retains revisions and intervals; saved capture time stays in the receipt. */
    public static function proofHash(array $proof): string
    {
        unset($proof['captured_at']);

        return hash('sha256', self::canonical($proof));
    }

    /** @param array{format:string,subject_kind:string,subject_id:string,proof_hash:string,actor_id:string,purposes:list<string>} $slot */
    public static function proofKey(string $tenant, string $project, array $slot, ?string $department = null): string
    {
        if (array_key_exists('pinned_proof', $slot)) {
            $department = $slot['pinned_proof']['department_id'] ?? null;
        }
        $identity = array_intersect_key($slot, array_flip(['format', 'subject_kind', 'subject_id', 'proof_hash', 'actor_id', 'purposes']));
        sort($identity['purposes'], SORT_STRING);

        return 'original:'.hash('sha256', self::canonical($identity + ['tenant_id' => $tenant, 'project_id' => $project, 'department_id' => $department]));
    }
}
