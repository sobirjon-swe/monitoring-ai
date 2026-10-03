<?php

namespace App\Providers;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\DenyAccessGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AccessGateway::class, DenyAccessGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
