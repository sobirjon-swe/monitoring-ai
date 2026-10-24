<?php

namespace App\Http\Middleware;

use App\Support\LocalManagementDatabase;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class EnsureLocalManagementDatabase
{
    public function handle(Request $request, Closure $next): Response
    {
        // Match the router's single path decode before token lookup can open a connection.
        $path = rawurldecode(rtrim($request->getPathInfo(), '/'));
        if (preg_match('~\A/(?:api|browser)/v1/projects/[^/]+/management(?:/|\z)~D', $path)) {
            try {
                app(LocalManagementDatabase::class)->assertRuntime();
            } catch (Throwable) {
                // A global middleware denial precedes router response preparation,
                // so prepare explicitly to preserve an empty body for HEAD too.
                return app(ExceptionHandler::class)->render($request, new ServiceUnavailableHttpException)->prepare($request);
            }
        }

        return $next($request);
    }
}
