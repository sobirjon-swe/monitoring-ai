<?php

namespace App\Domain\Browser\BaDemo;

use App\Domain\Browser\Demo\DemoCredentialOutput;
use Illuminate\Support\Str;
use LogicException;

final readonly class BaDemoManifest
{
    public const SHA256 = '156a475518989fee0ba15d9fb9b210e75ea6be548af3adb9194e111394363371';

    private function __construct(public array $data, public string $hash) {}

    public static function fromFile(string $path): self
    {
        DemoCredentialOutput::assertLocalPath($path);
        if (! is_file($path) || is_link($path) || filesize($path) > 16384) {
            throw new LogicException('Synthetic BA manifest refused.');
        }
        $raw = file_get_contents($path, false, null, 0, 16385);
        if (! is_string($raw) || ! hash_equals(self::SHA256, hash('sha256', $raw))) {
            throw new LogicException('Synthetic BA manifest refused.');
        }
        $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_keys($data) !== ['version', 'synthetic', 'tenant_id', 'project_id', 'policy_id', 'source_id', 'department_id', 'source_version', 'writer', 'producer']
            || $data['version'] !== 'ba-context-demo-v1' || $data['synthetic'] !== true || $data['source_version'] !== 1
            || ! is_array($data['writer']) || array_keys($data['writer']) !== ['name', 'role', 'employee_id', 'user_id', 'subject_id', 'token_id', 'grants']
            || ! is_array($data['producer']) || array_keys($data['producer']) !== ['name', 'role', 'employee_id', 'user_id', 'grants']
            || $data['writer']['name'] !== 'Synthetic BA writer' || $data['producer']['name'] !== 'Synthetic BA source producer'
            || $data['writer']['role'] !== 'ba' || $data['producer']['role'] !== 'ba'
            || $data['writer']['grants'] !== ['project.read', 'ba.read', 'ba.source.read', 'ba.request.manage']
            || $data['producer']['grants'] !== ['ba.fixture.manage', 'ba.request.manage', 'ba.source.read']) {
            throw new LogicException('Synthetic BA manifest refused.');
        }
        $ids = array_map(fn (string $key) => $data[$key], ['tenant_id', 'project_id', 'policy_id', 'source_id', 'department_id']);
        foreach (['employee_id', 'user_id', 'subject_id', 'token_id'] as $key) {
            $ids[] = $data['writer'][$key];
        }
        foreach (['employee_id', 'user_id'] as $key) {
            $ids[] = $data['producer'][$key];
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new LogicException('Synthetic BA manifest refused.');
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new LogicException('Synthetic BA manifest refused.');
            }
        }

        return new self($data, self::SHA256);
    }
}
