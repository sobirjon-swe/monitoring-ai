<?php

namespace App\Domain\Browser\BaDemo;

use DateTimeImmutable;
use InvalidArgumentException;

/** Data only; possession of this object does not authorize publication. */
final readonly class BaDemoReceipt
{
    public function __construct(
        public string $snapshotHash,
        public string $secretHash,
        public string $ciphertextHash,
        public string $volumeId,
        public string $parentFileId,
        public string $fileId,
        public DateTimeImmutable $expiresAt,
    ) {
        foreach ([$snapshotHash, $secretHash, $ciphertextHash] as $hash) {
            if (! preg_match('/\A[a-f0-9]{64}\z/D', $hash)) {
                throw new InvalidArgumentException('Invalid synthetic receipt.');
            }
        }
        if (! preg_match('/\A[a-f0-9]{8}\z/D', $volumeId)
            || ! preg_match('/\A[a-f0-9]{16}\z/D', $parentFileId)
            || ! preg_match('/\A[a-f0-9]{16}\z/D', $fileId)
            || $parentFileId === $fileId) {
            throw new InvalidArgumentException('Invalid synthetic receipt.');
        }
    }
}
