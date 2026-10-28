<?php

namespace App\Domain\Browser;

use App\Domain\Identity\LocalCredentialVerifier;
use App\Domain\Identity\VerifiedLocalCredential;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class BrowserSessionService
{
    public const BINDING = 'browser_credential';

    private const FENCE_MARKER = 'browser_fence_v1';

    public function __construct(private readonly LocalCredentialVerifier $verifier) {}

    public function initialize(Session $session): bool
    {
        if (! $this->validKey()) {
            return false;
        }
        if ($session->get(self::FENCE_MARKER) === null) {
            // A markerless copy is never promoted using its old identifier.
            $session->invalidate();
            $session->regenerateToken();
        }

        return DB::transaction(function () use ($session): bool {
            $row = $this->lockAnonymousFence($session, true);

            return $row !== null && $row->revoked_at === null && $row->successor_hash === null
                && CarbonImmutable::parse($row->expires_at)->greaterThan(now());
        });
    }

    public function runLocked(Session $session, callable $work): mixed
    {
        return DB::transaction(function () use ($session, $work): mixed {
            $verified = $this->resolve($session);
            if ($verified === null) {
                throw new AuthenticationException;
            }

            return app(TenantContext::class)->run($verified->actor->tenantId, function () use ($session, $verified, $work): mixed {
                DB::table('tenants')->where('id', $verified->actor->tenantId)->sharedLock()->first();
                DB::table('users')->where('id', $verified->actor->id)->sharedLock()->first();
                DB::table('auth_subjects')->where('id', $verified->subjectId)->sharedLock()->first();
                DB::table('auth_tokens')->where('id', $verified->tokenId)->sharedLock()->first();
                if ($this->verifier->verifyBinding($session->get(self::BINDING)) === null) {
                    throw new AuthenticationException;
                }

                $result = $work($verified);
                if ($this->verifier->verifyBinding($session->get(self::BINDING)) === null) {
                    throw new AuthenticationException;
                }

                return $result;
            });
        });
    }

    public function start(Session $session, string $token, array|false|null $replacement = null): ?VerifiedLocalCredential
    {
        $marker = $session->get(self::FENCE_MARKER);
        if (! $this->validKey() || ! is_string($marker) || ! hash_equals($this->sessionHash($session), $marker)) {
            return null;
        }
        if ($replacement === null && $session->has(self::BINDING)) {
            $replacement = $this->beginReplacement($session);
        }
        $session->forget(self::BINDING);
        if ($replacement === false) {
            return null;
        }

        return DB::transaction(function () use ($session, $token, $replacement): ?VerifiedLocalCredential {
            $oldHash = $this->sessionHash($session);
            $row = $this->lockAnonymousFence($session, true);
            if (! $row || $row->revoked_at !== null || $row->successor_hash !== null || CarbonImmutable::parse($row->expires_at)->lessThanOrEqualTo(now())) {
                return null;
            }
            $verified = $this->startLocked($session, $token, $replacement);
            if ($verified === null) {
                return null;
            }
            $newHash = $this->sessionHash($session);
            $this->withFenceContext($newHash, function () use ($newHash): void {
                DB::table('browser_anonymous_fences')->insert(['session_hash' => $newHash, 'key_version' => 1, 'expires_at' => now()->addMinutes(120)]);
            });
            $this->withFenceContext($oldHash, fn () => DB::table('browser_anonymous_fences')->where('session_hash', $oldHash)->update(['successor_hash' => $newHash]));
            $session->put(self::FENCE_MARKER, $newHash);

            return $verified;
        });
    }

    private function startLocked(Session $session, string $token, array|false|null $replacement): ?VerifiedLocalCredential
    {
        $verified = $this->verifier->verifyToken($token);
        if ($verified === null || ($replacement !== null && $replacement['tenant'] !== $verified->actor->tenantId)) {
            return null;
        }
        $started = app(TenantContext::class)->run($verified->actor->tenantId, function () use ($session, $verified, $replacement): bool {
            if ($replacement !== null) {
                $old = DB::table('browser_session_fences')->where('session_hash', $replacement['hash'])->lockForUpdate()->first();
                if (! $old || (int) $old->generation !== $replacement['generation'] || $old->successor_hash !== null) {
                    return false;
                }
            }
            $session->regenerate(true);
            $newHash = $this->sessionHash($session);
            DB::table('browser_session_fences')->insert([
                'tenant_id' => $verified->actor->tenantId,
                'session_hash' => $newHash,
                'user_id' => $verified->actor->id,
                'expires_at' => $verified->expiresAt->min(now()->addMinutes(120))->format('Y-m-d H:i:s.uP'),
            ]);
            if ($replacement !== null) {
                DB::table('browser_session_fences')->where('session_hash', $replacement['hash'])->update(['successor_hash' => $newHash]);
            }

            return true;
        });
        if (! $started) {
            return null;
        }
        $session->put(self::BINDING, ['version' => 1, 'tenant_id' => $verified->actor->tenantId,
            'token_id' => $verified->tokenId, 'subject_id' => $verified->subjectId, 'user_id' => $verified->actor->id,
            'fingerprint' => $verified->fingerprint, 'last_seen_at' => now()->format('Y-m-d\TH:i:s.uP')]);

        return $verified;
    }

    public function resolve(Session $session): ?VerifiedLocalCredential
    {
        $binding = $session->get(self::BINDING);
        try {
            $verified = DB::transaction(function () use ($session, $binding): ?VerifiedLocalCredential {
                $fence = $this->lockAnonymousFence($session, false);
                if (! $fence || $fence->revoked_at !== null || $fence->successor_hash !== null || CarbonImmutable::parse($fence->expires_at)->lessThanOrEqualTo(now())) {
                    return null;
                }
                $lastSeen = is_array($binding) && is_string($binding['last_seen_at'] ?? null) ? CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $binding['last_seen_at']) : null;

                return $lastSeen !== null && $lastSeen->lessThanOrEqualTo(now()) && $lastSeen->addMinutes(120)->greaterThan(now())
                    && is_string($binding['tenant_id'] ?? null) && Str::isUuid($binding['tenant_id'])
                    ? app(TenantContext::class)->run($binding['tenant_id'], function () use ($session, $binding) {
                        $row = DB::table('browser_session_fences')->where('session_hash', $this->sessionHash($session))->lockForUpdate()->first();
                        if (! $row || $row->revoked_at !== null || CarbonImmutable::parse($row->expires_at)->lessThanOrEqualTo(now()) || $row->user_id !== ($binding['user_id'] ?? null)) {
                            return null;
                        }
                        $verified = $this->verifier->verifyBinding($binding);
                        if ($verified !== null) {
                            DB::table('browser_session_fences')->where('session_hash', $this->sessionHash($session))->update(['expires_at' => $verified->expiresAt->min(now()->addMinutes(120))->format('Y-m-d H:i:s.uP')]);
                        }

                        return $verified;
                    }) : null;
            });
        } catch (Throwable) {
            $verified = null;
        }
        if ($verified === null) {
            if ($binding === null) {
                return null;
            }
            // Keep the binding when cleanup fails so a later request can retry it.
            try {
                $this->end($session);
            } catch (Throwable) {
                return null;
            }
            $session->forget(self::BINDING);

            return null;
        }
        $binding['last_seen_at'] = now()->format('Y-m-d\TH:i:s.uP');
        $session->put(self::BINDING, $binding);

        return $verified;
    }

    public function end(Session $session): void
    {
        if (! $this->validKey()) {
            throw new \RuntimeException('Browser session key is unavailable.');
        }
        DB::transaction(function () use ($session): void {
            $row = $this->lockAnonymousFence($session, true);
            if (! $row) {
                throw new \RuntimeException('Invalid browser session fence.');
            }
            $hash = $this->sessionHash($session);
            do {
                $next = $row->successor_hash;
                $this->withFenceContext($hash, fn () => DB::table('browser_anonymous_fences')->where('session_hash', $hash)->update(['revoked_at' => now()]));
                if ($next === null) {
                    break;
                }
                $hash = $next;
                $row = $this->withFenceContext($hash, fn () => DB::table('browser_anonymous_fences')->where('session_hash', $hash)->lockForUpdate()->first());
                if (! $row) {
                    throw new \RuntimeException('Invalid browser session lineage.');
                }
            } while (true);
            $this->revoke($session);
        });
        $session->invalidate();
        $session->regenerateToken();
    }

    public function revoke(Session $session): void
    {
        $binding = $session->get(self::BINDING);
        if (! is_array($binding) || ! is_string($binding['tenant_id'] ?? null) || ! Str::isUuid($binding['tenant_id'])) {
            return;
        }
        app(TenantContext::class)->run($binding['tenant_id'], function () use ($session): void {
            $hash = $this->sessionHash($session);
            while ($hash !== null) {
                $row = DB::table('browser_session_fences')->where('session_hash', $hash)->lockForUpdate()->first();
                if (! $row) {
                    break;
                }
                DB::table('browser_session_fences')->where('session_hash', $hash)->update(['revoked_at' => now(), 'generation' => DB::raw('generation + 1')]);
                $this->clearAttempts($hash);
                $hash = $row->successor_hash;
            }
        });
    }

    public function beginReplacement(Session $session): array|false|null
    {
        $marker = $session->get(self::FENCE_MARKER);
        if ($marker !== null && (! is_string($marker) || ! hash_equals($this->sessionHash($session), $marker))) {
            return false;
        }
        $binding = $session->get(self::BINDING);
        if ($binding === null) {
            return null;
        }
        if (! is_array($binding) || ! is_string($binding['tenant_id'] ?? null) || ! Str::isUuid($binding['tenant_id'])) {
            return false;
        }

        return app(TenantContext::class)->run($binding['tenant_id'], function () use ($session, $binding): array|false {
            $hash = $this->sessionHash($session);
            $row = DB::table('browser_session_fences')->where('session_hash', $hash)->lockForUpdate()->first();
            if (! $row || $row->revoked_at !== null || $row->user_id !== ($binding['user_id'] ?? null)) {
                return false;
            }
            $generation = (int) $row->generation + 1;
            DB::table('browser_session_fences')->where('session_hash', $hash)->update(['revoked_at' => now(), 'generation' => $generation]);
            $this->clearAttempts($hash);

            return ['tenant' => $binding['tenant_id'], 'hash' => $hash, 'generation' => $generation];
        });
    }

    private function sessionHash(Session $session): string
    {
        return hash_hmac('sha256', "monitoring.browser.session.v1\0".$session->getId(), (string) config('app.key'));
    }

    private function validKey(): bool
    {
        return is_string(config('app.key')) && config('app.key') !== '';
    }

    private function clearAttempts(string $hash): void
    {
        DB::table('browser_ba_attempts')->where('session_hash', $hash)
            ->whereIn('state', ['claimed', 'completed'])
            ->update(['state' => 'tombstone', 'ciphertext' => null]);
    }

    private function lockAnonymousFence(Session $session, bool $create): mixed
    {
        $hash = $this->sessionHash($session);
        $marker = $session->get(self::FENCE_MARKER);
        if ($marker !== null && (! is_string($marker) || ! hash_equals($hash, $marker))) {
            return null;
        }

        return $this->withFenceContext($hash, function () use ($hash, $session, $marker, $create) {
            if ($create && $marker === null) {
                DB::table('browser_anonymous_fences')->insertOrIgnore(['session_hash' => $hash, 'key_version' => 1, 'expires_at' => now()->addMinutes(120)]);
            }
            $row = DB::table('browser_anonymous_fences')->where('session_hash', $hash)->lockForUpdate()->first();
            if ($row && $marker === null) {
                $session->put(self::FENCE_MARKER, $hash);
            }

            return $row;
        });
    }

    private function withFenceContext(string $hash, callable $work): mixed
    {
        $previous = DB::scalar("select nullif(current_setting('app.browser_session_hash', true), '')");
        DB::select("select set_config('app.browser_session_hash', ?, true)", [$hash]);
        $result = $work();
        // A failed statement is restored by the surrounding transaction rollback.
        DB::select("select set_config('app.browser_session_hash', ?, true)", [$previous ?? '']);

        return $result;
    }
}
