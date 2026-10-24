<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateLocalToken;
use App\Http\Middleware\EnsureLocalBrowserRuntime;
use App\Http\Middleware\EnsureLocalManagementDatabase;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::middleware('browser')->prefix('browser/v1')->group(__DIR__.'/../routes/browser.php');
            Route::get('/up', fn () => response()->json(['status' => 'ok']));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(EnsureLocalBrowserRuntime::class);
        $middleware->group('browser', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            PreventRequestForgery::class,
            SubstituteBindings::class,
        ]);
        $middleware->append(EnsureLocalManagementDatabase::class);
        $middleware->appendToGroup('api', AuthenticateLocalToken::class);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->report(function (Throwable $exception): bool {
            // Exception messages and traces can include connection strings or source contents.
            Log::error('Request failed', [
                'request_id' => app()->bound('request') ? request()->attributes->get('request_id') : null,
                'exception_type' => get_class($exception),
            ]);

            return false;
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            $status = match (true) {
                $exception instanceof AuthenticationException => 401,
                $exception instanceof ValidationException => 422,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $code = match ($status) {
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                409 => 'conflict',
                422 => 'invalid_request',
                429 => 'too_many_requests',
                default => $status >= 500 ? 'internal_error' : 'request_failed',
            };
            $requestId = $request->attributes->get('request_id') ?? (string) Str::uuid();

            $body = [
                'error' => ['code' => $code, 'message' => $status >= 500 ? 'Request could not be completed.' : 'Request rejected.'],
                'request_id' => $requestId,
            ];
            if ($exception instanceof ValidationException) {
                $body['errors'] = array_fill_keys(array_keys($exception->errors()), ['Invalid value.']);
            }
            $headers = [];
            if ($exception instanceof HttpExceptionInterface) {
                foreach ($exception->getHeaders() as $name => $value) {
                    if (in_array(strtolower($name), ['allow', 'retry-after'], true)) {
                        $headers[$name] = $value;
                    }
                }
            }

            return response()->json($body, $status, $headers)->header('X-Request-ID', $requestId);
        });
    })->create();
