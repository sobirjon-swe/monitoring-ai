<?php

namespace App\Domain\Browser;

use App\Domain\Identity\LocalCredentialVerifier;
use App\Domain\Identity\VerifiedLocalCredential;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Throwable;

final class BrowserSessionService
{
    public const BINDING = 'browser_credential';

    public function __construct(private readonly LocalCredentialVerifier $verifier) {}

    public function start(Session $session, string $token): ?VerifiedLocalCredential
    {
        $session->forget(self::BINDING);
        $verified = $this->verifier->verifyToken($token);
        if ($verified === null) {
            return null;
        }
        $session->regenerate(true);
        $session->put(self::BINDING, ['version' => 1, 'tenant_id' => $verified->actor->tenantId,
            'token_id' => $verified->tokenId, 'subject_id' => $verified->subjectId, 'user_id' => $verified->actor->id,
            'fingerprint' => $verified->fingerprint, 'last_seen_at' => now()->format('Y-m-d\TH:i:s.uP')]);

        return $verified;
    }

    public function resolve(Session $session): ?VerifiedLocalCredential
    {
        $binding = $session->get(self::BINDING);
        try {
            $lastSeen = is_array($binding) && is_string($binding['last_seen_at'] ?? null) ? CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $binding['last_seen_at']) : null;
            $verified = $lastSeen !== null && $lastSeen->lessThanOrEqualTo(now()) && $lastSeen->addMinutes(120)->greaterThan(now())
                ? $this->verifier->verifyBinding($binding) : null;
        } catch (Throwable) {
            $verified = null;
        }
        if ($verified === null) {
            $session->forget(self::BINDING);

            return null;
        }
        $binding['last_seen_at'] = now()->format('Y-m-d\TH:i:s.uP');
        $session->put(self::BINDING, $binding);

        return $verified;
    }

    public function end(Session $session): void
    {
        $session->invalidate();
        $session->regenerateToken();
    }
}
