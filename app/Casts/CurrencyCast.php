<?php

declare(strict_types=1);

namespace App\Casts;

use App\Models\Currency;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a currency code, hands back the cached Currency model.
 *
 * @implements CastsAttributes<Currency, Currency|string>
 */
final class CurrencyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Currency
    {
        return $value === null ? null : Currency::lookup((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof Currency ? $value->code : strtoupper(trim((string) $value));
    }
}
