<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Budget;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\SinkingFund;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Support\Money;
use App\Support\WorkspaceScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Read-only analytics. Every figure is in base-currency minor units and honours the workspace scope. */
final class ReportService
{
    public function __construct(private readonly WorkspaceScope $scope) {}

    /** @return Collection<int, Wallet> */
    public function wallets(): Collection
    {
        return Wallet::query()->forWorkspaces($this->scope->ids())->active()->orderBy('id')->get();
    }

    public function netWorth(): int
    {
        return (int) $this->wallets()->where('is_included_in_net_worth', true)->sum(fn (Wallet $w) => $w->baseBalance());
    }

    public function liquidCash(): int
    {
        return (int) $this->wallets()->filter(fn (Wallet $w) => $w->type->isLiquid())->sum(fn (Wallet $w) => $w->baseBalance());
    }

    /** @return array{income:int, expense:int, net:int, savings_rate:?float} */
    public function flow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = Transaction::query()->forWorkspaces($this->scope->ids())->reportable()->between($from, $to)
            ->selectRaw('COALESCE(SUM('.Transaction::INCOME_SQL.'), 0) AS income, COALESCE(SUM('.Transaction::EXPENSE_SQL.'), 0) AS expense')
            ->first();

        $income = (int) $row->income;
        $expense = (int) $row->expense;

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'savings_rate' => $income > 0 ? round(($income - $expense) / $income * 100, 1) : null,
        ];
    }

    /**
     * Average daily net spending over the trailing 30 days, and how long liquid cash lasts at that pace.
     *
     * @return array{daily:int, monthly:int, runway_days:?int, change:?float}
     */
    public function burnRate(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $current = $this->flow($today->subDays(29), $today)['expense'];
        $previous = $this->flow($today->subDays(59), $today->subDays(30))['expense'];
        $daily = (int) round($current / 30);

        return [
            'daily' => $daily,
            'monthly' => $current,
            'runway_days' => $daily > 0 ? max(0, intdiv($this->liquidCash(), $daily)) : null,
            'change' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
        ];
    }

    /**
     * Daily income / net-expense series in major units for charting.
     *
     * @return array{labels:list<string>, income:list<float>, expense:list<float>}
     */
    public function dailySeries(int $days = 30, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $from = $today->subDays($days - 1);

        $rows = Transaction::query()->forWorkspaces($this->scope->ids())->reportable()->between($from, $today)
            ->selectRaw('occurred_on, SUM('.Transaction::INCOME_SQL.') AS income, SUM('.Transaction::EXPENSE_SQL.') AS expense')
            ->groupBy('occurred_on')
            ->toBase()->get()
            ->keyBy(fn ($row) => substr((string) $row->occurred_on, 0, 10));

        $series = ['labels' => [], 'income' => [], 'expense' => []];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->addDays($i);
            $row = $rows->get($date->toDateString());
            $series['labels'][] = $date->format('M j');
            $series['income'][] = Money::toFloat((int) ($row->income ?? 0));
            $series['expense'][] = Money::toFloat(max(0, (int) ($row->expense ?? 0)));
        }

        return $series;
    }

    /**
     * Net spending per [workspace_id][category_id] (0 = uncategorised).
     *
     * @return array<int, array<int, int>>
     */
    public function spendingMatrix(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $matrix = [];

        Transaction::query()->forWorkspaces($this->scope->ids())->reportable()->between($from, $to)
            ->selectRaw('workspace_id, category_id, SUM('.Transaction::EXPENSE_SQL.') AS spent')
            ->groupBy('workspace_id', 'category_id')
            ->toBase()->get()
            ->each(function ($row) use (&$matrix): void {
                $matrix[(int) $row->workspace_id][(int) $row->category_id] = (int) $row->spent;
            });

        return $matrix;
    }

    /**
     * Net spending rolled up to top-level categories, largest first; the tail folds into "Other".
     *
     * @return Collection<int, array{id:?int, name:string, color:string, icon:string, amount:int, share:float}>
     */
    public function categoryBreakdown(CarbonImmutable $from, CarbonImmutable $to, int $limit = 6): Collection
    {
        $categories = Category::query()->get()->keyBy('id');
        $totals = [];

        foreach ($this->spendingMatrix($from, $to) as $byCategory) {
            foreach ($byCategory as $categoryId => $spent) {
                $category = $categories->get($categoryId);
                $rootId = $category?->parent_id ?? $category?->id ?? 0;
                $totals[$rootId] = ($totals[$rootId] ?? 0) + $spent;
            }
        }

        $rows = collect($totals)->filter(fn (int $amount) => $amount > 0)->sortDesc()
            ->map(fn (int $amount, int $id) => [
                'id' => $id ?: null,
                'name' => $categories->get($id)?->name ?? 'Uncategorised',
                'color' => $categories->get($id)?->color_hex ?? '#71717A',
                'icon' => $categories->get($id)?->icon ?? 'circle',
                'amount' => $amount,
            ])->values();

        if ($rows->count() > $limit) {
            $rows = $rows->take($limit - 1)->push([
                'id' => null, 'name' => 'Other', 'color' => '#52525B', 'icon' => 'ellipsis',
                'amount' => (int) $rows->skip($limit - 1)->sum('amount'),
            ]);
        }

        $total = max(1, (int) $rows->sum('amount'));

        return $rows->map(fn (array $row) => $row + ['share' => round($row['amount'] / $total * 100, 1)]);
    }

    /**
     * Budget envelopes with spend, effective cap (incl. rollover) and pacing for the current month.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function budgets(?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::today();
        $thisMonth = $this->spendingMatrix($today->startOfMonth(), $today->endOfMonth());
        $lastMonth = $this->spendingMatrix($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth());

        $daysInMonth = $today->daysInMonth;
        $day = $today->day;

        return Budget::query()->whereIn('workspace_id', $this->scope->ids())
            ->with(['category.children', 'workspace'])->get()
            ->map(function (Budget $budget) use ($thisMonth, $lastMonth, $daysInMonth, $day): array {
                $family = [$budget->category_id, ...$budget->category->children->pluck('id')->all()];
                $sum = fn (array $matrix): int => (int) collect($family)->sum(fn (int $id) => $matrix[$budget->workspace_id][$id] ?? 0);

                $spent = max(0, $sum($thisMonth));
                $carry = $budget->rollover ? max(0, $budget->amount - max(0, $sum($lastMonth))) : 0;
                $cap = $budget->amount + $carry;
                $percent = $cap > 0 ? round($spent / $cap * 100, 1) : 0.0;
                $perDay = $cap / $daysInMonth;
                $daysAhead = $perDay > 0 ? (int) round(($perDay * $day - $spent) / $perDay) : 0;

                return [
                    'budget' => $budget,
                    'family' => $family,
                    'spent' => $spent,
                    'carry' => $carry,
                    'cap' => $cap,
                    'remaining' => $cap - $spent,
                    'percent' => $percent,
                    'status' => $percent > 95 ? 'expense' : ($percent >= 75 ? 'warn' : 'income'),
                    'pace_marker' => round($day / $daysInMonth * 100, 1),
                    'days_ahead' => $daysAhead,
                    'pace_label' => match (true) {
                        $spent > $cap => 'Over budget by '.money($spent - $cap),
                        $daysAhead > 0 => "You are {$daysAhead} ".($daysAhead === 1 ? 'day' : 'days').' ahead of pace',
                        $daysAhead < 0 => 'You are '.abs($daysAhead).' '.(abs($daysAhead) === 1 ? 'day' : 'days').' behind pace',
                        default => 'Right on pace',
                    },
                ];
            })
            ->sortByDesc('percent')->values();
    }

    /** @return Collection<int, RecurringRule> Active rules due within $days (overdue included), soonest first. */
    public function upcomingBills(int $days = 14, ?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::today();

        return RecurringRule::query()->forWorkspaces($this->scope->ids())->active()
            ->where('next_due_date', '<=', $today->addDays($days)->toDateString().' 23:59:59')
            ->with(['wallet', 'category'])->orderBy('next_due_date')->get();
    }

    /**
     * Safe to Spend Today =
     *   (available liquid cash + expected remaining income − unpaid fixed bills
     *    − remaining budget allocations − sinking fund contributions) / days left in month.
     *
     * Liquid cash excludes savings pots, which is where money already set
     * aside for sinking funds is assumed to live.
     *
     * @return array<string, int>
     */
    public function safeToSpend(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $monthEnd = $today->endOfMonth();
        $ids = $this->scope->ids();

        $funds = SinkingFund::query()->whereIn('workspace_id', $ids)->with('contributions')->get();
        $liquid = $this->liquidCash();

        $expectedIncome = 0;
        $unpaidBills = 0;
        $billsByCategory = [];

        foreach (RecurringRule::query()->forWorkspaces($ids)->active()->with('wallet')->get() as $rule) {
            $due = count($rule->occurrencesUntil($monthEnd)) * $rule->baseAmount();

            if ($rule->type === TransactionType::Income) {
                $expectedIncome += $due;
            } else {
                $unpaidBills += $due;
                $key = $rule->workspace_id.':'.(int) $rule->category_id;
                $billsByCategory[$key] = ($billsByCategory[$key] ?? 0) + $due;
            }
        }

        // A bill inside a budgeted category is already counted under "unpaid bills";
        // don't reserve the same money twice.
        $budgetsRemaining = (int) $this->budgets($today)->sum(function (array $row) use ($billsByCategory): int {
            $billed = collect($row['family'])->sum(fn (int $id) => $billsByCategory[$row['budget']->workspace_id.':'.$id] ?? 0);

            return max(0, $row['remaining'] - $billed);
        });

        $sinking = (int) $funds->sum(fn (SinkingFund $fund) => $fund->dueThisMonth($today));
        $daysRemaining = (int) $today->diffInDays($monthEnd->startOfDay()) + 1;
        $pool = $liquid + $expectedIncome - $unpaidBills - $budgetsRemaining - $sinking;

        return [
            'liquid' => $liquid,
            'expected_income' => $expectedIncome,
            'unpaid_bills' => $unpaidBills,
            'budgets_remaining' => $budgetsRemaining,
            'sinking_contributions' => $sinking,
            'days_remaining' => $daysRemaining,
            'pool' => $pool,
            'daily' => (int) floor($pool / $daysRemaining),
        ];
    }

    /**
     * Reconstruct each wallet's end-of-day balance for the last $days days by
     * walking backwards from the current balance.
     *
     * @param Collection<int, Wallet> $wallets
     * @return array<int, list<float>> wallet id → balances in major units, oldest first
     */
    public function sparklines(Collection $wallets, int $days = 14, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $from = $today->subDays($days - 1);
        $ids = $wallets->pluck('id')->all();
        $deltas = [];

        Transaction::query()->between($from, $today->addYears(5))
            ->where(fn ($q) => $q->whereIn('wallet_id', $ids)->orWhereIn('destination_wallet_id', $ids))
            ->get(['wallet_id', 'destination_wallet_id', 'type', 'amount', 'destination_amount', 'occurred_on'])
            ->each(function (Transaction $t) use (&$deltas, $today): void {
                $day = min($t->occurred_on->toDateString(), $today->toDateString());
                $sign = $t->type === TransactionType::Income ? 1 : -1;
                $deltas[$t->wallet_id][$day] = ($deltas[$t->wallet_id][$day] ?? 0) + $sign * $t->amount;

                if ($t->type === TransactionType::Transfer) {
                    $deltas[$t->destination_wallet_id][$day] = ($deltas[$t->destination_wallet_id][$day] ?? 0) + (int) $t->destination_amount;
                }
            });

        $lines = [];

        foreach ($wallets as $wallet) {
            $balance = $wallet->balance;
            $points = [];

            for ($i = 0; $i < $days; $i++) {
                $date = $today->subDays($i)->toDateString();
                $points[] = Money::toFloat($balance, $wallet->currency);
                $balance -= $deltas[$wallet->id][$date] ?? 0;
            }

            $lines[$wallet->id] = array_reverse($points);
        }

        return $lines;
    }
}
