<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\TransactionType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    /** Real income: excludes reimbursements, which offset spending instead. */
    public const INCOME_SQL = "CASE WHEN type = 'income' AND parent_transaction_id IS NULL THEN base_amount ELSE 0 END";

    /** Net spending: expenses minus the reimbursements linked to them. */
    public const EXPENSE_SQL = "CASE WHEN type = 'expense' THEN base_amount WHEN type = 'income' AND parent_transaction_id IS NOT NULL THEN -base_amount ELSE 0 END";

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => MoneyCast::class,
            'destination_amount' => MoneyCast::class,
            'base_amount' => MoneyCast::class,
            'affects_reports' => 'boolean',
            'occurred_on' => 'date',
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

    public function destinationWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'destination_wallet_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_transaction_id');
    }

    /** Reimbursements / refunds received against this expense. */
    public function reimbursements(): HasMany
    {
        return $this->hasMany(self::class, 'parent_transaction_id')->orderBy('occurred_on');
    }

    public function debtPayment(): HasOne
    {
        return $this->hasOne(DebtPayment::class);
    }

    /** @param list<int> $workspaceIds */
    public function scopeForWorkspaces(Builder $query, array $workspaceIds): void
    {
        $query->whereIn('transactions.workspace_id', $workspaceIds);
    }

    /** Income and spending only — no transfers, no debt movements. */
    public function scopeReportable(Builder $query): void
    {
        $query->where('affects_reports', true)->where('type', '!=', TransactionType::Transfer->value);
    }

    public function scopeBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereBetween('occurred_on', [$from->toDateString().' 00:00:00', $to->toDateString().' 23:59:59']);
    }

    public function isReimbursement(): bool
    {
        return $this->parent_transaction_id !== null;
    }

    /** +1 money in, -1 money out, 0 neutral (from the source wallet's perspective). */
    public function direction(): int
    {
        return match ($this->type) {
            TransactionType::Income => 1,
            TransactionType::Expense => -1,
            TransactionType::Transfer => 0,
        };
    }

    /** Amount actually borne after reimbursements (wallet currency). */
    public function netAmount(): int
    {
        return $this->amount - (int) $this->reimbursements->sum('amount');
    }
}
