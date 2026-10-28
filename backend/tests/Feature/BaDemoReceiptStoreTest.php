<?php

namespace Tests\Feature;

use App\Domain\Browser\BaDemo\BaDemoManifest;
use App\Domain\Browser\BaDemo\BaDemoReceipt;
use App\Domain\Browser\BaDemo\BaDemoReceiptStore;
use Tests\Support\AccessTestCase;

final class BaDemoReceiptStoreTest extends AccessTestCase
{
    public function test_provisioning_is_disabled_without_separate_authority(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        $receipt = new BaDemoReceipt(str_repeat('b', 64), str_repeat('a', 64), str_repeat('c', 64), '12345678', '1234567890abcdef', 'fedcba0987654321', new \DateTimeImmutable('+1 hour'));
        $this->expectExceptionMessage('Synthetic BA receipt provisioning unavailable.');
        app(BaDemoReceiptStore::class)->insert($manifest, $receipt);
    }
}
