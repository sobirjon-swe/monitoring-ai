<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RequireTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Only an authentication/authorization middleware may set this server-side attribute.
        $tenantId = $request->attributes->get('trusted_tenant_id');
        if (! is_string($tenantId) || ! Str::isUuid($tenantId)) {
            throw new AccessDeniedHttpException;
        }

        return $this->context->run($tenantId, fn () => $next($request));
    }
}
