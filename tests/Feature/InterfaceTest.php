<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Budgets;
use App\Livewire\CategoryDrawer;
use App\Livewire\Dashboard;
use App\Livewire\Debts;
use App\Livewire\Ledger;
use App\Livewire\QuickEntry;
use App\Livewire\Settings;
use App\Livewire\TransactionDrawer;
use App\Livewire\WorkspaceSwitcher;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Debt;
use App\Models\RecurringRule;
use App\Models\SinkingFund;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Renders every screen against the full demo dataset and drives the main interactions. */
class InterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed();
        $this->actingAs(\App\Models\User::query()->firstOrFail());
    }

    private function wallet(string $name): Wallet
    {
        return Wallet::where('name', $name)->firstOrFail();
    }

    /** @return array<string, array{string, string}> */
    public static function pages(): array
    {
        return [
            'dashboard' => ['/', 'Safe to spend today'],
            'transactions' => ['/transactions', 'Total in'],
            'budgets' => ['/budgets', 'Monthly envelopes'],
            'debts' => ['/debts', 'Recurring rules'],
            'settings' => ['/settings', 'New wallet'],
            'settings: currencies' => ['/settings?tab=currencies', 'New currency'],
            'settings: workspaces' => ['/settings?tab=workspaces', 'New workspace'],
            'settings: categories' => ['/settings?tab=categories', 'Auto-categorisation rules'],
            'settings: account' => ['/settings?tab=account', 'Update password'],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_renders_in_every_workspace_scope(string $url, string $marker): void
    {
        foreach (['all', (string) Workspace::first()->id, (string) Workspace::latest('id')->first()->id] as $scope) {
            $this->withSession(['workspace_scope' => $scope])->get($url)->assertOk()->assertSee($marker);
        }
    }

    public function test_seeded_balances_reconcile(): void
    {
        $this->assertGreaterThan(200, Transaction::count());

        foreach (Wallet::all() as $wallet) {
            $expected = $wallet->initial_balance;
            foreach (Transaction::where('wallet_id', $wallet->id)->orWhere('destination_wallet_id', $wallet->id)->get() as $t) {
                $expected += $t->type->value === 'transfer'
                    ? ($t->wallet_id === $wallet->id ? -$t->amount : $t->destination_amount)
                    : $t->direction() * $t->amount;
            }
            $this->assertSame($expected, $wallet->balance, $wallet->name);
        }
    }

    public function test_workspace_switcher_changes_the_global_scope(): void
    {
        $business = Workspace::where('type', 'business')->firstOrFail();

        Livewire::test(WorkspaceSwitcher::class)->call('switch', (string) $business->id)->assertRedirect();
        $this->assertSame((string) $business->id, session('workspace_scope'));

        Livewire::test(Dashboard::class)->assertSee('Business Account')->assertDontSee('BIAT Checking');
    }

    public function test_quick_entry_logs_expense_income_and_transfer(): void
    {
        $bank = $this->wallet('BIAT Checking');
        $cash = $this->wallet('Cash');
        $before = [$bank->balance, $cash->balance];
        $utilities = Category::where('name', 'Utilities')->firstOrFail();
        $today = today()->toDateString();

        // Rule engine suggestion while typing.
        Livewire::test(QuickEntry::class)->call('suggest', 'Facture STEG')->assertReturned([
            'keyword' => 'STEG', 'categoryId' => $utilities->id, 'categoryType' => 'expense', 'categoryName' => 'Utilities', 'walletId' => $bank->id,
        ]);
        Livewire::test(QuickEntry::class)->call('suggest', 'nothing matches this')->assertReturned(null);

        Livewire::test(QuickEntry::class)
            ->call('save', ['type' => 'expense', 'amount' => '96.400', 'walletId' => $bank->id, 'categoryId' => $utilities->id, 'description' => 'Facture STEG', 'date' => $today])
            ->assertReturned(['ok' => true])
            ->assertDispatched('transaction-saved');

        $this->assertSame($before[0] - 96_400, $bank->fresh()->balance);
        $this->assertSame('Utilities', Transaction::latest('id')->first()->category->name);

        Livewire::test(QuickEntry::class)
            ->call('save', ['type' => 'transfer', 'amount' => '50', 'walletId' => $bank->id, 'destinationWalletId' => $cash->id, 'date' => $today])
            ->assertReturned(['ok' => true]);

        $this->assertSame($before[0] - 96_400 - 50_000, $bank->fresh()->balance);
        $this->assertSame($before[1] + 50_000, $cash->fresh()->balance);

        Livewire::test(QuickEntry::class)
            ->call('save', ['type' => 'income', 'amount' => '12.5', 'walletId' => $cash->id, 'date' => $today])
            ->assertReturned(['ok' => true]);
        $this->assertSame($before[1] + 62_500, $cash->fresh()->balance);

        // Invalid submissions are refused and nothing moves.
        $count = Transaction::count();
        Livewire::test(QuickEntry::class)
            ->call('save', ['type' => 'transfer', 'amount' => '10', 'walletId' => $bank->id, 'date' => $today])
            ->assertReturned(['ok' => false, 'error' => 'Choose the wallet to transfer to.']);
        Livewire::test(QuickEntry::class)
            ->call('save', ['type' => 'transfer', 'amount' => '10', 'walletId' => $bank->id, 'destinationWalletId' => $bank->id, 'date' => $today])
            ->assertReturned(['ok' => false, 'error' => 'Source and destination wallets must differ.']);
        Livewire::test(QuickEntry::class)
            ->call('save', ['type' => 'expense', 'amount' => '0', 'walletId' => $bank->id, 'date' => $today])
            ->assertReturned(['ok' => false, 'error' => 'Amount must be greater than zero.']);

        $this->assertSame($count, Transaction::count());
        $this->assertSame($before[0] - 146_400, $bank->fresh()->balance);
    }

    public function test_transaction_drawer_edits_splits_and_deletes(): void
    {
        $bank = $this->wallet('BIAT Checking');
        $cash = $this->wallet('Cash');
        $dinner = Transaction::where('description', 'Dinner at Dar El Jeld (group)')->with('reimbursements')->firstOrFail();

        $this->assertSame(120_000, $dinner->amount);
        $this->assertSame(30_000, $dinner->netAmount());

        $bankBefore = $bank->balance;
        $cashBefore = $cash->balance;

        $drawer = Livewire::test(TransactionDrawer::class)
            ->call('open', $dinner->id)
            ->assertSee('Net cost')
            ->assertSet('amount', '120.000')
            ->set('refundAmount', '40')                // would exceed the 30 still outstanding
            ->call('addReimbursement')
            ->assertHasErrors('refundAmount')
            ->set('refundAmount', '10')
            ->set('refundWalletId', (string) $cash->id)
            ->call('addReimbursement')
            ->assertHasNoErrors();

        $this->assertSame($cashBefore + 10_000, $cash->fresh()->balance);
        $this->assertSame(20_000, $dinner->fresh()->load('reimbursements')->netAmount());

        $drawer->set('amount', '130')->call('save')->assertHasNoErrors()->assertSet('transactionId', null);
        $this->assertSame($bankBefore - 10_000, $bank->fresh()->balance);

        Livewire::test(TransactionDrawer::class)->call('open', $dinner->id)->call('delete');
        $this->assertNull(Transaction::find($dinner->id));
        $this->assertSame(0, Transaction::where('parent_transaction_id', $dinner->id)->count());
        $this->assertSame($bankBefore + 120_000 - 45_000, $bank->fresh()->balance); // expense back, its bank reimbursement gone
    }

    public function test_category_drawer_drills_down(): void
    {
        $food = Category::where('name', 'Food & Dining')->firstOrFail();

        Livewire::test(CategoryDrawer::class)
            ->call('open', $food->id)
            ->assertSee('Last 6 months')->assertSee('Groceries')->assertSee('Latest activity')
            ->call('close')->assertDontSee('Latest activity');
    }

    public function test_ledger_filters_totals_bulk_actions_and_export(): void
    {
        $ledger = Livewire::test(Ledger::class)
            ->set('period', 'all')
            ->set('search', 'Meta Ads')
            ->assertSee('Meta Ads')->assertDontSee('Carrefour Market')
            ->set('search', '')
            ->set('type', 'transfer')
            ->assertSee('ATM withdrawal')->assertDontSee('Meta Ads');

        $ids = Transaction::where('description', 'Bolt ride')->limit(3)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $shopping = Category::where('name', 'Shopping')->firstOrFail();

        $ledger->set('type', '')->set('selected', $ids)->set('bulkCategoryId', (string) $shopping->id)->call('bulkCategorize')->assertSet('selected', []);
        $this->assertSame(count($ids), Transaction::whereIn('id', $ids)->where('category_id', $shopping->id)->count());

        $cash = $this->wallet('Cash');
        $before = $cash->balance;
        $sum = (int) Transaction::whereIn('id', $ids)->sum('amount');
        $ledger->set('selected', $ids)->call('bulkDelete');
        $this->assertSame($before + $sum, $cash->fresh()->balance);

        $ledger->call('export')->assertFileDownloaded();
    }

    public function test_csv_import_applies_rules_and_updates_balances(): void
    {
        $bank = $this->wallet('BIAT Checking');
        $payoneer = $this->wallet('Payoneer USD');
        $before = [$bank->balance, $payoneer->balance];

        $csv = implode("\n", [
            'date,description,amount,category',
            '2026-03-01,Facture STEG mars,-88.500,',
            '2026-03-02,Meta Ads campaign,-25.00,',
            '2026-03-03,Client refund,150,Other Income',
            '2026-03-04,Broken row,not-a-number,',
        ]);

        Livewire::test(Ledger::class)
            ->call('openImport')
            ->set('importWalletId', (string) $bank->id)
            ->set('csv', UploadedFile::fake()->createWithContent('bank.csv', $csv))
            ->call('import')
            ->assertHasNoErrors()
            ->assertSet('importSummary', '3 imported · 2 auto-categorised by rules · 1 skipped');

        $this->assertSame($before[0] - 88_500 + 150_000, $bank->fresh()->balance);
        $this->assertSame($before[1] - 2_500, $payoneer->fresh()->balance);            // rule routed it to the USD wallet
        $this->assertSame('Utilities', Transaction::where('description', 'Facture STEG mars')->first()->category->name);
        $this->assertSame('Advertising', Transaction::where('description', 'Meta Ads campaign')->first()->category->name);
    }

    public function test_dashboard_mark_as_paid_posts_and_advances(): void
    {
        $rule = RecurringRule::where('name', 'Topnet fibre')->firstOrFail();
        $bank = $this->wallet('BIAT Checking');
        $before = $bank->balance;
        $due = $rule->next_due_date;

        Livewire::test(Dashboard::class)->assertSee('in 3d')->call('markPaid', $rule->id)->assertDontSee('in 3d');

        $this->assertSame($before - 45_000, $bank->fresh()->balance);
        $this->assertTrue($rule->fresh()->next_due_date->equalTo($due->addMonthNoOverflow()));
    }

    public function test_budgets_and_sinking_funds_can_be_managed(): void
    {
        $personal = Workspace::first();
        $insurance = Category::where('name', 'Insurance')->firstOrFail();

        Livewire::test(Budgets::class)
            ->call('newBudget')
            ->set('workspaceId', (string) $personal->id)->set('categoryId', (string) $insurance->id)->set('amount', '75.5')->set('rollover', true)
            ->call('saveBudget')->assertHasNoErrors()->assertSet('form', '')
            ->call('newBudget')
            ->set('workspaceId', (string) $personal->id)->set('categoryId', (string) $insurance->id)->set('amount', '10')
            ->call('saveBudget')->assertHasErrors('categoryId');   // one budget per category per workspace

        $this->assertSame(75_500, Budget::where('category_id', $insurance->id)->value('amount'));

        $fund = SinkingFund::where('name', 'Annual Car Maintenance')->firstOrFail();
        $saved = $fund->saved_amount;

        Livewire::test(Budgets::class)
            ->call('startContribution', $fund->id)
            ->set('contribution', '120')->call('contribute')->assertHasNoErrors()->assertSet('contributingId', null);
        $this->assertSame($saved + 120_000, $fund->fresh()->saved_amount);

        Livewire::test(Budgets::class)
            ->call('newFund')->set('name', 'Holiday')->set('targetAmount', '2400')->set('targetDate', today()->addMonths(11)->toDateString())
            ->call('saveFund')->assertHasNoErrors();
        $this->assertSame(200_000, SinkingFund::where('name', 'Holiday')->firstOrFail()->load('contributions')->monthlyAllocation());
    }

    public function test_debts_page_settles_and_manages_recurring_rules(): void
    {
        $cash = $this->wallet('Cash');
        $debt = Debt::where('contact_name', 'Sami K.')->firstOrFail();
        $before = $cash->balance;

        $page = Livewire::test(Debts::class)
            ->set('settleWalletId', (string) $cash->id)
            ->call('startPayment', $debt->id)->set('payAmount', '50')->call('pay')->assertHasNoErrors()
            ->call('settle', $debt->id);

        $this->assertTrue($debt->fresh()->isSettled());
        $this->assertSame($before + 150_000, $cash->fresh()->balance);

        $page->call('newRule')
            ->set('ruleName', 'Domain renewal')->set('ruleAmount', '35')->set('ruleFrequency', 'yearly')
            ->set('ruleWalletId', (string) $cash->id)->set('ruleNextDue', today()->addDays(4)->toDateString())
            ->call('saveRule')->assertHasNoErrors()
            ->assertSee('Domain renewal')
            ->call('shiftMonth', 1)->call('shiftMonth', -2)->assertOk();

        $rule = RecurringRule::where('name', 'Domain renewal')->firstOrFail();
        $page->call('markPaid', $rule->id);
        $this->assertSame($before + 150_000 - 35_000, $cash->fresh()->balance);
        $this->assertTrue($rule->fresh()->next_due_date->equalTo($rule->next_due_date->addYearNoOverflow()));
    }

    public function test_settings_manage_wallets_rules_and_categories(): void
    {
        $personal = Workspace::first();

        Livewire::test(Settings::class)
            ->call('newWallet')
            ->set('workspaceId', (string) $personal->id)->set('walletName', 'Revolut EUR')->set('walletType', 'e_wallet')
            ->set('currency', 'EUR')->set('openingBalance', '125.50')
            ->call('saveWallet')->assertHasNoErrors()
            ->set('tab', 'categories')
            ->set('keyword', 'Pharmacie')->set('ruleCategoryId', (string) Category::where('name', 'Health')->value('id'))
            ->call('addRule')->assertHasNoErrors()->assertSee('Pharmacie')
            ->call('newCategory')->set('categoryName', 'Pets')->set('categoryIcon', 'gift')->call('saveCategory')->assertHasNoErrors()->assertSee('Pets');

        $wallet = $this->wallet('Revolut EUR');
        $this->assertSame(12_550, $wallet->balance);
        $this->assertSame(424_190, $wallet->baseBalance());   // €125.50 × 3.38 (seeded EUR rate) = 424.190 TND

        Livewire::test(Settings::class)->call('toggleNetWorth', $wallet->id)->call('toggleArchive', $wallet->id);
        $this->assertFalse($wallet->fresh()->is_included_in_net_worth);
        $this->assertTrue($wallet->fresh()->is_archived);
    }
}
