<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Currencies become editable data instead of a hardcoded enum.
 * The exchange rate moves from each wallet to its currency, so one edit
 * re-values every wallet held in that currency.
 */
return new class extends Migration
{
    /** @var array<string, array{string, string, bool, int, float}> code => [name, symbol, symbol first, decimals, rate] */
    private const DEFAULTS = [
        'TND' => ['Tunisian Dinar', 'TND', false, 3, 1.0],
        'USD' => ['US Dollar', '$', true, 2, 3.12],
        'EUR' => ['Euro', '€', true, 2, 3.38],
    ];

    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->char('code', 3)->primary();
            $table->string('name', 60);
            $table->string('symbol', 8);
            $table->boolean('symbol_first')->default(false);
            $table->unsignedTinyInteger('decimals')->default(2);
            // Value of 1 major unit of this currency in the base currency (base = 1).
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->boolean('is_base')->default(false);
            $table->timestamps();
        });

        $base = strtoupper((string) config('money.base_currency', 'TND'));

        // Keep the rates already entered on wallets.
        $walletRates = Schema::hasColumn('wallets', 'exchange_rate')
            ? DB::table('wallets')->select('currency', DB::raw('MAX(exchange_rate) AS rate'))->groupBy('currency')->pluck('rate', 'currency')->all()
            : [];

        $codes = array_unique([$base, ...array_keys(self::DEFAULTS), ...array_keys($walletRates)]);
        $now = now();

        foreach ($codes as $code) {
            [$name, $symbol, $symbolFirst, $decimals, $rate] = self::DEFAULTS[$code] ?? [$code, $code, false, 2, 1.0];
            $isBase = $code === $base;

            DB::table('currencies')->insert([
                'code' => $code,
                'name' => $name,
                'symbol' => $symbol,
                'symbol_first' => $symbolFirst,
                'decimals' => $decimals,
                'exchange_rate' => $isBase ? 1 : (float) ($walletRates[$code] ?? $rate),
                'is_base' => $isBase,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasColumn('wallets', 'exchange_rate')) {
            Schema::table('wallets', function (Blueprint $table): void {
                $table->dropColumn('exchange_rate');
            });
        }
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->decimal('exchange_rate', 18, 6)->default(1)->after('currency');
        });

        foreach (DB::table('currencies')->get() as $currency) {
            DB::table('wallets')->where('currency', $currency->code)->update(['exchange_rate' => $currency->exchange_rate]);
        }

        Schema::dropIfExists('currencies');
    }
};
