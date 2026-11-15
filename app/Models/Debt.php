<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\DebtDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Debt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'direction' => DebtDirection::class,
            'principal' => MoneyCast::class,
            'paid_amount' => MoneyCast::class,
            'due_date' => 'immutable_date',
            'settled_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DebtPayment::class)->orderByDesc('paid_on')->orderByDesc('id');
    }

    /** @param list<int> $workspaceIds */
    public function scopeForWorkspaces(Builder $query, array $workspaceIds): void
    {
        $query->whereIn('workspace_id', $workspaceIds);
    }

    public function remaining(): int
    {
        return max(0, $this->principal - $this->paid_amount);
    }

    public function progress(): float
    {
        return $this->principal > 0 ? min(100.0, $this->paid_amount / $this->principal * 100) : 0.0;
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isSettled() && $this->due_date !== null && $this->due_date->isPast() && ! $this->due_date->isToday();
    }
}
