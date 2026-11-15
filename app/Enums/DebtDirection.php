<?php

declare(strict_types=1);

namespace App\Enums;

enum DebtDirection: string
{
    /** Money you lent: someone owes you. */
    case Receivable = 'receivable';

    /** Money you borrowed: you owe someone. */
    case Payable = 'payable';

    public function label(): string
    {
        return $this === self::Receivable ? 'Owed to you' : 'You owe';
    }
}
