<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['type' => WorkspaceType::class];
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
