<?php

namespace App\Support;

use App\Domain\Access\TrustedActor;
use Closure;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Opt-in retention of exact native proofs across a composed read's multiple calls. */
final class ReadDeliveryFence
{
    private ?string $block = null;

    private array $checks = [];

    private array $budgets = [];

    private array $overflow = [];

    public function __construct(private readonly TrustedActor $actor, private readonly string $project) {}

    public function within(string $block, Closure $read): mixed
    {
        if ($this->block !== null) {
            throw new LogicException('Nested delivery blocks are prohibited.');
        }
        $this->block = $block;
        try {
            return $read();
        } finally {
            $this->block = null;
        }
    }

    public function capture(TrustedActor $actor, string $project, array $proofs, Closure $assert): void
    {
        if ($this->block === null || $actor->id !== $this->actor->id || $actor->tenantId !== $this->actor->tenantId || $project !== $this->project) {
            throw new LogicException('Delivery proof scope mismatch.');
        }
        $block = $this->block;
        if (isset($this->overflow[$block])) {
            return;
        }
        $budget = $this->budgets[$block] ?? ['proofs' => 0, 'bytes' => 0, 'calls' => 0];
        $budget['proofs'] += count($proofs);
        $budget['bytes'] += strlen(json_encode($proofs, JSON_THROW_ON_ERROR));
        $budget['calls']++;
        if ($budget['proofs'] > 10000 || $budget['bytes'] > 8 * 1024 * 1024 || $budget['calls'] > 64) {
            $this->overflow[$block] = true;
            unset($this->checks[$block]);

            return;
        }
        $this->budgets[$block] = $budget;
        $this->checks[$block][] = $assert;
    }

    public function reason(string $block, string $instant): ?string
    {
        if (isset($this->overflow[$block])) {
            return 'coverage_limit';
        }
        try {
            foreach ($this->checks[$block] ?? [] as $check) {
                $check($instant);
            }
        } catch (AccessDeniedHttpException) {
            return 'original_authority_expired';
        }

        return null;
    }
}
