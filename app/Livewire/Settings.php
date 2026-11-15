<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\WalletType;
use App\Enums\WorkspaceType;
use App\Livewire\Concerns\ParsesMoney;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Debt;
use App\Models\SinkingFund;
use App\Models\Transaction;
use App\Models\TransactionRule;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Services\RuleEngine;
use App\Support\CurrencyRegistry;
use App\Support\StarterCategories;
use App\Support\WorkspaceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Settings')]
class Settings extends Component
{
    use ParsesMoney;

    public const TABS = ['wallets', 'currencies', 'workspaces', 'categories', 'account'];

    #[Url]
    public string $tab = 'wallets';

    /** '' | 'wallet' | 'currency' | 'workspace' | 'category' */
    public string $form = '';

    public ?int $editingId = null;

    // Wallet form
    public string $workspaceId = '';

    public string $walletName = '';

    public string $walletType = 'bank_account';

    public string $currency = '';

    public string $openingBalance = '0';

    public bool $inNetWorth = true;

    public string $walletColor = '#3B82F6';

    // Currency form
    public ?string $editingCode = null;

    public string $currencyCode = '';

    public string $currencyName = '';

    public string $currencySymbol = '';

    public bool $currencySymbolFirst = true;

    public string $currencyDecimals = '2';

    public string $currencyRate = '1';

    // Workspace form
    public string $workspaceName = '';

    public string $workspaceType = 'personal';

    public string $workspaceColor = '#22C55E';

    // Rule (inline)
    public string $keyword = '';

    public string $ruleCategoryId = '';

    public string $ruleWalletId = '';

    // Category form
    public string $categoryName = '';

    public string $categoryType = 'expense';

    public string $parentId = '';

    public string $categoryIcon = 'tag';

    public string $categoryColor = '#71717A';

    // Account
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public function mount(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'wallets';
        }
    }

    #[On('transaction-saved')]
    public function refresh(): void {}

    private function scope(): WorkspaceScope
    {
        return app(WorkspaceScope::class);
    }

    public function closeForm(): void
    {
        $this->form = '';
        $this->editingId = null;
        $this->editingCode = null;
        $this->resetErrorBag();
    }

    // ── Wallets ─────────────────────────────────────────────────────

    public function newWallet(): void
    {
        $this->reset('walletName', 'walletType', 'openingBalance', 'inNetWorth', 'walletColor', 'editingId');
        $this->resetErrorBag();
        $this->workspaceId = (string) $this->scope()->default()->id;
        $this->currency = Currency::base()->code;
        $this->form = 'wallet';
    }

    public function editWallet(int $id): void
    {
        $wallet = Wallet::query()->findOrFail($id);

        $this->resetErrorBag();
        $this->editingId = $wallet->id;
        $this->workspaceId = (string) $wallet->workspace_id;
        $this->walletName = $wallet->name;
        $this->walletType = $wallet->type->value;
        $this->currency = $wallet->currency->code;
        $this->inNetWorth = $wallet->is_included_in_net_worth;
        $this->walletColor = $wallet->color_hex;
        $this->form = 'wallet';
    }

    public function saveWallet(): void
    {
        $this->validate([
            'walletName' => 'required|string|max:60',
            'walletType' => ['required', Rule::enum(WalletType::class)],
            'currency' => 'required|exists:currencies,code',
            'workspaceId' => 'required|exists:workspaces,id',
            'walletColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], attributes: ['walletName' => 'name']);

        $data = [
            'name' => $this->walletName,
            'type' => $this->walletType,
            'is_included_in_net_worth' => $this->inNetWorth,
            'color_hex' => $this->walletColor,
        ];

        if ($this->editingId) {
            // Currency and workspace are fixed once a wallet exists: its history is denominated in them.
            Wallet::query()->findOrFail($this->editingId)->update($data);
        } else {
            if (($opening = $this->parseAmount('openingBalance', $this->currency, allowNegative: true)) === null) {
                return;
            }

            Wallet::create($data + [
                'workspace_id' => (int) $this->workspaceId,
                'currency' => $this->currency,
                'initial_balance' => $opening,
                'balance' => $opening,
            ]);
        }

        $this->toast('Wallet saved');
        $this->closeForm();
    }

    public function toggleNetWorth(int $id): void
    {
        $wallet = Wallet::query()->findOrFail($id);
        $wallet->update(['is_included_in_net_worth' => ! $wallet->is_included_in_net_worth]);
    }

    public function toggleArchive(int $id): void
    {
        $wallet = Wallet::query()->findOrFail($id);
        $wallet->update(['is_archived' => ! $wallet->is_archived]);
        $this->toast($wallet->is_archived ? 'Wallet archived' : 'Wallet restored');
    }

    // ── Currencies ──────────────────────────────────────────────────

    public function newCurrency(): void
    {
        $this->reset('currencyCode', 'currencyName', 'currencySymbol', 'currencySymbolFirst', 'currencyDecimals', 'currencyRate', 'editingCode');
        $this->resetErrorBag();
        $this->form = 'currency';
    }

    public function editCurrency(string $code): void
    {
        $currency = Currency::query()->findOrFail($code);

        $this->resetErrorBag();
        $this->editingCode = $currency->code;
        $this->currencyCode = $currency->code;
        $this->currencyName = $currency->name;
        $this->currencySymbol = $currency->symbol;
        $this->currencySymbolFirst = $currency->symbol_first;
        $this->currencyDecimals = (string) $currency->decimals;
        $this->currencyRate = rtrim(rtrim(number_format($currency->exchange_rate, 6, '.', ''), '0'), '.');
        $this->form = 'currency';
    }

    public function saveCurrency(): void
    {
        $this->currencyCode = strtoupper(trim($this->currencyCode));
        $existing = $this->editingCode ? Currency::query()->findOrFail($this->editingCode) : null;

        $this->validate([
            'currencyCode' => $existing ? [] : ['required', 'regex:/^[A-Z]{3}$/', 'unique:currencies,code'],
            'currencyName' => 'required|string|max:60',
            'currencySymbol' => 'required|string|max:8',
            'currencyDecimals' => 'required|integer|between:0,4',
            'currencyRate' => 'required|numeric|gt:0|max:1000000',
        ], [
            'currencyCode.regex' => 'Use the 3-letter ISO code, e.g. GBP or MAD.',
        ], [
            'currencyCode' => 'code', 'currencyName' => 'name', 'currencySymbol' => 'symbol',
            'currencyDecimals' => 'decimals', 'currencyRate' => 'exchange rate',
        ]);

        $decimals = (int) $this->currencyDecimals;

        // Stored amounts are minor units: changing decimals would silently rescale every balance.
        if ($existing && $existing->decimals !== $decimals && $existing->wallets()->exists()) {
            $this->addError('currencyDecimals', 'Decimals can’t change while wallets hold this currency.');

            return;
        }

        $isBase = $existing?->is_base ?? false;

        Currency::query()->updateOrCreate(['code' => $existing->code ?? $this->currencyCode], [
            'name' => trim($this->currencyName),
            'symbol' => trim($this->currencySymbol),
            'symbol_first' => $this->currencySymbolFirst,
            'decimals' => $decimals,
            'exchange_rate' => $isBase ? 1 : (float) $this->currencyRate,
            'is_base' => $isBase,
        ]);

        $this->toast('Currency saved');
        $this->closeForm();
    }

    public function deleteCurrency(): void
    {
        $currency = Currency::query()->findOrFail($this->editingCode);

        if ($currency->is_base || $currency->wallets()->exists()) {
            $this->addError('currencyCode', 'The base currency and currencies used by wallets can’t be removed.');

            return;
        }

        $currency->delete();
        $this->toast("{$currency->code} removed");
        $this->closeForm();
    }

    /** Budgets, debts, funds and every transaction's base amount are stored in the base currency. */
    public function baseCurrencyLocked(): bool
    {
        return Transaction::query()->exists() || Budget::query()->exists()
            || Debt::query()->exists() || SinkingFund::query()->exists();
    }

    /**
     * Make another currency the base. Allowed only before any money is recorded,
     * because stored base amounts would otherwise be in the old currency.
     * Rates are re-expressed so each currency keeps its real-world value.
     */
    public function makeBase(string $code): void
    {
        if ($this->baseCurrencyLocked()) {
            $this->toast('The base currency is locked once transactions, budgets, debts or funds exist.', 'error');

            return;
        }

        DB::transaction(function () use ($code): void {
            $new = Currency::query()->lockForUpdate()->findOrFail($code);
            $pivot = $new->exchange_rate > 0 ? $new->exchange_rate : 1.0;

            foreach (Currency::query()->lockForUpdate()->get() as $currency) {
                $currency->update([
                    'is_base' => $currency->code === $new->code,
                    'exchange_rate' => $currency->code === $new->code ? 1 : round($currency->exchange_rate / $pivot, 6),
                ]);
            }
        });

        app(CurrencyRegistry::class)->flush();
        $this->toast("{$code} is now the base currency");

        // The layout prints the base currency for charts; reload to pick it up.
        $this->redirect(route('settings', ['tab' => 'currencies']), navigate: true);
    }

    // ── Workspaces ──────────────────────────────────────────────────

    public function newWorkspace(): void
    {
        $this->reset('workspaceName', 'workspaceType', 'workspaceColor', 'editingId');
        $this->resetErrorBag();
        $this->form = 'workspace';
    }

    public function editWorkspace(int $id): void
    {
        $workspace = Workspace::query()->findOrFail($id);

        $this->resetErrorBag();
        $this->editingId = $workspace->id;
        $this->workspaceName = $workspace->name;
        $this->workspaceType = $workspace->type->value;
        $this->workspaceColor = $workspace->color_hex;
        $this->form = 'workspace';
    }

    public function saveWorkspace(): void
    {
        $this->validate([
            'workspaceName' => 'required|string|max:60',
            'workspaceType' => ['required', Rule::enum(WorkspaceType::class)],
            'workspaceColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], attributes: ['workspaceName' => 'name']);

        Workspace::query()->updateOrCreate(['id' => $this->editingId], [
            'name' => trim($this->workspaceName),
            'type' => $this->workspaceType,
            'color_hex' => $this->workspaceColor,
        ]);

        $this->scope()->flush();
        $this->toast('Workspace saved');
        $this->closeForm();
        $this->dispatch('workspaces-changed');
    }

    public function deleteWorkspace(): void
    {
        $workspace = Workspace::query()->findOrFail($this->editingId);

        if (Workspace::query()->count() <= 1) {
            $this->addError('workspaceName', 'You need at least one workspace.');

            return;
        }

        $inUse = $workspace->wallets()->exists()
            || Budget::query()->where('workspace_id', $workspace->id)->exists()
            || Debt::query()->where('workspace_id', $workspace->id)->exists()
            || SinkingFund::query()->where('workspace_id', $workspace->id)->exists();

        if ($inUse) {
            $this->addError('workspaceName', 'Move or remove its wallets, budgets, funds and debts first.');

            return;
        }

        $workspace->delete();
        $this->scope()->set('all');
        $this->scope()->flush();
        $this->toast("{$workspace->name} removed");
        $this->closeForm();
        $this->dispatch('workspaces-changed');
    }

    // ── Auto-categorisation rules ───────────────────────────────────

    public function addRule(RuleEngine $engine): void
    {
        $this->validate([
            'keyword' => 'required|string|min:2|max:60',
            'ruleCategoryId' => 'required|exists:categories,id',
        ], attributes: ['ruleCategoryId' => 'category']);

        TransactionRule::create([
            'keyword' => trim($this->keyword),
            'category_id' => (int) $this->ruleCategoryId,
            'wallet_id' => ((int) $this->ruleWalletId) ?: null,
        ]);

        $engine->flush();
        $this->reset('keyword', 'ruleCategoryId', 'ruleWalletId');
        $this->toast('Rule added');
    }

    public function toggleRule(int $id): void
    {
        $rule = TransactionRule::query()->findOrFail($id);
        $rule->update(['is_active' => ! $rule->is_active]);
    }

    public function deleteRule(int $id): void
    {
        TransactionRule::query()->whereKey($id)->delete();
    }

    // ── Categories ──────────────────────────────────────────────────

    public function installStarterCategories(): void
    {
        $before = Category::query()->count();
        StarterCategories::install();

        $this->toast((Category::query()->count() - $before).' categories added');
    }

    public function newCategory(): void
    {
        $this->reset('categoryName', 'categoryType', 'parentId', 'categoryIcon', 'categoryColor', 'editingId');
        $this->resetErrorBag();
        $this->form = 'category';
    }

    public function editCategory(int $id): void
    {
        $category = Category::query()->findOrFail($id);

        $this->resetErrorBag();
        $this->editingId = $category->id;
        $this->categoryName = $category->name;
        $this->categoryType = $category->type->value;
        $this->parentId = (string) $category->parent_id;
        $this->categoryIcon = $category->icon;
        $this->categoryColor = $category->color_hex;
        $this->form = 'category';
    }

    public function saveCategory(): void
    {
        $this->validate([
            'categoryName' => 'required|string|max:60',
            'categoryType' => 'required|in:expense,income',
            'categoryColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], attributes: ['categoryName' => 'name']);

        $parent = ((int) $this->parentId) ? Category::query()->find((int) $this->parentId) : null;

        if ($parent && ($parent->parent_id !== null || $parent->id === $this->editingId)) {
            $this->addError('parentId', 'Pick a top-level category as the parent.');

            return;
        }

        Category::query()->updateOrCreate(['id' => $this->editingId], [
            'name' => $this->categoryName,
            'type' => $parent ? $parent->type->value : $this->categoryType,
            'parent_id' => $parent?->id,
            'icon' => $this->categoryIcon,
            'color_hex' => $this->categoryColor,
        ]);

        $this->toast('Category saved');
        $this->closeForm();
    }

    public function deleteCategory(): void
    {
        // Transactions keep their history and fall back to "Uncategorised".
        Category::query()->whereKey($this->editingId)->delete();
        $this->toast('Category removed');
        $this->closeForm();
    }

    // ── Account ─────────────────────────────────────────────────────

    public function updatePassword(): void
    {
        $this->validate([
            'currentPassword' => 'required|current_password',
            'newPassword' => ['required', 'string', Password::min(8), 'same:newPasswordConfirmation'],
        ], [
            'newPassword.same' => 'The confirmation does not match.',
        ], ['currentPassword' => 'current password', 'newPassword' => 'new password']);

        Auth::user()->update(['password' => Hash::make($this->newPassword)]);

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        $this->toast('Password updated');
    }

    public function render(): View
    {
        $categories = Category::query()->with('children')->whereNull('parent_id')->orderBy('type')->orderBy('name')->get();
        $currencies = Currency::query()->withCount('wallets')->orderByDesc('is_base')->orderBy('code')->get();

        return view('livewire.settings', [
            'tabs' => [
                'wallets' => ['Wallets', 'wallet'],
                'currencies' => ['Currencies', 'circle-dollar-sign'],
                'workspaces' => ['Workspaces', 'layers'],
                'categories' => ['Categories & rules', 'tag'],
                'account' => ['Account', 'user'],
            ],
            'wallets' => Wallet::query()->forWorkspaces($this->scope()->ids())->with('workspace')->orderBy('is_archived')->orderBy('workspace_id')->orderBy('id')->get(),
            'rules' => TransactionRule::query()->with(['category', 'wallet'])->orderBy('keyword')->get(),
            'categories' => $categories,
            'categoryOptions' => Category::options(),
            'parents' => $categories,
            'workspaces' => Workspace::query()->withCount('wallets')->orderBy('id')->get(),
            'walletTypes' => WalletType::cases(),
            'workspaceTypes' => WorkspaceType::cases(),
            'currencies' => $currencies,
            'base' => Currency::base(),
            'baseLocked' => $this->tab === 'currencies' && $this->baseCurrencyLocked(),
            'editingCurrency' => $this->editingCode ? $currencies->firstWhere('code', $this->editingCode) : null,
            'icons' => ['tag', 'shopping-cart', 'utensils', 'coffee', 'fuel', 'car', 'bus', 'house', 'zap', 'wifi', 'shopping-bag', 'heart-pulse',
                'clapperboard', 'tv', 'music', 'dumbbell', 'plane', 'graduation-cap', 'gift', 'shield', 'briefcase', 'megaphone', 'package', 'truck',
                'laptop', 'box', 'store', 'users', 'droplets', 'code', 'receipt', 'circle-dollar-sign'],
        ]);
    }
}
