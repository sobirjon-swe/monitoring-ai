<?php

namespace App\Domain\Identity;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LocalTokenAuthenticator
{
    public function __construct(private readonly TenantContext $context) {}

    public function authenticate(?string $token): ?TrustedActor
    {
        if (! app()->environment(['local', 'testing']) || ! is_string($token)
            || ! preg_match('/\A([a-f0-9-]{36})\.([a-f0-9-]{36})\.([a-f0-9]{64})\z/D', $token, $parts)
            || ! Str::isUuid($parts[1]) || ! Str::isUuid($parts[2])) {
            return null;
        }

        // The unverified tenant is only a bounded candidate for this token lookup under RLS.
        return $this->context->run($parts[1], function () use ($parts): ?TrustedActor {
            $row = DB::table('auth_tokens as t')->join('auth_subjects as s', function ($join): void {
                $join->on('s.id', '=', 't.auth_subject_id')->on('s.tenant_id', '=', 't.tenant_id');
            })->join('users as u', function ($join): void {
                $join->on('u.id', '=', 's.user_id')->on('u.tenant_id', '=', 's.tenant_id');
            })->join('tenants as tenant', 'tenant.id', '=', 'u.tenant_id')
                ->where('t.id', $parts[2])->whereNull('t.revoked_at')->whereNull('s.revoked_at')
                ->where('t.expires_at', '>', now())->where('s.issuer', 'local-development')
                ->where('u.status', 'active')->where('tenant.status', 'active')
                ->first(['u.id', 'u.tenant_id', 't.secret_hash']);
            if ($row === null || ! hash_equals($row->secret_hash, hash('sha256', $parts[3]))) {
                return null;
            }

            return new TrustedActor($row->id, $row->tenant_id);
        });
    }
}
