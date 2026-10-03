<?php

namespace App\Http\Middleware;

use App\Domain\Identity\LocalTokenAuthenticator;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateLocalToken
{
    public function __construct(private readonly LocalTokenAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->remove('trusted_actor');
        $request->attributes->remove('trusted_tenant_id');
        $actor = in_array($request->ip(), ['127.0.0.1', '::1'], true)
            ? $this->authenticator->authenticate($request->bearerToken()) : null;
        if ($actor === null) {
            throw new AuthenticationException;
        }
        $request->attributes->set('trusted_actor', $actor);
        $request->attributes->set('trusted_tenant_id', $actor->tenantId);

        return $next($request);
    }
}
