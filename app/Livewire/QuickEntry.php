<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategoryType;
use App\Enums\TransactionType;
use App\Livewire\Concerns\ParsesMoney;
use App\Models\Category;
use App\Models\Wallet;
use App\Services\RuleEngine;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\WorkspaceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Throwable;

/**
 * Global Ctrl/Cmd+K · N quick-entry modal.
 *
 * The form state lives entirely in Alpine (the markup is wire:ignore'd) so
 * opening, the calculator and type switching are instant. Livewire is used
 * as a thin RPC layer: suggest() for rule lookups and save() to persist.
 */
class QuickEntry extends Component
{
    use ParsesMoney;

    /**
     * Auto-categorise while typing: "STEG" → Utilities, paid from the bank account.
     *
     * @return array{keyword:string, categoryId:?int, categoryType:?string, categoryName:?string, walletId:?int}|null
     */
    #[Renderless]
    public function suggest(string $description, RuleEngine $rules): ?array
    {
        $rule = $rules->match($description);

        return $rule === null ? null : [
            'keyword' => $rule->keyword,
            'categoryId' => $rule->category_id,
            'categoryType' => $rule->category?->type->value,
            'categoryName' => $rule->category?->name,
            'walletId' => $rule->wallet_id,
        ];
    }

    /**
     * @param array{type?:string, amount?:string, walletId?:int|string, destinationWalletId?:int|string|null,
     *              categoryId?:int|string|null, description?:string, date?:string} $payload
     * @return array{ok:bool, error?:string}
     */
    #[Renderless]
    public function save(array $payload, TransactionService $service): array
    {
        $validator = Validator::make($payload, [
            'type' => 'required|in:expense,income,transfer',
            'amount' => 'required|string|max:30',
            'walletId' => 'required|integer|exists:wallets,id',
            'destinationWalletId' => 'required_if:type,transfer|nullable|integer|exists:wallets,id',
            'categoryId' => 'nullable|integer|exists:categories,id',
            'description' => 'nullable|string|max:255',
            'date' => 'required|date',
        ], [
            'destinationWalletId.required_if' => 'Choose the wallet to transfer to.',
        ], ['walletId' => 'wallet', 'destinationWalletId' => 'destination wallet', 'categoryId' => 'category']);

        if ($validator->fails()) {
            return ['ok' => false, 'error' => $validator->errors()->first()];
        }

        $data = $validator->validated();

        try {
            $type = TransactionType::from($data['type']);
            $wallet = Wallet::query()->findOrFail((int) $data['walletId']);
            $minor = Money::parse($data['amount'], $wallet->currency);
            $description = (string) ($data['description'] ?? '');

            $transaction = $type === TransactionType::Transfer
                ? $service->transfer($wallet->id, (int) $data['destinationWalletId'], $minor, null, $data['date'], $description)
                : $service->record([
                    'wallet_id' => $wallet->id,
                    'type' => $type,
                    'amount' => $minor,
                    'category_id' => ((int) ($data['categoryId'] ?? 0)) ?: null,
                    'description' => $description,
                    'occurred_on' => $data['date'],
                ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e instanceof \DomainException || $e instanceof \InvalidArgumentException
                ? $e->getMessage()
                : 'Could not save — check the wallets and amount.'];
        }

        $this->dispatch('transaction-saved');
        $this->toast($type->label().' saved · '.money($transaction->amount, $wallet->currency));

        return ['ok' => true];
    }

    public function render(): View
    {
        $scope = app(WorkspaceScope::class);
        $wallets = Wallet::query()->active()->with('workspace')->orderBy('workspace_id')->orderBy('id')->get();
        $inScope = $wallets->whereIn('workspace_id', $scope->ids());

        return view('livewire.quick-entry', [
            'wallets' => $wallets,
            'config' => [
                'today' => today()->toDateString(),
                'defaultWalletId' => $inScope->first()?->id ?? $wallets->first()?->id,
                'wallets' => $wallets->mapWithKeys(fn (Wallet $w) => [
                    $w->id => ['code' => $w->currency->code, 'decimals' => $w->currency->decimals()],
                ]),
            ],
            'expenseCategories' => Category::options(CategoryType::Expense),
            'incomeCategories' => Category::options(CategoryType::Income),
        ]);
    }
}
