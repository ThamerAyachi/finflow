<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Settings;
use App\Models\Category;
use App\Models\Currency;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\StarterCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Starts from an empty database (only migrations), like a fresh install. */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAs(User::factory()->create());
    }

    public function test_migration_provides_default_currencies_with_tnd_as_base(): void
    {
        $this->assertSame(['EUR', 'TND', 'USD'], Currency::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame('TND', Currency::base()->code);
        $this->assertSame(3, Currency::base()->decimals);
    }

    public function test_every_page_renders_on_an_empty_database(): void
    {
        foreach (['/', '/transactions', '/budgets', '/debts', '/settings', '/settings?tab=currencies', '/settings?tab=workspaces', '/settings?tab=categories'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /** The reported crash: "New wallet" with no workspaces threw ItemNotFoundException. */
    public function test_new_wallet_on_an_empty_database_creates_a_default_workspace(): void
    {
        $this->assertSame(0, Workspace::count());

        Livewire::test(Settings::class)
            ->call('newWallet')
            ->assertSet('form', 'wallet')
            ->assertSet('currency', 'TND')
            ->set('walletName', 'Cash')->set('walletType', 'cash')->set('openingBalance', '250.5')
            ->call('saveWallet')->assertHasNoErrors();

        $this->assertSame(['Personal'], Workspace::pluck('name')->all());
        $this->assertSame(250_500, Wallet::firstOrFail()->balance);
    }

    public function test_currency_can_be_added_and_used_by_a_wallet(): void
    {
        Livewire::test(Settings::class)
            ->set('tab', 'currencies')
            ->call('newCurrency')
            ->set('currencyCode', 'gbp')->set('currencyName', 'British Pound')->set('currencySymbol', '£')
            ->set('currencySymbolFirst', true)->set('currencyDecimals', '2')->set('currencyRate', '3.95')
            ->call('saveCurrency')->assertHasNoErrors()
            ->assertSee('British Pound');

        $gbp = Currency::query()->findOrFail('GBP');
        $this->assertSame(3.95, $gbp->exchange_rate);
        $this->assertSame('£1,234.50', Money::format(123_450, 'GBP'));
        $this->assertSame(39_500, Money::toBase(1_000, 'GBP')); // £10.00 → 39.500 TND

        Livewire::test(Settings::class)
            ->call('newWallet')->set('walletName', 'Monzo')->set('currency', 'GBP')->set('openingBalance', '100')
            ->call('saveWallet')->assertHasNoErrors();

        $this->assertSame(395_000, Wallet::where('name', 'Monzo')->firstOrFail()->baseBalance());
    }

    public function test_currency_validation(): void
    {
        Livewire::test(Settings::class)
            ->call('newCurrency')
            ->set('currencyCode', 'TND')->set('currencyName', 'Dup')->set('currencySymbol', 'x')
            ->call('saveCurrency')->assertHasErrors(['currencyCode' => 'unique'])
            ->set('currencyCode', 'POUND')
            ->call('saveCurrency')->assertHasErrors('currencyCode')
            ->set('currencyCode', 'MAD')->set('currencyRate', '0')
            ->call('saveCurrency')->assertHasErrors('currencyRate');
    }

    public function test_changing_a_rate_revalues_wallets_but_not_recorded_transactions(): void
    {
        $ws = Workspace::create(['name' => 'Personal', 'type' => 'personal']);
        $usd = Wallet::create(['workspace_id' => $ws->id, 'name' => 'USD', 'type' => 'e_wallet', 'currency' => 'USD', 'initial_balance' => 10_000, 'balance' => 10_000]);
        $expense = app(TransactionService::class)->record(['wallet_id' => $usd->id, 'type' => 'expense', 'amount' => 1_000]); // $10 at 3.12

        $this->assertSame(31_200, $expense->base_amount);

        Livewire::test(Settings::class)
            ->call('editCurrency', 'USD')->assertSet('currencyRate', '3.12')
            ->set('currencyRate', '3.5')->call('saveCurrency')->assertHasNoErrors();

        $this->assertSame(315_000, $usd->fresh()->baseBalance());     // $90 × 3.5
        $this->assertSame(31_200, $expense->fresh()->base_amount);    // history keeps its rate
    }

    public function test_decimals_and_deletion_are_locked_while_wallets_use_a_currency(): void
    {
        $ws = Workspace::create(['name' => 'Personal', 'type' => 'personal']);
        Wallet::create(['workspace_id' => $ws->id, 'name' => 'USD', 'type' => 'e_wallet', 'currency' => 'USD', 'initial_balance' => 0, 'balance' => 0]);

        Livewire::test(Settings::class)
            ->call('editCurrency', 'USD')->set('currencyDecimals', '3')
            ->call('saveCurrency')->assertHasErrors('currencyDecimals')
            ->call('deleteCurrency')->assertHasErrors('currencyCode');

        $this->assertSame(2, Currency::query()->findOrFail('USD')->decimals);

        // Unused, non-base currencies can go.
        Livewire::test(Settings::class)->call('editCurrency', 'EUR')->call('deleteCurrency')->assertHasNoErrors();
        $this->assertNull(Currency::query()->find('EUR'));

        // The base currency is never deletable.
        Livewire::test(Settings::class)->call('editCurrency', 'TND')->call('deleteCurrency')->assertHasErrors('currencyCode');
    }

    public function test_base_currency_can_change_before_any_money_is_recorded(): void
    {
        Livewire::test(Settings::class)->call('makeBase', 'USD')->assertRedirect();

        $this->assertSame('USD', Currency::base()->code);
        $this->assertSame(1.0, Currency::query()->findOrFail('USD')->exchange_rate);
        // 1 TND was 1/3.12 USD; EUR keeps its real value too (3.38 / 3.12).
        $this->assertEqualsWithDelta(1 / 3.12, Currency::query()->findOrFail('TND')->exchange_rate, 0.000001);
        $this->assertEqualsWithDelta(3.38 / 3.12, Currency::query()->findOrFail('EUR')->exchange_rate, 0.000001);
        $this->assertFalse(Currency::query()->findOrFail('TND')->is_base);
    }

    public function test_base_currency_is_locked_once_money_is_recorded(): void
    {
        $ws = Workspace::create(['name' => 'Personal', 'type' => 'personal']);
        $cash = Wallet::create(['workspace_id' => $ws->id, 'name' => 'Cash', 'type' => 'cash', 'currency' => 'TND', 'initial_balance' => 0, 'balance' => 0]);
        app(TransactionService::class)->record(['wallet_id' => $cash->id, 'type' => 'income', 'amount' => 5_000]);

        Livewire::test(Settings::class)->set('tab', 'currencies')->assertSee('base currency is locked')
            ->call('makeBase', 'USD')->assertNoRedirect();

        $this->assertSame('TND', Currency::base()->code);
    }

    public function test_workspaces_can_be_added_renamed_and_removed_safely(): void
    {
        Livewire::test(Settings::class)
            ->call('newWorkspace')->set('workspaceName', 'Personal')->call('saveWorkspace')->assertHasNoErrors()
            ->call('newWorkspace')->set('workspaceName', 'Etsy shop')->set('workspaceType', 'business')->set('workspaceColor', '#3B82F6')
            ->call('saveWorkspace')->assertHasNoErrors()->assertDispatched('workspaces-changed');

        $shop = Workspace::where('name', 'Etsy shop')->firstOrFail();
        $personal = Workspace::where('name', 'Personal')->firstOrFail();
        Wallet::create(['workspace_id' => $shop->id, 'name' => 'Shop bank', 'type' => 'bank_account', 'currency' => 'TND', 'initial_balance' => 0, 'balance' => 0]);

        // In use → refused.
        Livewire::test(Settings::class)->call('editWorkspace', $shop->id)->call('deleteWorkspace')->assertHasErrors('workspaceName');

        Livewire::test(Settings::class)->call('editWorkspace', $shop->id)->set('workspaceName', 'Etsy Shop TN')->call('saveWorkspace');
        $this->assertSame('Etsy Shop TN', $shop->fresh()->name);

        // Empty → removed; the last one is always kept.
        Livewire::test(Settings::class)->call('editWorkspace', $personal->id)->call('deleteWorkspace')->assertHasNoErrors();
        $this->assertNull(Workspace::find($personal->id));

        Wallet::query()->delete();
        Livewire::test(Settings::class)->call('editWorkspace', $shop->id)->call('deleteWorkspace')->assertHasErrors('workspaceName');
        $this->assertSame(1, Workspace::count());
    }

    public function test_starter_categories_install_once(): void
    {
        Livewire::test(Settings::class)->set('tab', 'categories')
            ->assertSee('Add starter categories')
            ->call('installStarterCategories')
            ->assertSee('Groceries')->assertDontSee('Add starter categories');

        $count = Category::count();
        $this->assertGreaterThan(25, $count);

        StarterCategories::install(); // idempotent
        $this->assertSame($count, Category::count());
        $this->assertSame('Food & Dining', Category::where('name', 'Groceries')->firstOrFail()->parent->name);
    }
}
