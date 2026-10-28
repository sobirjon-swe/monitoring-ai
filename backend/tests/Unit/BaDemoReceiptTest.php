<?php

namespace Tests\Unit;

use App\Domain\Browser\BaDemo\BaDemoReceipt;
use PHPUnit\Framework\TestCase;

final class BaDemoReceiptTest extends TestCase
{
    public function test_native_identity_and_hashes_are_strictly_canonical(): void
    {
        $this->assertTrue(class_exists(BaDemoReceipt::class), 'Typed receipt is missing.');
        $receipt = new BaDemoReceipt(str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), '12345678', '1234567890abcdef', 'fedcba0987654321', new \DateTimeImmutable('+1 hour'));
        $this->assertSame('fedcba0987654321', $receipt->fileId);
        $this->expectException(\InvalidArgumentException::class);
        new BaDemoReceipt(str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), '12345678', '1234567890abcdef', 'FEDCBA0987654321', new \DateTimeImmutable('+1 hour'));
    }
}
