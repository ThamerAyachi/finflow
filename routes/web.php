<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Livewire\Budgets;
use App\Livewire\Dashboard;
use App\Livewire\Debts;
use App\Livewire\Ledger;
use App\Livewire\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/login', Login::class)->middleware('guest')->name('login');

Route::middleware('auth')->group(function (): void {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/transactions', Ledger::class)->name('transactions');
    Route::get('/budgets', Budgets::class)->name('budgets');
    Route::get('/debts', Debts::class)->name('debts');
    Route::get('/settings', Settings::class)->name('settings');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
