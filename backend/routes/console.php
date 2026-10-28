<?php

use App\Domain\Browser\BrowserAttemptCleanup;
use App\Support\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('browser:cleanup-attempts', function () {
    foreach (config('browser.attempt_cleanup_tenants', []) as $tenant) {
        app(TenantContext::class)->run($tenant, function () use ($tenant): void {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['browser-attempt-cleanup:'.$tenant]);
            DB::table('browser_attempt_cleanup_cursors')->insertOrIgnore(['tenant_id' => $tenant, 'after_id' => null]);
            $cursor = DB::table('browser_attempt_cleanup_cursors')->where('tenant_id', $tenant)->lockForUpdate()->first();
            $through = $cursor->through_id ?? DB::table('browser_ba_attempts')->whereIn('state', ['claimed', 'completed'])->orderByDesc('id')->value('id');
            $next = $through === null ? null : app(BrowserAttemptCleanup::class)->run($tenant, 100, $cursor->after_id, $through);
            DB::table('browser_attempt_cleanup_cursors')->where('tenant_id', $tenant)->update(['after_id' => $next, 'through_id' => $next === null ? null : $through]);
        });
    }
})->purpose('Clear expired or revoked browser attempt secrets in configured tenants');

Schedule::command('browser:cleanup-attempts')->everyMinute()->when(fn () => app()->environment(['local', 'testing']));
