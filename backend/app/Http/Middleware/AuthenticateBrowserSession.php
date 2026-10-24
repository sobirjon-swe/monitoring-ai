<?php

namespace App\Http\Middleware;

use App\Domain\Browser\BrowserSessionService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateBrowserSession
{
    public function __construct(private readonly BrowserSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->remove('trusted_actor');
        $request->attributes->remove('trusted_tenant_id');
        if ($request->headers->has('Authorization')) {
            throw new AuthenticationException;
        }
        $verified = $this->sessions->resolve($request->session());
        if ($verified === null) {
            throw new AuthenticationException;
        }
        $request->attributes->set('trusted_actor', $verified->actor);
        $request->attributes->set('trusted_tenant_id', $verified->actor->tenantId);

        return $next($request);
    }
}
