<?php

namespace App\Domain\Browser\BaDemo;

use Illuminate\Database\Connection;
use LogicException;

/** Setup remains disabled until its privilege and concurrency invariants are proved. */
final class BaDemoSetupRoleGuard
{
    public function assertSetupConnection(Connection $connection): never
    {
        $config = $connection->getConfig();
        if (! app()->environment('testing') || ($config['driver'] ?? null) !== 'pgsql'
            || ($config['host'] ?? null) !== '127.0.0.1' || (string) ($config['port'] ?? '') !== '55432'
            || ($config['database'] ?? null) !== 'monitoring_ai_test'
            || ($config['username'] ?? null) !== 'monitoring_ai_ba_demo_setup'
            || ! in_array($config['url'] ?? null, [null, ''], true)
            || ! empty($config['read']) || ! empty($config['write']) || ! empty($config['options'])) {
            throw new LogicException('Synthetic BA setup connection refused.');
        }

        // No connection is authorized until exact marker, role, policy and exclusion checks exist.
        throw new LogicException('Synthetic BA setup authority unavailable.');
    }
}
