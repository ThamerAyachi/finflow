<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SinkingFundContribution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'contributed_on' => 'immutable_date'];
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(SinkingFund::class, 'sinking_fund_id');
    }
}
