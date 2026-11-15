<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Currency;
use App\Support\Money;
use InvalidArgumentException;

trait ParsesMoney
{
    /** Parse a public string property into minor units, adding a validation error on failure. */
    protected function parseAmount(string $field, Currency|string|null $currency = null, bool $allowNegative = false): ?int
    {
        // Livewire keeps errors between requests; a retry must start clean.
        $this->resetErrorBag($field);

        try {
            $value = Money::parse((string) $this->{$field}, $currency ?? Currency::base());
        } catch (InvalidArgumentException) {
            $this->addError($field, 'Enter a valid amount.');

            return null;
        }

        if (! $allowNegative && $value <= 0) {
            $this->addError($field, 'Amount must be greater than zero.');

            return null;
        }

        return $value;
    }

    protected function toast(string $message, string $tone = 'success'): void
    {
        $this->dispatch('toast', message: $message, tone: $tone);
    }
}
