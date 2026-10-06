<?php

namespace Tests\Support;

use App\Domain\Access\TrustedActor;

abstract class LifecycleTestCase extends AccessTestCase
{
    protected function lifecycle(): string
    {
        $this->fixture();
        $p = $this->project();
        foreach (['requirements.read', 'requirements.manage', 'requirements.approve', 'policy.approve'] as $purpose) {
            $this->grant($purpose, $p);
        }

        return $p;
    }

    protected function actor(): TrustedActor
    {
        return new TrustedActor($this->user, $this->tenant);
    }

    protected function requirement(): array
    {
        return ['title' => 'Synthetic requirement', 'owner_id' => $this->employee, 'stakeholder' => 'Synthetic stakeholder', 'business_outcome' => 'Synthetic outcome', 'nfr_constraints' => ['Synthetic NFR'], 'dependencies' => [], 'criteria' => [['key' => 'A', 'text' => 'Reject', 'required_test_keys' => ['test-a']], ['key' => 'B', 'text' => 'Verify', 'required_test_keys' => ['test-b']]]];
    }
}
