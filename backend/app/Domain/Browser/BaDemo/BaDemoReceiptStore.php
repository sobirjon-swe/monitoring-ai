<?php

namespace App\Domain\Browser\BaDemo;

use LogicException;

/** Disabled until a separate provisioning authority can bind and verify the receipt. */
final class BaDemoReceiptStore
{
    public function insert(BaDemoManifest $manifest, BaDemoReceipt $receipt): never
    {
        throw new LogicException('Synthetic BA receipt provisioning unavailable.');
    }
}
