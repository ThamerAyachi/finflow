<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DebtDirection;
use App\Enums\TransactionType;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Wallet;
use App\Support\Money;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Debts are tracked in the base currency. Money moving because of a debt is
 * posted to a wallet with affects_reports = false: lending is not spending and
 * being repaid is not income.
 */
final class DebtService
{
    public function __construct(private readonly TransactionService $transactions) {}

    /**
     * @param array{workspace_id:int, contact_name:string, direction:DebtDirection|string, principal:int, due_date?:mixed, notes?:?string} $data
     * @param int|null $walletId When given, the principal is moved out of (lent) or into (borrowed) this wallet.
     */
    public function create(array $data, ?int $walletId = null, CarbonInterface|string|null $occurredOn = null): Debt
    {
        if ((int) $data['principal'] <= 0) {
            throw new DomainException('Amount must be greater than zero.');
        }

        return DB::transaction(function () use ($data, $walletId, $occurredOn): Debt {
            $debt = Debt::create($data);

            if ($walletId !== null) {
                $wallet = Wallet::query()->findOrFail($walletId);
                $lent = $debt->direction === DebtDirection::Receivable;

                $this->transactions->record([
                    'wallet_id' => $wallet->id,
                    'type' => $lent ? TransactionType::Expense : TransactionType::Income,
                    'amount' => Money::fromBase($debt->principal, $wallet->currency, $wallet->exchange_rate),
                    'description' => ($lent ? 'Lent to ' : 'Borrowed from ').$debt->contact_name,
                    'occurred_on' => $occurredOn ?? today(),
                    'affects_reports' => false,
                ]);
            }

            return $debt;
        });
    }

    /** Record a partial payment (amount in base currency) through a wallet. */
    public function recordPayment(Debt $debt, int $walletId, int $amount, CarbonInterface|string|null $paidOn = null): DebtPayment
    {
        return DB::transaction(function () use ($debt, $walletId, $amount, $paidOn): DebtPayment {
            /** @var Debt $debt */
            $debt = Debt::query()->lockForUpdate()->findOrFail($debt->id);

            if ($amount <= 0) {
                throw new DomainException('Amount must be greater than zero.');
            }
            if ($amount > $debt->remaining()) {
                throw new DomainException('Payment exceeds the outstanding balance.');
            }

            $wallet = Wallet::query()->findOrFail($walletId);
            $collecting = $debt->direction === DebtDirection::Receivable;

            $transaction = $this->transactions->record([
                'wallet_id' => $wallet->id,
                'type' => $collecting ? TransactionType::Income : TransactionType::Expense,
                'amount' => Money::fromBase($amount, $wallet->currency, $wallet->exchange_rate),
                'description' => ($collecting ? 'Repayment from ' : 'Repayment to ').$debt->contact_name,
                'occurred_on' => $paidOn ?? today(),
                'affects_reports' => false,
            ]);

            $debt->paid_amount += $amount;
            $debt->settled_at = $debt->remaining() === 0 ? now() : null;
            $debt->save();

            return $debt->payments()->create([
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'paid_on' => $transaction->occurred_on,
            ]);
        });
    }

    /** One-click settlement: pay whatever is still outstanding. */
    public function settle(Debt $debt, int $walletId): ?DebtPayment
    {
        $remaining = $debt->fresh()->remaining();

        return $remaining > 0 ? $this->recordPayment($debt, $walletId, $remaining) : null;
    }
}
