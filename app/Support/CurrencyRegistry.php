<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Request-scoped cache of the currencies table. Money formatting runs for
 * every row on screen, so currencies are loaded once per request.
 */
final class CurrencyRegistry
{
    /** @var Collection<string, Currency>|null */
    private ?Collection $currencies = null;

    /** @return Collection<string, Currency> keyed by code, base first */
    public function all(): Collection
    {
        return $this->currencies ??= $this->load();
    }

    public function get(string $code): Currency
    {
        return $this->all()->get(strtoupper($code))
            ?? throw new InvalidArgumentException("Unknown currency: {$code}");
    }

    public function has(string $code): bool
    {
        return $this->all()->has(strtoupper($code));
    }

    public function base(): Currency
    {
        return $this->all()->firstWhere('is_base', true)
            ?? $this->all()->get(strtoupper((string) config('money.base_currency', 'TND')))
            ?? $this->all()->first();
    }

    public function flush(): void
    {
        $this->currencies = null;
    }

    /** @return Collection<string, Currency> */
    private function load(): Collection
    {
        try {
            $rows = Currency::query()->orderByDesc('is_base')->orderBy('code')->get()->keyBy('code');
        } catch (QueryException) {
            $rows = collect(); // table not migrated yet
        }

        if ($rows->isNotEmpty()) {
            return $rows;
        }

        // Never crash on a fresh install: fall back to the configured base currency.
        $code = strtoupper((string) config('money.base_currency', 'TND'));

        return collect([$code => new Currency([
            'code' => $code, 'name' => $code, 'symbol' => $code, 'symbol_first' => false,
            'decimals' => $code === 'TND' ? 3 : 2, 'exchange_rate' => 1.0, 'is_base' => true,
        ])]);
    }
}
