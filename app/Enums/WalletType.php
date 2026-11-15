<?php

declare(strict_types=1);

namespace App\Enums;

enum WalletType: string
{
    case Cash = 'cash';
    case BankAccount = 'bank_account';
    case CreditCard = 'credit_card';
    case SavingsPot = 'savings_pot';
    case EWallet = 'e_wallet';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankAccount => 'Bank account',
            self::CreditCard => 'Credit card',
            self::SavingsPot => 'Savings pot',
            self::EWallet => 'E-wallet',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cash => 'banknote',
            self::BankAccount => 'landmark',
            self::CreditCard => 'credit-card',
            self::SavingsPot => 'piggy-bank',
            self::EWallet => 'smartphone',
        };
    }

    /**
     * Counts toward "available liquid cash". Credit cards are included so an
     * outstanding (negative) card balance reduces what is safe to spend.
     */
    public function isLiquid(): bool
    {
        return $this !== self::SavingsPot;
    }
}
