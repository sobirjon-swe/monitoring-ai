<?php

namespace App\Domain\Browser;

use Illuminate\Validation\ValidationException;
use JsonException;

final class BrowserLoginPayload
{
    public function token(string $raw): string
    {
        if (strlen($raw) > 1024) {
            abort(413);
        }
        // A single-member grammar detects duplicate keys, including escaped keys.
        if (! preg_match('/\A[\x20\t\r\n]*\{[\x20\t\r\n]*("(?:[^"\\\\\x00-\x1f]|\\\\.)*")[\x20\t\r\n]*:[\x20\t\r\n]*("(?:[^"\\\\\x00-\x1f]|\\\\.)*")[\x20\t\r\n]*\}[\x20\t\r\n]*\z/sD', $raw, $parts)) {
            $this->invalid();
        }
        try {
            $key = json_decode($parts[1], true, 4, JSON_THROW_ON_ERROR);
            $token = json_decode($parts[2], true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid();
        }
        if ($key !== 'token' || ! is_string($token) || $token === '' || preg_match('/[\x00-\x1f\x7f]/', $token)) {
            $this->invalid();
        }
        if (strlen($token) > 512) {
            abort(413);
        }

        return $token;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['payload' => 'Invalid request.']);
    }
}
