<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DebtDirection;
use App\Enums\Frequency;
use App\Models\Budget;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\SinkingFund;
use App\Models\Currency;
use App\Models\TransactionRule;
use App\Support\CurrencyRegistry;
use App\Support\StarterCategories;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Services\DebtService;
use App\Services\TransactionService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Seeds two workspaces with 60 days of believable activity. Every transaction
 * goes through TransactionService so wallet balances always reconcile.
 */
class DatabaseSeeder extends Seeder
{
    private CarbonImmutable $today;

    /** @var array<string, Wallet> */
    private array $w = [];

    /** @var array<string, Category> */
    private array $c = [];

    public function __construct(
        private readonly TransactionService $tx,
        private readonly DebtService $debts,
    ) {}

    public function run(): void
    {
        mt_srand(20260101); // deterministic demo data
        $this->today = CarbonImmutable::today();

        $personal = Workspace::create(['name' => 'Personal', 'type' => 'personal', 'color_hex' => '#22C55E']);
        $business = Workspace::create(['name' => 'Business / E-Commerce', 'type' => 'business', 'color_hex' => '#3B82F6']);

        $this->seedWallets($personal, $business);
        $this->seedCategories();
        $this->seedRecurring($personal, $business);
        $this->seedDailyActivity();
        $this->seedBudgets($personal, $business);
        $this->seedSinkingFunds($personal, $business);
        $this->seedDebts($personal, $business);
        $this->seedRules();

        // Login account. Override with SEED_USER_EMAIL / SEED_USER_PASSWORD in .env.
        User::query()->updateOrCreate(
            ['email' => env('SEED_USER_EMAIL', 'admin@money-traker.test')],
            ['name' => env('SEED_USER_NAME', 'Admin'), 'password' => Hash::make(env('SEED_USER_PASSWORD', 'password'))],
        );
    }

    private function seedWallets(Workspace $personal, Workspace $business): void
    {
        $wallets = [
            // key => [workspace, name, type, currency, rate, opening balance, colour, in net worth]
            'cash' => [$personal, 'Cash', 'cash', 'TND', 1, '480', '#22C55E', true],
            'bank' => [$personal, 'BIAT Checking', 'bank_account', 'TND', 1, '2400', '#3B82F6', true],
            'usd' => [$personal, 'Wise USD Card', 'e_wallet', 'USD', 3.12, '420', '#A855F7', true],
            'savings' => [$personal, 'Savings Pot', 'savings_pot', 'TND', 1, '5500', '#F59E0B', true],
            'cc' => [$personal, 'Visa Credit Card', 'credit_card', 'TND', 1, '-320', '#F43F5E', true],
            'bbank' => [$business, 'Business Account', 'bank_account', 'TND', 1, '6800', '#3B82F6', true],
            'bcash' => [$business, 'Store Cash Float', 'cash', 'TND', 1, '250', '#22C55E', true],
            'payo' => [$business, 'Payoneer USD', 'e_wallet', 'USD', 3.12, '950', '#F97316', true],
            'stripe' => [$business, 'Stripe EUR', 'e_wallet', 'EUR', 3.38, '600', '#06B6D4', false],
        ];

        // Rates live on the currency (the migration creates TND/USD/EUR).
        foreach ($wallets as [, , , $currency, $rate]) {
            Currency::query()->whereKey($currency)->where('is_base', false)->update(['exchange_rate' => $rate]);
        }
        app(CurrencyRegistry::class)->flush();

        foreach ($wallets as $key => [$workspace, $name, $type, $currency, , $opening, $color, $inNetWorth]) {
            $balance = Money::parse($opening, $currency);
            $this->w[$key] = Wallet::create([
                'workspace_id' => $workspace->id, 'name' => $name, 'type' => $type, 'currency' => $currency,
                'initial_balance' => $balance, 'balance' => $balance,
                'color_hex' => $color, 'is_included_in_net_worth' => $inNetWorth,
            ]);
        }
    }

    private function seedCategories(): void
    {
        $this->c = StarterCategories::install();
    }

    /** Recurring rules, plus their history for the past 60 days. */
    private function seedRecurring(Workspace $personal, Workspace $business): void
    {
        $t = $this->today;
        $nextDay = fn (int $day): CarbonImmutable => $t->day < $day ? $t->setDay($day) : $t->addMonthNoOverflow()->setDay($day);

        $rules = [
            // name, type, wallet, category, amount, frequency, next due, auto-post
            ['Salary — Acme Corp', 'income', 'bank', 'Salary', '3200', 'monthly', $nextDay(1), false],
            ['Apartment rent', 'expense', 'bank', 'Rent', '850', 'monthly', $nextDay(5), false],
            ['Topnet fibre', 'expense', 'bank', 'Internet & Phone', '45', 'monthly', $t->addDays(3), false],
            ['STEG electricity & gas', 'expense', 'bank', 'Utilities', '96.400', 'monthly', $t->addDays(12), false],
            ['Netflix', 'expense', 'usd', 'Subscriptions', '9.99', 'monthly', $t->addDays(6), true],
            ['Spotify', 'expense', 'usd', 'Subscriptions', '5.99', 'monthly', $t->addDays(10), true],
            ['Gym membership', 'expense', 'bank', 'Health', '70', 'monthly', $t->subDay(), false],
            ['Home cleaning', 'expense', 'cash', 'Housing', '40', 'weekly', $t->addDays(2), false],
            ['Car insurance', 'expense', 'bank', 'Insurance', '640', 'yearly', $t->addDays(45), false],
            ['Shopify plan', 'expense', 'payo', 'Software & SaaS', '39', 'monthly', $t->addDays(8), true],
            ['Accountant retainer', 'expense', 'bbank', 'Business Ops', '250', 'monthly', $t->addDays(13), false],
            ['Warehouse rent', 'expense', 'bbank', 'Business Ops', '400', 'monthly', $t->addDays(20), false],
        ];

        $start = $t->subDays(59);

        foreach ($rules as [$name, $type, $wallet, $category, $amount, $frequency, $next, $auto]) {
            $wallet = $this->w[$wallet];
            $rule = RecurringRule::create([
                'workspace_id' => $wallet->workspace_id, 'wallet_id' => $wallet->id, 'category_id' => $this->c[$category]->id,
                'name' => $name, 'type' => $type, 'amount' => Money::parse($amount, $wallet->currency),
                'frequency' => $frequency, 'next_due_date' => $next, 'auto_post' => $auto,
            ]);

            $frequency = Frequency::from($frequency);
            for ($date = $frequency->rewind($next); $date->gte($start); $date = $frequency->rewind($date)) {
                $this->tx->record([
                    'wallet_id' => $wallet->id, 'type' => $type, 'amount' => $rule->amount, 'category_id' => $rule->category_id,
                    'description' => $name, 'occurred_on' => $date, 'recurring_rule_id' => $rule->id,
                ]);
            }
        }
    }

    private function seedDailyActivity(): void
    {
        for ($i = 59; $i >= 0; $i--) {
            $date = $this->today->subDays($i);

            // ── Personal ────────────────────────────────────────────────
            if ($this->chance(45)) {
                $this->spend($this->chance(60) ? 'bank' : 'cash', 'Groceries', $this->pick(['Carrefour Market', 'Monoprix', 'Aziza', 'MG Proxi']), 18, 95, $date);
            }
            if ($this->chance(55)) {
                $this->spend('cash', 'Coffee', $this->pick(['Café Ben Rahim', 'Kaffeine', 'Espresso bar']), 3.5, 9, $date);
            }
            if ($this->chance(22)) {
                $this->spend($this->chance(50) ? 'bank' : 'cc', 'Restaurants', $this->pick(['Le Baroque', 'Pasta Cosy', 'Plan B Burger', 'El Ali']), 22, 85, $date);
            }
            if ($i % 6 === 2) {
                $this->spend('bank', 'Fuel', $this->pick(['Shell Fuel', 'Agil Fuel']), 60, 90, $date);
            }
            if ($this->chance(25)) {
                $this->spend('cash', 'Taxi & Rides', 'Bolt ride', 4, 14, $date);
            }
            if ($this->chance(10)) {
                $this->spend('cc', 'Shopping', $this->pick(['Zara', 'Decathlon', 'Jumia order']), 45, 260, $date);
            }
            if ($this->chance(5)) {
                $this->spend('bank', 'Health', $this->pick(['Pharmacie Centrale', 'Dr. consultation']), 25, 80, $date);
            }
            if ($this->chance(8)) {
                $this->spend('bank', 'Entertainment', $this->pick(['Pathé cinema', 'Bowling night']), 15, 45, $date);
            }
            if ($date->isMonday()) {
                $this->tx->transfer($this->w['bank']->id, $this->w['cash']->id, 150_000, null, $date, 'ATM withdrawal');
            }
            if ($date->day === 2) {
                $this->tx->transfer($this->w['bank']->id, $this->w['savings']->id, 400_000, null, $date, 'Monthly savings');
            }
            if ($date->day === 10) {
                $this->tx->transfer($this->w['bank']->id, $this->w['cc']->id, 300_000, null, $date, 'Credit card payment');
            }
            if ($i === 47 || $i === 19) {
                $this->earn('usd', 'Freelance', 'Upwork payout', $i === 47 ? 380 : 520, $date);
            }
            if ($i === 15) {
                $this->tx->transfer($this->w['usd']->id, $this->w['bank']->id, 30_000, null, $date, 'USD → TND conversion');
            }

            // Split bill: paid 120 for the table, friends paid back 90 → net 30 in Restaurants.
            if ($i === 6) {
                $dinner = $this->tx->record([
                    'wallet_id' => $this->w['bank']->id, 'type' => 'expense', 'amount' => 120_000,
                    'category_id' => $this->c['Restaurants']->id, 'description' => 'Dinner at Dar El Jeld (group)', 'occurred_on' => $date,
                ]);
                $this->tx->reimburse($dinner, $this->w['cash']->id, 45_000, $date->addDay(), 'Sami paid back — dinner');
                $this->tx->reimburse($dinner, $this->w['bank']->id, 45_000, $date->addDays(2), 'Leila paid back — dinner');
            }

            // ── Business ────────────────────────────────────────────────
            if ($this->chance(70)) {
                $this->earn('bbank', 'Sales Revenue', 'Shopify payout #'.(4100 + (60 - $i) * 3), $this->between(140, 620), $date);
            }
            if ($this->chance(15)) {
                $this->earn('stripe', 'Sales Revenue', 'Stripe payout (EU orders)', $this->between(40, 120), $date);
            }
            if ($i % 3 === 0) {
                $this->spend('payo', 'Advertising', 'Meta Ads', 18, 55, $date);
            }
            if ($i % 13 === 4) {
                $this->spend('bbank', 'Inventory', 'Supplier restock — TexPrint', 700, 1500, $date);
            }
            if ($this->chance(50)) {
                $this->spend($this->chance(80) ? 'bbank' : 'bcash', 'Shipping', 'Aramex shipping', 14, 58, $date);
            }
            if ($i % 17 === 5) {
                $this->spend('bbank', 'Packaging', 'Packaging supplies', 60, 140, $date);
            }
            if ($i % 20 === 7) {
                $this->tx->transfer($this->w['bbank']->id, $this->w['payo']->id, 600_000, null, $date, 'Payoneer top-up');
            }
            if ($date->day === 28) {
                $this->tx->transfer($this->w['bbank']->id, $this->w['bank']->id, 1_200_000, null, $date, 'Owner draw');
            }
        }
    }

    private function seedBudgets(Workspace $personal, Workspace $business): void
    {
        $budgets = [
            [$personal, 'Groceries', '650', true], [$personal, 'Restaurants', '300', false], [$personal, 'Coffee', '90', false],
            [$personal, 'Transport', '320', false], [$personal, 'Shopping', '350', true], [$personal, 'Entertainment', '120', false],
            [$personal, 'Health', '160', false],
            [$business, 'Advertising', '1400', false], [$business, 'Shipping', '500', false], [$business, 'Software & SaaS', '200', true],
        ];

        foreach ($budgets as [$workspace, $category, $amount, $rollover]) {
            Budget::create([
                'workspace_id' => $workspace->id, 'category_id' => $this->c[$category]->id,
                'amount' => Money::parse($amount, 'TND'), 'rollover' => $rollover,
            ]);
        }
    }

    private function seedSinkingFunds(Workspace $personal, Workspace $business): void
    {
        $funds = [
            // workspace, name, icon, colour, target, months until target, contributions [months ago => amount]
            [$personal, 'Annual Car Maintenance', 'wrench', '#3B82F6', '1800', 7, [2 => '250', 1 => '250', 0 => '100']],
            [$personal, 'Insurance Renewal', 'shield', '#64748B', '640', 2, [2 => '160', 1 => '160']],
            [$personal, 'New MacBook Pro', 'laptop', '#A855F7', '7500', 10, [2 => '1200', 1 => '500', 0 => '400']],
            [$business, 'Camera & Studio Gear', 'camera', '#F97316', '3000', 6, [2 => '600', 1 => '300']],
        ];

        foreach ($funds as [$workspace, $name, $icon, $color, $target, $months, $contributions]) {
            $fund = SinkingFund::create([
                'workspace_id' => $workspace->id, 'name' => $name, 'icon' => $icon, 'color_hex' => $color,
                'target_amount' => Money::parse($target, 'TND'), 'target_date' => $this->today->addMonthsNoOverflow($months),
            ]);

            foreach ($contributions as $monthsAgo => $amount) {
                $fund->contributions()->create([
                    'amount' => Money::parse($amount, 'TND'),
                    'contributed_on' => $monthsAgo === 0 ? $this->today->startOfMonth() : $this->today->subMonthsNoOverflow($monthsAgo)->startOfMonth()->addDays(2),
                ]);
            }

            $fund->update(['saved_amount' => (int) $fund->contributions()->sum('amount')]);
        }
    }

    private function seedDebts(Workspace $personal, Workspace $business): void
    {
        $t = $this->today;
        $make = fn (Workspace $ws, string $name, DebtDirection $dir, string $amount, int $dueIn, ?string $wallet, int $daysAgo, ?string $notes = null) => $this->debts->create([
            'workspace_id' => $ws->id, 'contact_name' => $name, 'direction' => $dir,
            'principal' => Money::parse($amount, 'TND'), 'due_date' => $t->addDays($dueIn), 'notes' => $notes,
        ], $wallet ? $this->w[$wallet]->id : null, $t->subDays($daysAgo));

        $ahmed = $make($personal, 'Ahmed B.', DebtDirection::Receivable, '500', 10, 'bank', 40, 'Car repair loan');
        $this->debts->recordPayment($ahmed, $this->w['cash']->id, 200_000, $t->subDays(20));

        $make($personal, 'Sami K.', DebtDirection::Receivable, '150', 20, 'cash', 12, 'Concert tickets');
        $make($personal, 'Yassine', DebtDirection::Receivable, '80', -5, 'cash', 30, 'Lunch & taxi');

        $mom = $make($personal, 'Mom', DebtDirection::Payable, '1000', 40, 'bank', 50, 'Laptop advance');
        $this->debts->recordPayment($mom, $this->w['bank']->id, 200_000, $t->subDays(30));
        $this->debts->recordPayment($mom, $this->w['bank']->id, 200_000, $t->subDays(8));

        $supplier = $make($business, 'TexPrint (supplier credit)', DebtDirection::Payable, '2400', 25, null, 35, 'Net-60 invoice #2291');
        $this->debts->recordPayment($supplier, $this->w['bbank']->id, 800_000, $t->subDays(15));
    }

    private function seedRules(): void
    {
        $rules = [
            ['STEG', 'Utilities', 'bank'], ['Topnet', 'Internet & Phone', 'bank'], ['Meta Ads', 'Advertising', 'payo'],
            ['Fuel', 'Fuel', null], ['Carrefour', 'Groceries', null], ['Monoprix', 'Groceries', null], ['Bolt', 'Taxi & Rides', 'cash'],
            ['Aramex', 'Shipping', 'bbank'], ['Netflix', 'Subscriptions', 'usd'], ['Spotify', 'Subscriptions', 'usd'],
            ['Shopify', 'Software & SaaS', 'payo'],
        ];

        foreach ($rules as [$keyword, $category, $wallet]) {
            TransactionRule::create([
                'keyword' => $keyword, 'category_id' => $this->c[$category]->id, 'wallet_id' => $wallet ? $this->w[$wallet]->id : null,
            ]);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function spend(string $wallet, string $category, string $description, float $min, float $max, CarbonImmutable $date): void
    {
        $this->post('expense', $wallet, $category, $description, $this->between($min, $max), $date);
    }

    private function earn(string $wallet, string $category, string $description, float $amount, CarbonImmutable $date): void
    {
        $this->post('income', $wallet, $category, $description, $amount, $date);
    }

    private function post(string $type, string $wallet, string $category, string $description, float $amount, CarbonImmutable $date): void
    {
        $wallet = $this->w[$wallet];

        $this->tx->record([
            'wallet_id' => $wallet->id, 'type' => $type, 'category_id' => $this->c[$category]->id, 'description' => $description,
            'amount' => Money::parse(number_format($amount, 1, '.', ''), $wallet->currency), 'occurred_on' => $date,
        ]);
    }

    private function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    private function between(float $min, float $max): float
    {
        return round($min + mt_rand(0, 1000) / 1000 * ($max - $min), 1);
    }

    /** @param list<string> $options */
    private function pick(array $options): string
    {
        return $options[mt_rand(0, count($options) - 1)];
    }
}
