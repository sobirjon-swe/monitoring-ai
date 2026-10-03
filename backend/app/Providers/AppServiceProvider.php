<?php

namespace App\Providers;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\DatabaseAccessGateway;
use App\Support\PrecisePostgresConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register a lazy resolver; no database connection is acquired during bootstrap.
        Connection::resolverFor('pgsql', fn ($connection, $database, $prefix, $config) => new PrecisePostgresConnection($connection, $database, $prefix, $config));
        $this->app->bind(AccessGateway::class, DatabaseAccessGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
