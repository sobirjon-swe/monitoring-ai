<?php

namespace App\Http\Middleware;

use App\Support\LocalManagementDatabase;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final class EnsureLocalBrowserRuntime
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = rawurldecode($request->getPathInfo());
        if (! str_starts_with($path, '/browser') && $path !== '/app' && ! str_starts_with($path, '/app/')) {
            return $next($request);
        }
        try {
            $origin = config('browser.origin');
            if (! app()->environment(['local', 'testing']) || $origin !== 'http://127.0.0.1:18080'
                || config('browser.client') !== '127.0.0.1'
                || $request->getSchemeAndHttpHost() !== $origin || $request->ip() !== config('browser.client')
                || $request->headers->has('Forwarded') || $request->headers->has('X-Forwarded-Host')
                || $request->headers->has('X-Forwarded-For') || $request->headers->has('X-Forwarded-Proto')
                || ! in_array(config('session.driver'), app()->environment('testing') ? ['file', 'array'] : ['file'], true)
                || config('session.domain') !== null || config('session.secure') !== false
                || config('session.http_only') !== true || config('session.same_site') !== 'lax'
                || config('session.path') !== '/' || config('session.partitioned') !== false
                || config('session.lifetime') !== 120 || config('session.serialization') !== 'json'
                || config('session.files') !== storage_path('framework/sessions') || ! is_string(config('app.key')) || config('app.key') === '') {
                throw new \LogicException('Local browser runtime refused.');
            }
            app(LocalManagementDatabase::class)->assertRuntime();
        } catch (Throwable) {
            return app(ExceptionHandler::class)->render($request, new ServiceUnavailableHttpException)->header('Cache-Control', 'no-store')->prepare($request);
        }
        // Render inside this boundary so errors receive the same privacy headers.
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $response = app(ExceptionHandler::class)->render($request, $exception);
        }

        return $response->header('Cache-Control', 'no-store')->prepare($request);
    }
}
