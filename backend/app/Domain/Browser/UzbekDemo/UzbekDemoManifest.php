<?php

namespace App\Domain\Browser\UzbekDemo;

use App\Domain\Browser\Demo\DemoCredentialOutput;
use LogicException;

final readonly class UzbekDemoManifest
{
    public const SHA256 = 'bbd0d458fb183a84528d9da7049d7bdc3c98f3923b9b72d6fd3044300f6affe9';

    private function __construct(public array $data, public string $hash) {}

    public static function fromFile(string $path): self
    {
        DemoCredentialOutput::assertLocalPath($path);
        if (! is_file($path) || is_link($path) || filesize($path) > 16384) {
            throw new LogicException('Uzbek demo manifest refused.');
        }
        $raw = file_get_contents($path);
        if (! is_string($raw) || ! hash_equals(self::SHA256, hash('sha256', $raw))) {
            throw new LogicException('Uzbek demo manifest refused.');
        }

        return new self(json_decode($raw, true, 16, JSON_THROW_ON_ERROR), self::SHA256);
    }
}
