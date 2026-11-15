<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SinkingFund extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_amount' => MoneyCast::class,
            'saved_amount' => MoneyCast::class,
            'target_date' => 'immutable_date',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(SinkingFundContribution::class);
    }

    public function remaining(): int
    {
        return max(0, $this->target_amount - $this->saved_amount);
    }

    public function progress(): float
    {
        return $this->target_amount > 0 ? min(100.0, $this->saved_amount / $this->target_amount * 100) : 0.0;
    }

    /** Months left to fund the goal, counting the current month. */
    public function monthsRemaining(?CarbonImmutable $today = null): int
    {
        $start = ($today ?? CarbonImmutable::today())->startOfMonth();
        $end = $this->target_date->startOfMonth();

        return max(1, (int) $start->diffInMonths($end, false) + 1);
    }

    public function contributedThisMonth(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();

        return (int) $this->contributions
            ->filter(fn (SinkingFundContribution $c) => $c->contributed_on->isSameMonth($today))
            ->sum('amount');
    }

    /** What must be set aside every month to hit the target on time. */
    public function monthlyAllocation(?CarbonImmutable $today = null): int
    {
        // Measured from the start-of-month position so contributing doesn't move the goalposts.
        $outstanding = max(0, $this->target_amount - ($this->saved_amount - $this->contributedThisMonth($today)));

        return (int) ceil($outstanding / $this->monthsRemaining($today));
    }

    /** Portion of this month's allocation not yet contributed. */
    public function dueThisMonth(?CarbonImmutable $today = null): int
    {
        return max(0, min($this->remaining(), $this->monthlyAllocation($today) - $this->contributedThisMonth($today)));
    }
}
