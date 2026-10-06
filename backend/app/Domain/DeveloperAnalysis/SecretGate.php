<?php

namespace App\Domain\DeveloperAnalysis;

use InvalidArgumentException;

final class SecretGate
{
    public const VERSION = 'secret-gate-v1';

    private const CREDENTIAL_ASSIGNMENT = '["\x27]?(?:password|passwd|secret|api[_-]?key|access[_-]?token|authorization)["\x27]?\s*(?>=>|[=:])\s*';

    public function inspect(string $diff, string $policy): array
    {
        if (! in_array($policy, ['block-v1', 'redact-lines-v1'], true) || strlen($diff) > 524288 || ! mb_check_encoding($diff, 'UTF-8') || str_contains($diff, "\0")) {
            return ['accepted' => false, 'reason_code' => 'invalid_diff', 'raw_hash' => hash('sha256', $diff)];
        }
        $diff = str_replace("\r\n", "\n", $diff);
        $lines = explode("\n", $diff);
        $mapping = [];
        foreach ($lines as $i => $line) {
            if (! $this->secret($line)) {
                continue;
            }
            if ($policy !== 'redact-lines-v1' || ! in_array($line[0] ?? '', ['+', '-', ' '], true) || str_starts_with($line, '+++') || str_starts_with($line, '---')
                || str_contains($line, 'PRIVATE KEY') || str_ends_with($line, '\\') || preg_match('/'.self::CREDENTIAL_ASSIGNMENT.'(?:[|>]|(["\x27])[^"\x27]*$)/i', $line)) {
                return ['accepted' => false, 'reason_code' => 'secret_blocked', 'raw_hash' => hash('sha256', $diff)];
            }
            $lines[$i] = $line[0].'[REDACTED]';
            $mapping[] = ['diff_line' => $i + 1, 'prefix' => $line[0]];
        }
        $safe = implode("\n", $lines);

        return ['accepted' => true, 'text' => $safe, 'hash' => hash('sha256', $safe), 'mapping' => $mapping, 'version' => self::VERSION, 'policy' => $policy];
    }

    public function safeText(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0") || $this->secret($value)) {
            throw new InvalidArgumentException('Unsafe free text.');
        }

        return $value;
    }

    private function secret(string $text): bool
    {
        return (bool) preg_match('/'.self::CREDENTIAL_ASSIGNMENT.'|(?:AKIA|ASIA)[A-Z0-9]{16}|gh[pousr]_[A-Za-z0-9]{20,}|-----BEGIN [A-Z ]*PRIVATE KEY-----|(?:Bearer|Basic)\s+[A-Za-z0-9._+=\/-]{4,}|[a-z][a-z0-9+.-]*:\/\/[^\s:\/]+:[^\s@]+@/i', $text);
    }
}
