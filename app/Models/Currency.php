<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CurrencyRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A currency the user holds money in. Amounts are stored in minor units,
 * so `decimals` decides what 1 means (1 TND = 1000, 1 USD = 100).
 *
 * @property string $code
 * @property string $name
 * @property string $symbol
 * @property bool $symbol_first
 * @property int $decimals
 * @property float $exchange_rate
 * @property bool $is_base
 */
class Currency extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'symbol_first' => 'boolean',
            'decimals' => 'integer',
            'exchange_rate' => 'float',
            'is_base' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Every lookup goes through the registry; keep it in sync with edits.
        static::saved(fn () => app(CurrencyRegistry::class)->flush());
        static::deleted(fn () => app(CurrencyRegistry::class)->flush());
    }

    public static function base(): self
    {
        return app(CurrencyRegistry::class)->base();
    }

    public static function lookup(string $code): self
    {
        return app(CurrencyRegistry::class)->get($code);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class, 'currency', 'code');
    }

    public function decimals(): int
    {
        return (int) $this->decimals;
    }

    public function minorPerMajor(): int
    {
        return 10 ** $this->decimals();
    }

    public function symbol(): string
    {
        return $this->symbol;
    }
}
