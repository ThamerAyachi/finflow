<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\Frequency;
use App\Enums\TransactionType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => MoneyCast::class,
            'frequency' => Frequency::class,
            'next_due_date' => 'immutable_date',
            'auto_post' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @param list<int> $workspaceIds */
    public function scopeForWorkspaces(Builder $query, array $workspaceIds): void
    {
        $query->whereIn('workspace_id', $workspaceIds);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function baseAmount(): int
    {
        return Money::toBase($this->amount, $this->wallet->currency, $this->wallet->exchange_rate);
    }

    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        return $this->next_due_date->lt($today ?? CarbonImmutable::today());
    }

    /**
     * Every unpaid due date up to and including $until (overdue ones included).
     *
     * @return list<CarbonImmutable>
     */
    public function occurrencesUntil(CarbonImmutable $until): array
    {
        $dates = [];
        $date = $this->next_due_date;

        while ($date->lte($until) && count($dates) < 400) {
            $dates[] = $date;
            $date = $this->frequency->advance($date);
        }

        return $dates;
    }
}
