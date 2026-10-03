<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class PreciseAfter implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function __construct(private readonly string $other) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            if (isset($this->data[$this->other]) && is_string($value)
                && CarbonImmutable::parse($value)->greaterThan(CarbonImmutable::parse($this->data[$this->other]))) {
                return;
            }
        } catch (\Throwable) {
            // Invalid dates fail without exposing parser diagnostics or supplied values.
        }
        $fail('Invalid period.');
    }
}
