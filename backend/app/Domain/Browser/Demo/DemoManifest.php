<?php

namespace App\Domain\Browser\Demo;

use Illuminate\Support\Str;
use LogicException;

final readonly class DemoManifest
{
    public const SHA256 = '733e5758aa5c0ec8e1ffacd4e4d2d5a060697e4552c80df15e024f9e14ff3324';

    private function __construct(public array $data, public string $hash) {}

    public static function fromFile(string $path): self
    {
        DemoCredentialOutput::assertLocalPath($path);
        if (! is_file($path) || is_link($path) || filesize($path) > 16384) {
            throw new LogicException('Synthetic manifest refused.');
        }
        $raw = file_get_contents($path, false, null, 0, 16385);
        if (! is_string($raw) || ! hash_equals(self::SHA256, hash('sha256', $raw))) {
            throw new LogicException('Synthetic manifest refused.');
        }
        $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        $keys = ['version', 'synthetic', 'tenant_id', 'project_id', 'policy_id', 'source_id', 'technical_department_id', 'ba_department_id', 'source_version', 'personas'];
        if (array_keys($data) !== $keys || $data['version'] !== 'browser-demo-v1' || $data['synthetic'] !== true || $data['source_version'] !== 1 || ! array_is_list($data['personas']) || count($data['personas']) !== 6) {
            throw new LogicException('Synthetic manifest refused.');
        }
        $ids = [];
        foreach (array_slice($keys, 2, 6) as $key) {
            $ids[] = $data[$key];
        }
        foreach ($data['personas'] as $persona) {
            if (array_keys($persona) !== ['key', 'name', 'role', 'employee_id', 'user_id', 'subject_id', 'token_id']) {
                throw new LogicException('Synthetic manifest refused.');
            }
            foreach (['employee_id', 'user_id', 'subject_id', 'token_id'] as $key) {
                $ids[] = $persona[$key];
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new LogicException('Synthetic manifest refused.');
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new LogicException('Synthetic manifest refused.');
            }
        }

        return new self($data, self::SHA256);
    }
}
