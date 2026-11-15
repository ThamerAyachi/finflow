<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategoryType;
use App\Enums\TransactionType;
use App\Livewire\Concerns\ParsesMoney;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\TransactionService;
use App\Support\Money;
use DomainException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** Right-hand slide-over: edit a transaction and manage its split / refund history. */
class TransactionDrawer extends Component
{
    use ParsesMoney;

    public ?int $transactionId = null;

    public string $amount = '';

    public string $description = '';

    public string $categoryId = '';

    public string $walletId = '';

    public string $destinationWalletId = '';

    public string $occurredOn = '';

    public string $notes = '';

    public string $refundAmount = '';

    public string $refundWalletId = '';

    public string $refundDate = '';

    #[On('open-transaction')]
    public function open(int $id): void
    {
        $this->resetErrorBag();
        $this->transactionId = $id;
        unset($this->transaction);

        if (! $t = $this->transaction) {
            $this->transactionId = null;

            return;
        }

        $this->amount = Money::decimal($t->amount, $t->wallet->currency, thousands: false);
        $this->description = $t->description;
        $this->categoryId = (string) $t->category_id;
        $this->walletId = (string) $t->wallet_id;
        $this->destinationWalletId = (string) $t->destination_wallet_id;
        $this->occurredOn = $t->occurred_on->toDateString();
        $this->notes = (string) $t->notes;
        $this->refundAmount = '';
        $this->refundWalletId = (string) $t->wallet_id;
        $this->refundDate = today()->toDateString();
    }

    public function close(): void
    {
        $this->transactionId = null;
    }

    #[Computed]
    public function transaction(): ?Transaction
    {
        return $this->transactionId === null ? null : Transaction::query()
            ->with(['wallet', 'destinationWallet', 'category', 'parent.wallet', 'reimbursements.wallet', 'debtPayment.debt'])
            ->find($this->transactionId);
    }

    public function save(TransactionService $service): void
    {
        if (! $t = $this->transaction) {
            return;
        }

        $this->validate(['occurredOn' => 'required|date', 'description' => 'max:255', 'notes' => 'max:2000']);

        $wallet = Wallet::query()->find((int) $this->walletId) ?? $t->wallet;
        $amount = $this->parseAmount('amount', $wallet->currency);

        if ($amount === null) {
            return;
        }

        try {
            $service->update($t, [
                'wallet_id' => $wallet->id,
                'destination_wallet_id' => $t->type === TransactionType::Transfer ? (int) $this->destinationWalletId : null,
                'amount' => $amount,
                'category_id' => ((int) $this->categoryId) ?: null,
                'description' => $this->description,
                'notes' => $this->notes !== '' ? $this->notes : null,
                'occurred_on' => $this->occurredOn,
            ]);
        } catch (DomainException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->dispatch('transaction-saved');
        $this->toast('Transaction updated');
        $this->close();
    }

    public function addReimbursement(TransactionService $service): void
    {
        if (! $t = $this->transaction) {
            return;
        }

        $wallet = Wallet::query()->find((int) $this->refundWalletId) ?? $t->wallet;
        $amount = $this->parseAmount('refundAmount', $wallet->currency);

        if ($amount === null) {
            return;
        }

        try {
            $service->reimburse($t, $wallet->id, $amount, $this->refundDate ?: today());
        } catch (DomainException $e) {
            $this->addError('refundAmount', $e->getMessage());

            return;
        }

        $this->refundAmount = '';
        unset($this->transaction);
        $this->dispatch('transaction-saved');
        $this->toast('Reimbursement linked');
    }

    public function removeReimbursement(int $id, TransactionService $service): void
    {
        $child = $this->transaction?->reimbursements->firstWhere('id', $id);

        if ($child) {
            $service->delete($child);
            unset($this->transaction);
            $this->dispatch('transaction-saved');
        }
    }

    public function delete(TransactionService $service): void
    {
        if ($t = $this->transaction) {
            $service->delete($t);
            $this->dispatch('transaction-saved');
            $this->toast('Transaction deleted');
        }

        $this->close();
    }

    public function render(): View
    {
        $t = $this->transaction;

        return view('livewire.transaction-drawer', [
            't' => $t,
            'wallets' => $t ? Wallet::query()->active()->orWhere('id', $t->wallet_id)->orderBy('workspace_id')->orderBy('id')->get() : collect(),
            'categories' => $t && $t->type !== TransactionType::Transfer
                ? Category::options($t->type === TransactionType::Income ? CategoryType::Income : CategoryType::Expense)
                : collect(),
        ]);
    }
}
