<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Currency;
use InvalidArgumentException;

/**
 * Money helper. Amounts are always integers in minor units
 * (1.000 TND = 1000, 1.00 USD = 100); floats never touch stored values.
 */
final class Money
{
    public static function currency(Currency|string $currency): Currency
    {
        return $currency instanceof Currency ? $currency : Currency::lookup($currency);
    }

    /**
     * Parse user input ("12.5", "1,234.50", "12,5", "1 200") into minor units.
     * Uses string arithmetic only; rounds half-up beyond the currency precision.
     */
    public static function parse(string|int|float|null $input, Currency|string $currency): int
    {
        $currency = self::currency($currency);
        $value = trim((string) $input);
        $value = str_replace([' ', "\u{00A0}", '_'], '', $value);

        if ($value === '') {
            throw new InvalidArgumentException('Amount is required.');
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace(',', '', $value);       // 1,234.50
        } elseif (substr_count($value, ',') === 1) {
            $value = str_replace(',', '.', $value);      // 12,5
        } else {
            $value = str_replace(',', '', $value);
        }

        if (! preg_match('/^(-)?(\d*)(?:\.(\d*))?$/', $value, $m) || (($m[2] ?? '') === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("Invalid amount: {$input}");
        }

        $decimals = $currency->decimals();
        $whole = $m[2] === '' ? '0' : $m[2];
        $fraction = str_pad($m[3] ?? '', $decimals + 1, '0');

        $minor = (int) $whole * $currency->minorPerMajor() + (int) substr($fraction, 0, $decimals);
        if ((int) $fraction[$decimals] >= 5) {
            $minor++;
        }

        return $m[1] === '-' ? -$minor : $minor;
    }

    /** "1,234.500" — no currency code. */
    public static function decimal(int $minor, Currency|string $currency, bool $thousands = true): string
    {
        $currency = self::currency($currency);
        $per = $currency->minorPerMajor();
        $abs = abs($minor);
        $whole = intdiv($abs, $per);
        $sign = $minor < 0 ? '-' : '';
        $integer = $thousands ? number_format($whole) : (string) $whole;

        if ($currency->decimals() === 0) {
            return $sign.$integer;
        }

        return $sign.$integer.'.'.str_pad((string) ($abs % $per), $currency->decimals(), '0', STR_PAD_LEFT);
    }

    /** "1,234.500 TND" / "-$45.00" — symbol placement comes from the currency. */
    public static function format(int $minor, Currency|string $currency, bool $signed = false): string
    {
        $currency = self::currency($currency);
        $sign = $minor < 0 ? '-' : ($signed && $minor > 0 ? '+' : '');
        $number = self::decimal(abs($minor), $currency);

        return $currency->symbol_first
            ? "{$sign}{$currency->symbol}{$number}"
            : "{$sign}{$number} {$currency->symbol}";
    }

    /** Compact form for KPI tiles and chart axes: "12.4k". */
    public static function compact(int $minor, Currency|string|null $currency = null): string
    {
        $major = $minor / self::currency($currency ?? Currency::base())->minorPerMajor();
        $abs = abs($major);

        return match (true) {
            $abs >= 1_000_000 => round($major / 1_000_000, 1).'M',
            $abs >= 10_000 => round($major / 1_000, 1).'k',
            default => number_format($major, 0),
        };
    }

    /**
     * Convert minor units of $from into base-currency minor units.
     * $rate (1 major unit of $from in base) defaults to the currency's own rate.
     */
    public static function toBase(int $minor, Currency|string $from, ?float $rate = null): int
    {
        $from = self::currency($from);
        $base = Currency::base();

        if ($from->code === $base->code) {
            return $minor;
        }

        return (int) round($minor * ($rate ?? $from->exchange_rate) * (10 ** ($base->decimals() - $from->decimals())));
    }

    /** Convert base-currency minor units into minor units of $to. */
    public static function fromBase(int $baseMinor, Currency|string $to, ?float $rate = null): int
    {
        $to = self::currency($to);
        $base = Currency::base();
        $rate ??= $to->exchange_rate;

        if ($to->code === $base->code || $rate <= 0.0) {
            return $baseMinor;
        }

        return (int) round($baseMinor / $rate * (10 ** ($to->decimals() - $base->decimals())));
    }

    /** Float major units, for chart series only. */
    public static function toFloat(int $minor, Currency|string|null $currency = null): float
    {
        return $minor / self::currency($currency ?? Currency::base())->minorPerMajor();
    }
}
