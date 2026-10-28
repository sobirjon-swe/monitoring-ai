<?php

namespace App\Domain\Browser;

use App\Domain\Access\TrustedActor;
use App\Domain\Identity\LocalCredentialVerifier;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BrowserAttemptCleanup
{
    public function clearDeniedSlot(string $id, string $hash, string $actor, string $project, string $clarification): void
    {
        $slot = DB::table('browser_ba_attempts')->where(['id' => $id, 'session_hash' => $hash, 'actor_id' => $actor, 'project_id' => $project, 'clarification_id' => $clarification])
            ->whereIn('state', ['claimed', 'completed'])->lockForUpdate()->first();
        if (! $slot) {
            return;
        }
        try {
            $saved = json_decode(Crypt::decryptString($slot->ciphertext), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($saved) || ! is_string($saved['key'] ?? null)) {
                throw new InvalidArgumentException;
            }
            app(BrowserBaAuthority::class)->check(new TrustedActor($actor, $slot->tenant_id), $project, $clarification, $saved['key']);

            return;
        } catch (AccessDeniedHttpException|NotFoundHttpException|DecryptException|JsonException|InvalidArgumentException) {
            DB::table('browser_ba_attempts')->where('id', $id)->update(['state' => 'tombstone', 'ciphertext' => null]);
        }
    }

    public function run(string $tenant, int $limit, ?string $after = null, ?string $through = null): ?string
    {
        if (! Str::isUuid($tenant) || ! in_array($tenant, config('browser.attempt_cleanup_tenants', []), true)
            || $limit < 1 || $limit > 100 || ($after !== null && ! Str::isUuid($after))
            || ($through !== null && ! Str::isUuid($through))) {
            throw new InvalidArgumentException('Invalid cleanup scope.');
        }
        $candidates = app(TenantContext::class)->run($tenant, fn () => DB::table('browser_ba_attempts')
            ->whereIn('state', ['claimed', 'completed'])->when($after !== null, fn ($q) => $q->where('id', '>', $after))
            ->when($through !== null, fn ($q) => $q->where('id', '<=', $through))
            ->orderBy('id')->limit($limit)->get(['id', 'session_hash']));
        foreach ($candidates as $candidate) {
            app(TenantContext::class)->run($tenant, function () use ($candidate): void {
                $previous = DB::scalar("select nullif(current_setting('app.browser_session_hash', true), '')");
                DB::select("select set_config('app.browser_session_hash', ?, true)", [$candidate->session_hash]);
                $fence = DB::table('browser_anonymous_fences')->where('session_hash', $candidate->session_hash)->lockForUpdate()->first();
                DB::select("select set_config('app.browser_session_hash', ?, true)", [$previous ?? '']);
                $tenantFence = DB::table('browser_session_fences')->where('session_hash', $candidate->session_hash)->lockForUpdate()->first();
                $attempt = DB::table('browser_ba_attempts')->where('id', $candidate->id)->lockForUpdate()->first();
                if (! $attempt || $attempt->session_hash !== $candidate->session_hash
                    || ! in_array($attempt->state, ['claimed', 'completed'], true)) {
                    return;
                }
                $actorActive = DB::table('users')->where('id', $attempt->actor_id)->where('status', 'active')->exists();
                $tenantActive = DB::table('tenants')->where('id', $attempt->tenant_id)->where('status', 'active')->exists();
                $invalid = ! $fence || $fence->revoked_at !== null || $fence->successor_hash !== null
                    || CarbonImmutable::parse($fence->expires_at)->lessThanOrEqualTo(now())
                    || ! $tenantFence || $tenantFence->revoked_at !== null || $tenantFence->successor_hash !== null
                    || $tenantFence->user_id !== $attempt->actor_id || CarbonImmutable::parse($tenantFence->expires_at)->lessThanOrEqualTo(now())
                    || ! $actorActive || ! $tenantActive
                    || CarbonImmutable::parse($attempt->expires_at)->lessThanOrEqualTo(now());
                if (! $invalid) {
                    try {
                        $saved = json_decode(Crypt::decryptString($attempt->ciphertext), true, 32, JSON_THROW_ON_ERROR);
                        if (! is_array($saved) || ! is_string($saved['key'] ?? null)) {
                            throw new InvalidArgumentException;
                        }
                        $binding = $saved['binding'] ?? null;
                        if (! is_array($binding) || ($verified = app(LocalCredentialVerifier::class)->verifyBinding($binding)) === null
                            || $verified->actor->id !== $attempt->actor_id || $verified->actor->tenantId !== $attempt->tenant_id) {
                            throw new AccessDeniedHttpException;
                        }
                        app(BrowserBaAuthority::class)->check(new TrustedActor($attempt->actor_id, $attempt->tenant_id), $attempt->project_id, $attempt->clarification_id, $saved['key']);
                    } catch (ConflictHttpException $conflict) {
                        DB::table('projects')->where('id', $attempt->project_id)->lockForUpdate()->first();
                        $state = DB::table('lifecycle_project_state')->where('project_id', $attempt->project_id)->lockForUpdate()->first();
                        if (! $state?->is_closed) {
                            throw $conflict;
                        }
                        $invalid = true;
                    } catch (AccessDeniedHttpException|NotFoundHttpException|DecryptException|JsonException|InvalidArgumentException) {
                        $invalid = true;
                    }
                }
                if ($invalid) {
                    DB::table('browser_ba_attempts')->where('id', $candidate->id)->update(['state' => 'tombstone', 'ciphertext' => null]);
                }
            });
        }

        return $candidates->count() === $limit ? $candidates->last()->id : null;
    }
}
