<p align="center"><img src="public/icon-192.png" width="96" alt="Finflow"></p>

<h1 align="center">Finflow</h1>

<p align="center">A dark, keyboard-first personal finance and expense tracker built with Laravel 12, Livewire 3 and Tailwind CSS.</p>

---

## Features

- **Dashboard**: net worth, *safe to spend today*, monthly cash flow with savings rate, burn rate, a 30-day cash-flow chart and a category breakdown.
- **Quick entry**: press `N` or `Ctrl/Cmd + K` and log an expense, income or transfer from the keyboard. The amount field accepts sums like `12.5+4*2`.
- **Multi-wallet ledger**: cash, bank accounts, credit cards, savings pots and e-wallets, in any currency.
- **Atomic money engine**: every balance change runs in a database transaction with row locks. Amounts are stored as integers in minor units, never floats.
- **Transfers** move money between wallets without inflating income or expense totals.
- **Split bills and refunds**: link a reimbursement to the original expense so the category shows only your real cost.
- **Budgets** with rollover and pace tracking, plus **sinking funds** with automatic monthly allocation.
- **Recurring bills**: a calendar view and one-click *mark as paid*.
- **Debts and IOUs**: track money lent and borrowed, with partial payments and settlement through a wallet.
- **Auto-categorisation rules**, applied in quick entry and CSV import.
- **Workspaces** keep personal and business money separate.
- **Editable currencies**: add any currency with its own decimals and exchange rate. The base currency defaults to TND.

## Requirements

PHP 8.3+, Composer, Node 20+, and MySQL 8 (or SQLite for local tinkering).

## Getting started

```bash
git clone https://github.com/ThamerAyachi/finflow.git
cd finflow
composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate
# set DB_* in .env, then:
php artisan migrate

# create your login
php artisan user:create you@example.com

php artisan serve
```

Optional demo data (sample wallets and 60 days of transactions):

```bash
php artisan db:seed
```

The seeder also creates the login `admin@money-traker.test` / `password`. Use it only locally.

## Recurring bills

Rules marked *auto-post* are posted by `php artisan recurring:post-due`, which is scheduled daily. In production, add Laravel's scheduler to cron:

```
* * * * * cd /path/to/finflow && php artisan schedule:run >> /dev/null 2>&1
```

## Tests

```bash
php artisan test
```

The suite covers the balance math (transfers, refunds, debts, budgets, safe-to-spend), the UI flows, authentication and settings.
