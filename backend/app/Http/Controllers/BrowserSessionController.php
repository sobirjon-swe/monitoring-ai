<?php

namespace App\Http\Controllers;

use App\Domain\Browser\BrowserLoginPayload;
use App\Domain\Browser\BrowserSessionService;
use App\Domain\Identity\VerifiedLocalCredential;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class BrowserSessionController
{
    public function __construct(private readonly BrowserSessionService $sessions) {}

    public function show(Request $request): mixed
    {
        $this->noAuthorization($request);
        $bound = $request->session()->has(BrowserSessionService::BINDING);
        if (! $bound && ! $this->sessions->initialize($request->session())) {
            throw new AuthenticationException;
        }
        $verified = $this->sessions->resolve($request->session());
        if ($bound && $verified === null) {
            throw new AuthenticationException;
        }

        return response()->json(['data' => $this->data($request, $verified)]);
    }

    public function login(Request $request, BrowserLoginPayload $payload): mixed
    {
        $replacement = $this->sessions->beginReplacement($request->session());
        if ($replacement === false) {
            throw new AuthenticationException;
        }
        $request->session()->forget(BrowserSessionService::BINDING);
        $this->noAuthorization($request);
        $key = 'browser.login.'.hash('sha256', (string) config('browser.client'));
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, '', ['Retry-After' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);
        if ($request->query() || ! $request->isJson()) {
            throw ValidationException::withMessages(['payload' => 'Invalid request.']);
        }
        $verified = $this->sessions->start($request->session(), $payload->token($request->getContent()), $replacement);
        if ($verified === null) {
            throw new AuthenticationException;
        }

        return response()->json(['data' => $this->data($request, $verified)]);
    }

    public function logout(Request $request): mixed
    {
        $this->noAuthorization($request);
        $this->sessions->end($request->session());

        return response()->noContent();
    }

    private function noAuthorization(Request $request): void
    {
        if ($request->headers->has('Authorization')) {
            throw new AuthenticationException;
        }
    }

    private function data(Request $request, ?VerifiedLocalCredential $verified): array
    {
        if ($verified === null) {
            return ['authenticated' => false, 'csrf_token' => $request->session()->token()];
        }

        return ['authenticated' => true, 'csrf_token' => $request->session()->token(),
            'user' => ['id' => $verified->actor->id, 'tenant_id' => $verified->actor->tenantId,
                'employee_id' => $verified->employeeId, 'display_name' => $verified->displayName],
            'expires_at' => $verified->expiresAt->min(now()->addMinutes(120))->format('Y-m-d\TH:i:s.uP')];
    }
}
