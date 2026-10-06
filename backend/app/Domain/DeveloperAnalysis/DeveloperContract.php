<?php

namespace App\Domain\DeveloperAnalysis;

use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;

final class DeveloperContract
{
    public static function canonical(array $value): string
    {
        $sort = function ($value) use (&$sort) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($sort, $value);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    public function input(array $value): array
    {
        $this->validate('input', $value, 1048576);
        $hash = $value['input_hash'];
        unset($value['input_hash']);
        if (! hash_equals($hash, hash('sha256', self::canonical($value))) || ! hash_equals($value['snapshot']['hash'], hash('sha256', $value['snapshot']['redacted_diff']))
            || $value['snapshot']['base_sha'] === $value['snapshot']['head_sha'] || $value['snapshot']['hash'] !== $value['snapshot']['redaction']['hash']) {
            throw new InvalidArgumentException('Invalid developer input binding.');
        }
        foreach ($value['snapshot']['paths'] as $paths) {
            foreach ($paths as $path) {
                if ($path !== null) {
                    self::path($path);
                }
            }
        }
        if (strlen($value['snapshot']['redacted_diff']) > 524288) {
            throw new InvalidArgumentException('Invalid developer input size.');
        }
        $redaction = $value['snapshot']['redaction'];
        $lines = explode("\n", $value['snapshot']['redacted_diff']);
        $last = 0;
        foreach ($redaction['mapping'] as $mapped) {
            if ($redaction['policy'] !== 'redact-lines-v1' || $mapped['diff_line'] <= $last
                || ($lines[$mapped['diff_line'] - 1] ?? null) !== $mapped['prefix'].'[REDACTED]') {
                throw new InvalidArgumentException('Invalid redaction coordinates.');
            }
            $last = $mapped['diff_line'];
        }
        if (! app(SecretGate::class)->inspect($value['snapshot']['redacted_diff'], 'block-v1')['accepted']) {
            throw new InvalidArgumentException('Unsafe developer input.');
        }
        $value['input_hash'] = $hash;

        return $value;
    }

    public function output(array $value): array
    {
        $this->validate('output', $value, 262144);
        foreach ($value['files'] as $file) {
            foreach (['old_path', 'new_path'] as $key) {
                if ($file[$key] !== null) {
                    self::path($file[$key]);
                }
            }
        }
        foreach ($value['locators'] as $locator) {
            self::path($locator['path']);
        }

        return $value;
    }

    public static function path(string $path): void
    {
        if ($path === '' || strlen($path) > 500 || ! mb_check_encoding($path, 'UTF-8') || preg_match('/[\x00-\x20\x7f\\\\:]|^\//', $path)
            || array_intersect(explode('/', $path), ['..', '.', ''])) {
            throw new InvalidArgumentException('Unsafe logical path.');
        }
    }

    private function validate(string $kind, array $value, int $limit): void
    {
        try {
            $json = self::canonical($value);
            $schema = json_decode(file_get_contents(base_path('../docs/contracts/developer-analysis-'.$kind.'-v1.schema.json')), false, 64, JSON_THROW_ON_ERROR);
            if (strlen($json) > $limit || ! (new CompliantValidator)->validate(json_decode($json, false, 64, JSON_THROW_ON_ERROR), $schema)->isValid()) {
                throw new InvalidArgumentException;
            }
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid developer contract.');
        }
    }
}
