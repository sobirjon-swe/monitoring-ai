<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\PostgresConnection;

class PrecisePostgresConnection extends PostgresConnection
{
    public function prepareBindings(array $bindings): array
    {
        // Keep fractional instants for inserts and authorization/event-time comparisons alike.
        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = CarbonImmutable::instance($value)->utc()->format('Y-m-d H:i:s.uP');
            }
        }

        return parent::prepareBindings($bindings);
    }
}
