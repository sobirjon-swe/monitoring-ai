<?php

namespace App\Domain\Ba;

use InvalidArgumentException;

final class BaPagination
{
    public function encode(array $scope, array $position): string
    {
        $body = base64_encode(BaContract::canonical(['scope' => $scope, 'position' => $position]));

        return $body.'.'.hash_hmac('sha256', $body, $this->key());
    }

    public function decode(string $cursor, array $scope): array
    {
        if (strlen($cursor) > 4096 || substr_count($cursor, '.') !== 1) {
            throw new InvalidArgumentException('Invalid BA cursor.');
        }
        [$body, $mac] = explode('.', $cursor);
        if (! hash_equals(hash_hmac('sha256', $body, $this->key()), $mac)) {
            throw new InvalidArgumentException('Invalid BA cursor.');
        }
        try {
            $value = json_decode(base64_decode($body, true), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid BA cursor.');
        }
        if (! is_array($value) || BaContract::canonical($value['scope'] ?? []) !== BaContract::canonical($scope) || ! is_array($value['position'] ?? null)) {
            throw new InvalidArgumentException('Invalid BA cursor.');
        }

        return $value['position'];
    }

    private function key(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new \LogicException('BA cursor key unavailable.');
        }

        return $key;
    }
}
