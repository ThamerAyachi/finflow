<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\RuleEngine;
use App\Support\CurrencyRegistry;
use App\Support\WorkspaceScope;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(WorkspaceScope::class);
        $this->app->scoped(RuleEngine::class);
        $this->app->scoped(CurrencyRegistry::class);
    }

    public function boot(): void
    {
        //
    }
}
