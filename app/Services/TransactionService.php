<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Debt;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Support\Money;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place wallet balances are mutated.
 *
 * Every operation runs inside a DB transaction and takes row locks
 * (SELECT … FOR UPDATE) on the affected wallets, always in ascending id
 * order so concurrent transfers in opposite directions cannot deadlock.
 */
final class TransactionService
{
    /**
     * Record an expense or income.
     *
     * @param array{
     *   wallet_id:int, type:TransactionType|string, amount:int, category_id?:?int,
     *   description?:string, notes?:?string, occurred_on?:CarbonInterface|string|null,
     *   parent_transaction_id?:?int, recurring_rule_id?:?int, affects_reports?:bool
     * } $data
     */
    public function record(array $data): Transaction
    {
        $type = $this->type($data['type']);

        if ($type === TransactionType::Transfer) {
            throw new DomainException('Use transfer() to move money between wallets.');
        }

        $amount = $this->positive((int) $data['amount']);

        return DB::transaction(function () use ($data, $type, $amount): Transaction {
            $wallets = $this->lockWallets([(int) $data['wallet_id']]);
            $wallet = $wallets[(int) $data['wallet_id']];

            $transaction = new Transaction([
                'workspace_id' => $wallet->workspace_id,
                'wallet_id' => $wallet->id,
                'category_id' => $data['category_id'] ?? null,
                'parent_transaction_id' => $data['parent_transaction_id'] ?? null,
                'recurring_rule_id' => $data['recurring_rule_id'] ?? null,
                'type' => $type,
                'amount' => $amount,
                'base_amount' => Money::toBase($amount, $wallet->currency, $wallet->exchange_rate),
                'affects_reports' => $data['affects_reports'] ?? true,
                'description' => trim((string) ($data['description'] ?? '')),
                'notes' => $data['notes'] ?? null,
                'occurred_on' => $data['occurred_on'] ?? today(),
            ]);

            $this->apply($transaction, $wallets, 1);
            $transaction->save();

            return $transaction;
        });
    }

    /**
     * Move money between two wallets. Neither income nor expense totals change.
     * For cross-currency transfers pass $destinationAmount (what actually
     * arrived) or let it be derived from the wallets' exchange rates.
     */
    public function transfer(
        int $fromWalletId,
        int $toWalletId,
        int $amount,
        ?int $destinationAmount = null,
        CarbonInterface|string|null $occurredOn = null,
        string $description = '',
        ?string $notes = null,
    ): Transaction {
        if ($fromWalletId === $toWalletId) {
            throw new DomainException('Source and destination wallets must differ.');
        }

        $amount = $this->positive($amount);

        return DB::transaction(function () use ($fromWalletId, $toWalletId, $amount, $destinationAmount, $occurredOn, $description, $notes): Transaction {
            $wallets = $this->lockWallets([$fromWalletId, $toWalletId]);
            $from = $wallets[$fromWalletId];
            $to = $wallets[$toWalletId];

            $transaction = new Transaction([
                'workspace_id' => $from->workspace_id,
                'wallet_id' => $from->id,
                'destination_wallet_id' => $to->id,
                'type' => TransactionType::Transfer,
                'amount' => $amount,
                'destination_amount' => $this->positive($destinationAmount ?? $this->convertBetween($amount, $from, $to)),
                'base_amount' => Money::toBase($amount, $from->currency, $from->exchange_rate),
                'description' => trim($description) !== '' ? trim($description) : "{$from->name} → {$to->name}",
                'notes' => $notes,
                'occurred_on' => $occurredOn ?? today(),
            ]);

            $this->apply($transaction, $wallets, 1);
            $transaction->save();

            return $transaction;
        });
    }

    /**
     * Record money received back against an expense (split bill, refund).
     * Stored as an income row linked via parent_transaction_id; reports net
     * it against the original category instead of counting it as income.
     */
    public function reimburse(
        Transaction $original,
        int $walletId,
        int $amount,
        CarbonInterface|string|null $occurredOn = null,
        string $description = '',
    ): Transaction {
        return DB::transaction(function () use ($original, $walletId, $amount, $occurredOn, $description): Transaction {
            /** @var Transaction $original */
            $original = Transaction::query()->lockForUpdate()->findOrFail($original->id);

            if ($original->type !== TransactionType::Expense || $original->isReimbursement()) {
                throw new DomainException('Only an original expense can be reimbursed.');
            }

            $wallet = Wallet::query()->findOrFail($walletId);
            $incomingBase = Money::toBase($this->positive($amount), $wallet->currency, $wallet->exchange_rate);
            $alreadyBack = (int) $original->reimbursements()->sum('base_amount');

            if ($alreadyBack + $incomingBase > $original->base_amount) {
                throw new DomainException('Reimbursements cannot exceed the original expense.');
            }

            return $this->record([
                'wallet_id' => $walletId,
                'type' => TransactionType::Income,
                'amount' => $amount,
                'category_id' => $original->category_id,
                'parent_transaction_id' => $original->id,
                'description' => trim($description) !== '' ? trim($description) : 'Reimbursement · '.$original->description,
                'occurred_on' => $occurredOn ?? today(),
            ]);
        });
    }

    /**
     * Edit a transaction: reverse its old effect, apply the new one, atomically.
     *
     * @param array<string, mixed> $data wallet_id, destination_wallet_id, amount,
     *                                   destination_amount, category_id, description, notes, occurred_on
     */
    public function update(Transaction $transaction, array $data): Transaction
    {
        return DB::transaction(function () use ($transaction, $data): Transaction {
            /** @var Transaction $transaction */
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            // Debt-linked rows mirror a debt payment; only their metadata may change.
            if ($transaction->debtPayment()->exists()) {
                $data = array_intersect_key($data, array_flip(['description', 'notes', 'occurred_on']));
            }

            $walletIds = array_filter([
                $transaction->wallet_id,
                $transaction->destination_wallet_id,
                isset($data['wallet_id']) ? (int) $data['wallet_id'] : null,
                isset($data['destination_wallet_id']) ? (int) $data['destination_wallet_id'] : null,
            ]);
            $wallets = $this->lockWallets($walletIds);

            $this->apply($transaction, $wallets, -1);

            $currencyChanged = false;
            if (isset($data['wallet_id']) && (int) $data['wallet_id'] !== $transaction->wallet_id) {
                $currencyChanged = true;
                $transaction->wallet_id = (int) $data['wallet_id'];
                $transaction->workspace_id = $wallets[$transaction->wallet_id]->workspace_id;
            }
            if (isset($data['amount'])) {
                $transaction->amount = $this->positive((int) $data['amount']);
            }

            $source = $wallets[$transaction->wallet_id];
            $transaction->base_amount = Money::toBase($transaction->amount, $source->currency, $source->exchange_rate);

            if ($transaction->type === TransactionType::Transfer) {
                if (isset($data['destination_wallet_id'])) {
                    $currencyChanged = $currencyChanged || (int) $data['destination_wallet_id'] !== $transaction->destination_wallet_id;
                    $transaction->destination_wallet_id = (int) $data['destination_wallet_id'];
                }
                if ($transaction->destination_wallet_id === $transaction->wallet_id) {
                    throw new DomainException('Source and destination wallets must differ.');
                }
                $destination = $wallets[$transaction->destination_wallet_id];
                $transaction->destination_amount = isset($data['destination_amount'])
                    ? $this->positive((int) $data['destination_amount'])
                    : (($currencyChanged || isset($data['amount']))
                        ? $this->convertBetween($transaction->amount, $source, $destination)
                        : $transaction->destination_amount);
            } elseif (array_key_exists('category_id', $data)) {
                $transaction->category_id = $data['category_id'] ?: null;
            }

            foreach (['description', 'notes', 'occurred_on'] as $field) {
                if (array_key_exists($field, $data)) {
                    $transaction->{$field} = $data[$field];
                }
            }

            if ($transaction->type === TransactionType::Expense
                && (int) $transaction->reimbursements()->sum('base_amount') > $transaction->base_amount) {
                throw new DomainException('Amount cannot be lower than what was already reimbursed.');
            }

            $this->apply($transaction, $wallets, 1);
            $transaction->save();

            // Reimbursements always live in the category of the expense they offset.
            $transaction->reimbursements()->update(['category_id' => $transaction->category_id]);

            return $transaction;
        });
    }

    /** Delete a transaction (and its reimbursements), restoring every balance. */
    public function delete(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            /** @var Transaction|null $transaction */
            $transaction = Transaction::query()->lockForUpdate()->find($transaction->id);

            if ($transaction === null) {
                return;
            }

            foreach ($transaction->reimbursements()->get() as $child) {
                $this->delete($child);
            }

            $wallets = $this->lockWallets(array_filter([$transaction->wallet_id, $transaction->destination_wallet_id]));
            $this->apply($transaction, $wallets, -1);

            if ($payment = $transaction->debtPayment) {
                /** @var Debt $debt */
                $debt = Debt::query()->lockForUpdate()->findOrFail($payment->debt_id);
                $debt->paid_amount = max(0, $debt->paid_amount - $payment->amount);
                $debt->settled_at = $debt->remaining() === 0 ? $debt->settled_at : null;
                $debt->save();
                $payment->delete();
            }

            $transaction->delete();
        });
    }

    /** Amount that lands in $to when $amount leaves $from, via the base currency. */
    public function convertBetween(int $amount, Wallet $from, Wallet $to): int
    {
        if ($from->currency->code === $to->currency->code) {
            return $amount;
        }

        return Money::fromBase(Money::toBase($amount, $from->currency, $from->exchange_rate), $to->currency, $to->exchange_rate);
    }

    /**
     * @param array<int, int> $ids
     * @return Collection<int, Wallet> keyed by id
     */
    private function lockWallets(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $wallets = Wallet::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($wallets->count() !== count($ids)) {
            throw new DomainException('Wallet not found.');
        }

        return $wallets;
    }

    /**
     * Apply (+1) or reverse (-1) a transaction's effect on the locked wallets.
     *
     * @param Collection<int, Wallet> $wallets
     */
    private function apply(Transaction $transaction, Collection $wallets, int $sign): void
    {
        $source = $wallets[$transaction->wallet_id];

        match ($transaction->type) {
            TransactionType::Income => $source->balance += $sign * $transaction->amount,
            TransactionType::Expense => $source->balance -= $sign * $transaction->amount,
            TransactionType::Transfer => (function () use ($transaction, $wallets, $source, $sign): void {
                $source->balance -= $sign * $transaction->amount;
                $wallets[$transaction->destination_wallet_id]->balance += $sign * (int) $transaction->destination_amount;
                $wallets[$transaction->destination_wallet_id]->save();
            })(),
        };

        $source->save();
    }

    private function type(TransactionType|string $type): TransactionType
    {
        return $type instanceof TransactionType ? $type : TransactionType::from($type);
    }

    private function positive(int $amount): int
    {
        if ($amount <= 0) {
            throw new DomainException('Amount must be greater than zero.');
        }

        return $amount;
    }
}
