<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum Frequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function advance(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $date->addWeek(),
            self::Monthly => $date->addMonthNoOverflow(),
            self::Yearly => $date->addYearNoOverflow(),
        };
    }

    public function rewind(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $date->subWeek(),
            self::Monthly => $date->subMonthNoOverflow(),
            self::Yearly => $date->subYearNoOverflow(),
        };
    }
}
