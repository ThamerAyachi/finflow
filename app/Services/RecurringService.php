<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RecurringRule;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecurringService
{
    public function __construct(private readonly TransactionService $transactions) {}

    /** Post the rule's transaction and move next_due_date one period forward. */
    public function markPaid(RecurringRule $rule, ?CarbonImmutable $paidOn = null): Transaction
    {
        return DB::transaction(function () use ($rule, $paidOn): Transaction {
            /** @var RecurringRule $rule */
            $rule = RecurringRule::query()->lockForUpdate()->findOrFail($rule->id);

            $transaction = $this->transactions->record([
                'wallet_id' => $rule->wallet_id,
                'type' => $rule->type,
                'amount' => $rule->amount,
                'category_id' => $rule->category_id,
                'description' => $rule->name,
                'occurred_on' => $paidOn ?? CarbonImmutable::today(),
                'recurring_rule_id' => $rule->id,
            ]);

            $rule->next_due_date = $rule->frequency->advance($rule->next_due_date);
            $rule->save();

            return $transaction;
        });
    }

    /** Skip one occurrence without posting anything. */
    public function skip(RecurringRule $rule): void
    {
        $rule->update(['next_due_date' => $rule->frequency->advance($rule->next_due_date)]);
    }

    /** Post every due occurrence of auto_post rules. Returns the number posted. */
    public function postDue(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $posted = 0;

        $rules = RecurringRule::query()->active()->where('auto_post', true)
            ->where('next_due_date', '<=', $today->toDateString().' 23:59:59')->get();

        foreach ($rules as $rule) {
            while ($rule->next_due_date->lte($today) && $posted < 1000) {
                $this->markPaid($rule, $rule->next_due_date);
                $rule->refresh();
                $posted++;
            }
        }

        return $posted;
    }
}
