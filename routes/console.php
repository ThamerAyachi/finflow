<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\RecurringService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('recurring:post-due', function (RecurringService $recurring): void {
    $this->info("Posted {$recurring->postDue()} recurring transaction(s).");
})->purpose('Post due transactions for recurring rules with auto_post enabled');

Artisan::command('user:create {email} {--name=} {--password=}', function (): int {
    $password = $this->option('password') ?: $this->secret('Password (min 8 characters)');

    if (! filter_var($this->argument('email'), FILTER_VALIDATE_EMAIL) || mb_strlen((string) $password) < 8) {
        $this->error('Provide a valid email and a password of at least 8 characters.');

        return 1;
    }

    $user = User::query()->updateOrCreate(
        ['email' => $this->argument('email')],
        ['name' => $this->option('name') ?: Str::before($this->argument('email'), '@'), 'password' => Hash::make($password)],
    );

    $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." login for {$user->email}.");

    return 0;
})->purpose('Create a login, or reset the password of an existing one');

Schedule::command('recurring:post-due')->dailyAt('06:00');
