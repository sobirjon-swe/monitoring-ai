<?php

namespace App\Domain\Identity;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LocalCredentialVerifier
{
    public function __construct(private readonly TenantContext $context) {}

    public function verifyToken(?string $token): ?VerifiedLocalCredential
    {
        if (! is_string($token) || ! preg_match('/\A([a-f0-9-]{36})\.([a-f0-9-]{36})\.([a-f0-9]{64})\z/D', $token, $parts)
            || ! Str::isUuid($parts[1]) || ! Str::isUuid($parts[2])) {
            return null;
        }

        return $this->lookup($parts[1], $parts[2], fn ($row) => hash_equals($row->secret_hash, hash('sha256', $parts[3])));
    }

    public function verifyBinding(array $binding): ?VerifiedLocalCredential
    {
        $keys = ['version', 'tenant_id', 'token_id', 'subject_id', 'user_id', 'fingerprint', 'last_seen_at'];
        if (count($binding) !== count($keys) || array_diff($keys, array_keys($binding)) || ($binding['version'] ?? null) !== 1) {
            return null;
        }
        foreach (['tenant_id', 'token_id', 'subject_id', 'user_id'] as $key) {
            if (! is_string($binding[$key]) || ! Str::isUuid($binding[$key])) {
                return null;
            }
        }
        if (! is_string($binding['fingerprint']) || ! preg_match('/\A[a-f0-9]{64}\z/D', $binding['fingerprint'])) {
            return null;
        }
        $verified = $this->lookup($binding['tenant_id'], $binding['token_id'], fn () => true);

        return $verified !== null && $verified->subjectId === $binding['subject_id'] && $verified->actor->id === $binding['user_id']
            && hash_equals($verified->fingerprint, $binding['fingerprint']) ? $verified : null;
    }

    private function lookup(string $tenant, string $tokenId, callable $accept): ?VerifiedLocalCredential
    {
        if (! app()->environment(['local', 'testing'])) {
            return null;
        }

        // An unverified tenant is only a bounded lookup candidate under RLS.
        return $this->context->run($tenant, function () use ($tokenId, $accept): ?VerifiedLocalCredential {
            $row = DB::table('auth_tokens as t')->join('auth_subjects as s', function ($join): void {
                $join->on('s.id', '=', 't.auth_subject_id')->on('s.tenant_id', '=', 't.tenant_id');
            })->join('users as u', function ($join): void {
                $join->on('u.id', '=', 's.user_id')->on('u.tenant_id', '=', 's.tenant_id');
            })->join('tenants as tenant', 'tenant.id', '=', 'u.tenant_id')
                ->where('t.id', $tokenId)->whereNull('t.revoked_at')->whereNull('s.revoked_at')
                ->where('s.issuer', 'local-development')->where('u.status', 'active')->where('tenant.status', 'active')
                ->first(['u.id', 'u.tenant_id', 'u.employee_id', 'u.display_name', 't.secret_hash', 't.expires_at', 's.id as subject_id', 's.subject', 's.issuer']);
            if ($row === null || ! $accept($row)) {
                return null;
            }
            $expires = CarbonImmutable::parse($row->expires_at);
            if ($expires->lessThanOrEqualTo(now())) {
                return null;
            }
            $identity = [$row->tenant_id, $tokenId, $row->subject_id, $row->id, $row->subject, $row->issuer, $row->secret_hash, $expires->format('Y-m-d\TH:i:s.uP')];
            $fingerprint = hash_hmac('sha256', "monitoring.browser.credential.v1\0".json_encode($identity, JSON_THROW_ON_ERROR), (string) config('app.key'));

            return new VerifiedLocalCredential(new TrustedActor($row->id, $row->tenant_id), $tokenId, $row->subject_id, $expires, $fingerprint, $row->employee_id, $row->display_name);
        });
    }
}
