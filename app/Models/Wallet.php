<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CurrencyCast;
use App\Casts\MoneyCast;
use App\Enums\WalletType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property Currency $currency
 * @property-read float $exchange_rate
 */
class Wallet extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => WalletType::class,
            'currency' => CurrencyCast::class,
            'initial_balance' => MoneyCast::class,
            'balance' => MoneyCast::class,
            'is_included_in_net_worth' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    /** The rate lives on the currency, so one edit re-values every wallet in it. */
    protected function exchangeRate(): Attribute
    {
        return Attribute::get(fn (): float => $this->currency->exchange_rate);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @param list<int> $workspaceIds */
    public function scopeForWorkspaces(Builder $query, array $workspaceIds): void
    {
        $query->whereIn('workspace_id', $workspaceIds);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_archived', false);
    }

    public function baseBalance(): int
    {
        return Money::toBase($this->balance, $this->currency);
    }

    public function formattedBalance(): string
    {
        return Money::format($this->balance, $this->currency);
    }
}
