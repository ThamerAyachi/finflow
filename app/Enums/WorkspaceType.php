<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkspaceType: string
{
    case Personal = 'personal';
    case Business = 'business';

    public function icon(): string
    {
        return $this === self::Personal ? 'user' : 'store';
    }
}
