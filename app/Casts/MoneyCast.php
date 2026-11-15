<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Guarantees money attributes are integers in minor units on the way in and out.
 * Rejects floats so a stray 12.5 can never be silently truncated into storage.
 *
 * @implements CastsAttributes<int, int>
 */
final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : (int) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
            return (int) $value;
        }

        throw new InvalidArgumentException("{$key} must be an integer amount in minor units; use Money::parse() for user input.");
    }
}
