<?php

namespace App\Support;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class TenantContext
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function run(string $tenantId, Closure $work): mixed
    {
        if (! Str::isUuid($tenantId)) {
            throw new InvalidArgumentException('Tenant ID must be a UUID.');
        }
        $tenantId = strtolower($tenantId);
        $connection = $this->database->connection('pgsql');
        $previous = $connection->scalar("select nullif(current_setting('app.tenant_id', true), '')");
        if ($previous !== null && ($previous !== $tenantId || $connection->transactionLevel() === 0)) {
            throw new LogicException('Tenant context cannot be switched or reused outside its transaction.');
        }

        return $connection->transaction(function () use ($connection, $tenantId, $previous, $work): mixed {
            $connection->select("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            $result = $work();
            // Committing a savepoint retains SET LOCAL, so restore even inside an existing transaction.
            // On exceptions PostgreSQL rollback restores the previous savepoint/transaction setting.
            $connection->select("select set_config('app.tenant_id', ?, true)", [$previous ?? '']);

            return $result;
        });
    }
}
