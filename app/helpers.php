<?php

declare(strict_types=1);

use App\Models\Currency;
use App\Support\Money;

if (! function_exists('money')) {
    /** Format minor units for display; defaults to the base currency. */
    function money(int $minor, Currency|string|null $currency = null, bool $signed = false): string
    {
        return Money::format($minor, $currency ?? Currency::base(), $signed);
    }
}

if (! function_exists('base_currency')) {
    /** The currency every report, budget and debt is expressed in. */
    function base_currency(): Currency
    {
        return Currency::base();
    }
}
