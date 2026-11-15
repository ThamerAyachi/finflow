<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DebtDirection;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Currency;
use App\Support\CurrencyRegistry;
use App\Models\RecurringRule;
use App\Models\SinkingFund;
use App\Models\Transaction;
use App\Models\TransactionRule;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Services\DebtService;
use App\Services\RecurringService;
use App\Services\ReportService;
use App\Services\RuleEngine;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\WorkspaceScope;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerMathTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $personal;

    private Workspace $business;

    private Wallet $bank;

    private Wallet $cash;

    private Wallet $usd;

    private Category $restaurants;

    private Category $salary;

    private TransactionService $tx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personal = Workspace::create(['name' => 'Personal', 'type' => 'personal']);
        $this->business = Workspace::create(['name' => 'Business', 'type' => 'business']);

        $this->bank = $this->wallet($this->personal, 'Bank', 'bank_account', 'TND', 1, 1_000_000);
        $this->cash = $this->wallet($this->personal, 'Cash', 'cash', 'TND', 1, 100_000);
        $this->usd = $this->wallet($this->personal, 'USD Card', 'e_wallet', 'USD', 3.12, 20_000); // $200.00

        $food = Category::create(['name' => 'Food', 'type' => 'expense']);
        $this->restaurants = Category::create(['name' => 'Restaurants', 'type' => 'expense', 'parent_id' => $food->id]);
        $this->salary = Category::create(['name' => 'Salary', 'type' => 'income']);

        $this->tx = app(TransactionService::class);
    }

    private function wallet(Workspace $ws, string $name, string $type, string $currency, float $rate, int $balance): Wallet
    {
        // The rate belongs to the currency (TND is the base, so it stays 1).
        Currency::query()->whereKey($currency)->where('is_base', false)->update(['exchange_rate' => $rate]);
        app(CurrencyRegistry::class)->flush();

        return Wallet::create([
            'workspace_id' => $ws->id, 'name' => $name, 'type' => $type, 'currency' => $currency,
            'initial_balance' => $balance, 'balance' => $balance,
        ]);
    }

    private function report(): ReportService
    {
        return app(ReportService::class);
    }

    /** @return array{income:int, expense:int, net:int, savings_rate:?float} */
    private function monthFlow(): array
    {
        $today = CarbonImmutable::today();

        return $this->report()->flow($today->startOfMonth(), $today->endOfMonth());
    }

    // ── Money helper ────────────────────────────────────────────────

    public function test_money_parses_and_formats_in_minor_units_without_floats(): void
    {
        $this->assertSame(1_000, Money::parse('1', 'TND'));
        $this->assertSame(12_500, Money::parse('12.5', 'TND'));
        $this->assertSame(12_500, Money::parse('12,5', 'TND'));
        $this->assertSame(1_234_500, Money::parse('1,234.50', 'TND'));
        $this->assertSame(1_235, Money::parse('12.345', 'USD'));     // half-up beyond precision
        $this->assertSame(10, Money::parse('0.1', 'USD'));
        $this->assertSame(-4_550, Money::parse('-45.5', 'EUR'));
        $this->assertSame(30, Money::parse('0.1', 'USD') + Money::parse('0.2', 'USD')); // no 0.30000000000000004

        $this->assertSame('1,234.500 TND', Money::format(1_234_500, 'TND'));
        $this->assertSame('-$45.00', Money::format(-4_500, 'USD'));
        $this->assertSame('+€0.05', Money::format(5, 'EUR', signed: true));

        $this->assertSame(31_200, Money::toBase(1_000, 'USD', 3.12));   // $10.00 → 31.200 TND
        $this->assertSame(1_000, Money::fromBase(31_200, 'USD', 3.12));

        $this->expectException(\InvalidArgumentException::class);
        Money::parse('abc', 'TND');
    }

    // ── Core balance mutations ──────────────────────────────────────

    public function test_expense_and_income_move_the_wallet_balance(): void
    {
        $this->tx->record(['wallet_id' => $this->bank->id, 'type' => 'expense', 'amount' => 45_500, 'category_id' => $this->restaurants->id]);
        $this->assertSame(954_500, $this->bank->fresh()->balance);

        $this->tx->record(['wallet_id' => $this->bank->id, 'type' => 'income', 'amount' => 3_200_000, 'category_id' => $this->salary->id]);
        $this->assertSame(4_154_500, $this->bank->fresh()->balance);

        $flow = $this->monthFlow();
        $this->assertSame(3_200_000, $flow['income']);
        $this->assertSame(45_500, $flow['expense']);
        $this->assertSame(98.6, $flow['savings_rate']);
    }

    public function test_non_positive_amounts_are_rejected_and_nothing_is_written(): void
    {
        try {
            $this->tx->record(['wallet_id' => $this->bank->id, 'type' => 'expense', 'amount' => 0]);
            $this->fail('Expected a DomainException.');
        } catch (DomainException) {
        }

        $this->assertSame(0, Transaction::count());
        $this->assertSame(1_000_000, $this->bank->fresh()->balance);
    }

    public function test_transfer_is_atomic_and_does_not_inflate_income_or_expenses(): void
    {
        $transfer = $this->tx->transfer($this->bank->id, $this->cash->id, 150_000);

        $this->assertSame(850_000, $this->bank->fresh()->balance);
        $this->assertSame(250_000, $this->cash->fresh()->balance);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(150_000, $transfer->destination_amount);

        $flow = $this->monthFlow();
        $this->assertSame(0, $flow['income']);
        $this->assertSame(0, $flow['expense']);

        // Net worth is unchanged by moving money between your own wallets.
        $this->assertSame(1_000_000 + 100_000 + Money::toBase(20_000, 'USD', 3.12), $this->report()->netWorth());
    }

    public function test_failed_transfer_rolls_back_completely(): void
    {
        try {
            $this->tx->transfer($this->bank->id, 999_999, 150_000);
            $this->fail('Expected a DomainException.');
        } catch (DomainException) {
        }

        $this->assertSame(1_000_000, $this->bank->fresh()->balance);
        $this->assertSame(0, Transaction::count());

        $this->expectException(DomainException::class);
        $this->tx->transfer($this->bank->id, $this->bank->id, 1_000);
    }

    public function test_cross_currency_transfer_converts_through_the_base_currency(): void
    {
        // $100.00 at 3.12 → 312.000 TND
        $transfer = $this->tx->transfer($this->usd->id, $this->bank->id, 10_000);

        $this->assertSame(10_000, $this->usd->fresh()->balance);
        $this->assertSame(1_312_000, $this->bank->fresh()->balance);
        $this->assertSame(312_000, $transfer->base_amount);

        // An explicit received amount (bank applied its own rate) wins.
        $this->tx->transfer($this->usd->id, $this->bank->id, 5_000, 150_000);
        $this->assertSame(5_000, $this->usd->fresh()->balance);
        $this->assertSame(1_462_000, $this->bank->fresh()->balance);
    }

    public function test_foreign_currency_expense_is_reported_in_base_currency(): void
    {
        $t = $this->tx->record(['wallet_id' => $this->usd->id, 'type' => 'expense', 'amount' => 999]); // $9.99

        $this->assertSame(19_001, $this->usd->fresh()->balance);
        $this->assertSame(31_169, $t->base_amount); // 9.99 × 3.12 = 31.1688 → 31.169 TND
        $this->assertSame(31_169, $this->monthFlow()['expense']);
    }

    // ── Split bills / reimbursements ────────────────────────────────

    public function test_reimbursements_net_against_the_original_category(): void
    {
        $dinner = $this->tx->record([
            'wallet_id' => $this->bank->id, 'type' => 'expense', 'amount' => 120_000,
            'category_id' => $this->restaurants->id, 'description' => 'Group dinner',
        ]);
        $this->tx->reimburse($dinner, $this->cash->id, 45_000);
        $back = $this->tx->reimburse($dinner, $this->bank->id, 45_000);

        $this->assertSame(1_000_000 - 120_000 + 45_000, $this->bank->fresh()->balance);
        $this->assertSame(145_000, $this->cash->fresh()->balance);
        $this->assertSame($this->restaurants->id, $back->category_id);
        $this->assertSame(30_000, $dinner->fresh()->load('reimbursements')->netAmount());

        // Paid 120, got 90 back → 30 spent, and the 90 is NOT income.
        $flow = $this->monthFlow();
        $this->assertSame(30_000, $flow['expense']);
        $this->assertSame(0, $flow['income']);

        $today = CarbonImmutable::today();
        $matrix = $this->report()->spendingMatrix($today->startOfMonth(), $today->endOfMonth());
        $this->assertSame(30_000, $matrix[$this->personal->id][$this->restaurants->id]);

        $breakdown = $this->report()->categoryBreakdown($today->startOfMonth(), $today->endOfMonth());
        $this->assertSame('Food', $breakdown->first()['name']); // rolled up to the parent
        $this->assertSame(30_000, $breakdown->first()['amount']);
    }

    public function test_reimbursement_cannot_exceed_the_original_expense(): void
    {
        $dinner = $this->tx->record(['wallet_id' => $this->bank->id, 'type' => 'expense', 'amount' => 120_000]);
        $this->tx->reimburse($dinner, $this->cash->id, 100_000);

        try {
            $this->tx->reimburse($dinner, $this->cash->id, 20_001);
            $this->fail('Expected a DomainException.');
        } catch (DomainException) {
        }

        $this->assertSame(200_000, $this->cash->fresh()->balance);
        $this->assertSame(2, Transaction::count());
    }

    // ── Edit / delete ───────────────────────────────────────────────

    public function test_update_reverses_the_old_effect_and_applies_the_new_one(): void
    {
        $t = $this->tx->record(['wallet_id' => $this->bank->id, 'type' => 'expense', 'amount' => 50_000]);

        $this->tx->update($t, ['amount' => 80_000]);
        $this->assertSame(920_000, $this->bank->fresh()->balance);

        // Move it to another wallet: the bank is made whole, cash takes the hit.
        $this->tx->update($t, ['wallet_id' => $this->cash->id]);
        $this->assertSame(1_000_000, $this->bank->fresh()->balance);
        $this->assertSame(20_000, $this->cash->fresh()->balance);

        $transfer = $this->tx->transfer($this->bank->id, $this->cash->id, 100_000);
        $this->tx->update($transfer, ['amount' => 40_000]);
        $this->assertSame(960_000, $this->bank->fresh()->balance);
        $this->assertSame(60_000, $this->cash->fresh()->balance);
    }

    public function test_delete_restores_balances_including_reimbursements(): void
    {
        $dinner = $this->tx->record(['wallet_id' => $this->bank->id, 'type' => 'expense', 'amount' => 120_000]);
        $this->tx->reimburse($dinner, $this->cash->id, 90_000);
        $transfer = $this->tx->transfer($this->bank->id, $this->cash->id, 200_000);

        $this->tx->delete($dinner);
        $this->tx->delete($transfer);

        $this->assertSame(1_000_000, $this->bank->fresh()->balance);
        $this->assertSame(100_000, $this->cash->fresh()->balance);
        $this->assertSame(0, Transaction::count());
    }

    public function test_every_wallet_balance_reconciles_with_its_history(): void
    {
        $this->tx->record(['wallet_id' => $this->bank->id, 'type' => 'income', 'amount' => 3_200_000]);
        $a = $this->tx->record(['wallet_id' => $this->bank->id, 'type' => 'expense', 'amount' => 85_300]);
        $this->tx->transfer($this->bank->id, $this->cash->id, 150_000);
        $this->tx->transfer($this->usd->id, $this->bank->id, 7_500);
        $this->tx->reimburse($a, $this->cash->id, 20_000);
        $this->tx->update($a, ['amount' => 99_900]);
        $this->tx->delete($this->tx->record(['wallet_id' => $this->cash->id, 'type' => 'expense', 'amount' => 9_000]));

        foreach (Wallet::all() as $wallet) {
            $expected = $wallet->initial_balance;
            foreach (Transaction::where('wallet_id', $wallet->id)->orWhere('destination_wallet_id', $wallet->id)->get() as $t) {
                if ($t->type->value === 'transfer') {
                    $expected += $t->wallet_id === $wallet->id ? -$t->amount : $t->destination_amount;
                } else {
                    $expected += $t->direction() * $t->amount;
                }
            }
            $this->assertSame($expected, $wallet->balance, "{$wallet->name} does not reconcile");
        }
    }

    // ── Debts ───────────────────────────────────────────────────────

    public function test_debt_payments_move_cash_but_are_not_income_or_spending(): void
    {
        $debts = app(DebtService::class);

        $debt = $debts->create([
            'workspace_id' => $this->personal->id, 'contact_name' => 'Ahmed',
            'direction' => DebtDirection::Receivable, 'principal' => 500_000,
        ], $this->bank->id);
        $this->assertSame(500_000, $this->bank->fresh()->balance);

        $debts->recordPayment($debt, $this->cash->id, 200_000);
        $debt->refresh();
        $this->assertSame(300_000, $debt->remaining());
        $this->assertFalse($debt->isSettled());
        $this->assertSame(300_000, $this->cash->fresh()->balance);

        $payment = $debts->settle($debt, $this->cash->id);
        $debt->refresh();
        $this->assertSame(0, $debt->remaining());
        $this->assertTrue($debt->isSettled());
        $this->assertSame(600_000, $this->cash->fresh()->balance);
        $this->assertSame(2, $debt->payments()->count());

        $flow = $this->monthFlow();
        $this->assertSame(0, $flow['income']);
        $this->assertSame(0, $flow['expense']);

        // Deleting the linked wallet transaction re-opens the debt.
        $this->tx->delete($payment->transaction);
        $debt->refresh();
        $this->assertSame(300_000, $debt->remaining());
        $this->assertFalse($debt->isSettled());
        $this->assertSame(300_000, $this->cash->fresh()->balance);

        $this->expectException(DomainException::class);
        $debts->recordPayment($debt, $this->cash->id, 300_001);
    }

    public function test_paying_back_a_loan_decrements_the_wallet(): void
    {
        $debts = app(DebtService::class);
        $debt = $debts->create([
            'workspace_id' => $this->personal->id, 'contact_name' => 'Mom',
            'direction' => DebtDirection::Payable, 'principal' => 1_000_000,
        ], $this->bank->id);
        $this->assertSame(2_000_000, $this->bank->fresh()->balance);

        $debts->recordPayment($debt, $this->bank->id, 400_000);
        $this->assertSame(1_600_000, $this->bank->fresh()->balance);
        $this->assertSame(60.0, 100 - $debt->fresh()->progress());
    }

    // ── Recurring ───────────────────────────────────────────────────

    public function test_mark_as_paid_posts_the_transaction_and_advances_the_due_date(): void
    {
        $today = CarbonImmutable::today();
        $rule = RecurringRule::create([
            'workspace_id' => $this->personal->id, 'wallet_id' => $this->bank->id, 'name' => 'Rent',
            'type' => 'expense', 'amount' => 850_000, 'frequency' => 'monthly', 'next_due_date' => $today,
        ]);

        $t = app(RecurringService::class)->markPaid($rule);

        $this->assertSame(150_000, $this->bank->fresh()->balance);
        $this->assertSame($rule->id, $t->recurring_rule_id);
        $this->assertTrue($rule->fresh()->next_due_date->equalTo($today->addMonthNoOverflow()));
    }

    public function test_auto_post_catches_up_on_every_missed_occurrence(): void
    {
        $today = CarbonImmutable::today();
        RecurringRule::create([
            'workspace_id' => $this->personal->id, 'wallet_id' => $this->cash->id, 'name' => 'Cleaning',
            'type' => 'expense', 'amount' => 10_000, 'frequency' => 'weekly', 'next_due_date' => $today->subWeeks(2), 'auto_post' => true,
        ]);
        RecurringRule::create([
            'workspace_id' => $this->personal->id, 'wallet_id' => $this->cash->id, 'name' => 'Manual bill',
            'type' => 'expense', 'amount' => 10_000, 'frequency' => 'weekly', 'next_due_date' => $today->subWeeks(2),
        ]);

        $this->assertSame(3, app(RecurringService::class)->postDue($today));
        $this->assertSame(70_000, $this->cash->fresh()->balance);
    }

    // ── Rules ───────────────────────────────────────────────────────

    public function test_rule_engine_matches_keywords_case_insensitively(): void
    {
        TransactionRule::create(['keyword' => 'STEG', 'category_id' => $this->restaurants->id, 'wallet_id' => $this->bank->id]);
        TransactionRule::create(['keyword' => 'Meta Ads', 'category_id' => $this->salary->id]);
        TransactionRule::create(['keyword' => 'Paused', 'category_id' => $this->salary->id, 'is_active' => false]);

        $engine = app(RuleEngine::class);

        $this->assertSame($this->restaurants->id, $engine->match('Facture steg novembre')?->category_id);
        $this->assertSame($this->bank->id, $engine->match('STEG')?->wallet_id);
        $this->assertSame($this->salary->id, $engine->match('META ADS *campaign 12')?->category_id);
        $this->assertNull($engine->match('paused thing'));
        $this->assertNull($engine->match('Unrelated'));
    }

    // ── Workspace isolation ─────────────────────────────────────────

    public function test_business_spending_never_pollutes_personal_metrics(): void
    {
        $biz = $this->wallet($this->business, 'Business Bank', 'bank_account', 'TND', 1, 5_000_000);
        $this->tx->record(['wallet_id' => $biz->id, 'type' => 'expense', 'amount' => 700_000]);
        $this->tx->record(['wallet_id' => $this->bank->id, 'type' => 'expense', 'amount' => 40_000]);

        $scope = app(WorkspaceScope::class);

        $scope->set($this->personal->id);
        $this->assertSame(40_000, $this->monthFlow()['expense']);

        $scope->set($this->business->id);
        $this->assertSame(700_000, $this->monthFlow()['expense']);
        $this->assertSame(4_300_000, $this->report()->netWorth());

        $scope->set('all');
        $this->assertSame(740_000, $this->monthFlow()['expense']);
    }

    public function test_wallets_excluded_from_net_worth_are_ignored(): void
    {
        $before = $this->report()->netWorth();
        $this->cash->update(['is_included_in_net_worth' => false]);

        $this->assertSame($before - 100_000, $this->report()->netWorth());
    }

    // ── Budgets & safe-to-spend ─────────────────────────────────────

    public function test_budget_progress_thresholds_and_rollover(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00'));

        $food = $this->restaurants->parent;
        Budget::create(['workspace_id' => $this->personal->id, 'category_id' => $food->id, 'amount' => 300_000, 'rollover' => true]);

        // Last month: spent 200 of 300 → 100 rolls over, cap becomes 400.
        $this->tx->record(['wallet_id' => $this->bank->id, 'type' => 'expense', 'amount' => 200_000, 'category_id' => $this->restaurants->id, 'occurred_on' => '2026-05-20']);
        // This month: 320 spent in a child category counts toward the parent budget.
        $this->tx->record(['wallet_id' => $this->bank->id, 'type' => 'expense', 'amount' => 320_000, 'category_id' => $this->restaurants->id, 'occurred_on' => '2026-06-10']);

        $row = $this->report()->budgets()->first();

        $this->assertSame(100_000, $row['carry']);
        $this->assertSame(400_000, $row['cap']);
        $this->assertSame(320_000, $row['spent']);
        $this->assertSame(80.0, $row['percent']);
        $this->assertSame('warn', $row['status']);                     // 75–95 %
        $this->assertSame('You are 9 days behind pace', $row['pace_label']); // 320 spent vs 200 expected by day 15 of 30

        $this->tx->record(['wallet_id' => $this->bank->id, 'type' => 'expense', 'amount' => 70_000, 'category_id' => $food->id, 'occurred_on' => '2026-06-12']);
        $this->assertSame('expense', $this->report()->budgets()->first()['status']); // > 95 %
    }

    public function test_safe_to_spend_follows_the_documented_formula(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-21 09:00')); // 10 days left incl. today
        $today = CarbonImmutable::today();

        // Liquid: bank 1000 + cash 100 + $200 × 3.12 = 1724.000
        $savings = $this->wallet($this->personal, 'Savings', 'savings_pot', 'TND', 1, 9_000_000); // not liquid
        $this->assertSame(1_724_000, $this->report()->liquidCash());

        // + expected income 500 on the 25th
        RecurringRule::create(['workspace_id' => $this->personal->id, 'wallet_id' => $this->bank->id, 'name' => 'Freelance retainer',
            'type' => 'income', 'amount' => 500_000, 'frequency' => 'monthly', 'next_due_date' => '2026-06-25']);
        // − unpaid bills: 45 (once) + weekly 40 on the 22nd and 29th = 125
        RecurringRule::create(['workspace_id' => $this->personal->id, 'wallet_id' => $this->bank->id, 'name' => 'Internet',
            'type' => 'expense', 'amount' => 45_000, 'frequency' => 'monthly', 'next_due_date' => '2026-06-24']);
        RecurringRule::create(['workspace_id' => $this->personal->id, 'wallet_id' => $this->cash->id, 'name' => 'Cleaning',
            'type' => 'expense', 'amount' => 40_000, 'frequency' => 'weekly', 'next_due_date' => '2026-06-22']);
        // Next month's rent is out of range.
        RecurringRule::create(['workspace_id' => $this->personal->id, 'wallet_id' => $this->bank->id, 'name' => 'Rent',
            'type' => 'expense', 'amount' => 850_000, 'frequency' => 'monthly', 'next_due_date' => '2026-07-05']);

        // − remaining budget: 300 cap − 100 spent = 200
        Budget::create(['workspace_id' => $this->personal->id, 'category_id' => $this->restaurants->id, 'amount' => 300_000]);
        $this->tx->record(['wallet_id' => $savings->id, 'type' => 'expense', 'amount' => 100_000, 'category_id' => $this->restaurants->id, 'occurred_on' => $today]);

        // − sinking fund: 1200 target over 6 months (Jun–Nov) = 200/month, 50 already contributed → 150
        $fund = SinkingFund::create(['workspace_id' => $this->personal->id, 'name' => 'Car', 'target_amount' => 1_200_000, 'target_date' => '2026-11-30']);
        $fund->contributions()->create(['amount' => 50_000, 'contributed_on' => $today]);
        $fund->update(['saved_amount' => 50_000]);

        $safe = $this->report()->safeToSpend();

        $this->assertSame(1_724_000, $safe['liquid']);
        $this->assertSame(500_000, $safe['expected_income']);
        $this->assertSame(125_000, $safe['unpaid_bills']);
        $this->assertSame(200_000, $safe['budgets_remaining']);
        $this->assertSame(150_000, $safe['sinking_contributions']);
        $this->assertSame(10, $safe['days_remaining']);
        $this->assertSame(1_724_000 + 500_000 - 125_000 - 200_000 - 150_000, $safe['pool']);
        $this->assertSame(174_900, $safe['daily']);
    }
}
