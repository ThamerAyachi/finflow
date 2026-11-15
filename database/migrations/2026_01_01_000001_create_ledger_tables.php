<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 20)->default('personal');
            $table->string('color_hex', 7)->default('#22C55E');
            $table->timestamps();
        });

        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->char('currency', 3)->default('TND');
            // Value of 1 major unit of this wallet's currency in the base currency.
            $table->decimal('exchange_rate', 18, 6)->default(1);
            // Minor units of the wallet currency. Signed: credit cards run negative.
            $table->bigInteger('initial_balance')->default(0);
            $table->bigInteger('balance')->default(0);
            $table->boolean('is_included_in_net_worth')->default(true);
            $table->string('color_hex', 7)->default('#3B82F6');
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 10);
            $table->string('icon', 50)->default('tag');
            $table->string('color_hex', 7)->default('#71717A');
            $table->timestamps();

            $table->index(['type', 'parent_id']);
        });

        Schema::create('recurring_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 10);
            $table->bigInteger('amount'); // wallet currency, minor units
            $table->string('frequency', 10);
            $table->date('next_due_date');
            $table->boolean('auto_post')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'next_due_date']);
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_wallet_id')->nullable()->constrained('wallets')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            // Reimbursement / refund → the original expense it offsets.
            $table->foreignId('parent_transaction_id')->nullable()->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('recurring_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10);
            $table->bigInteger('amount');                         // always positive, wallet currency
            $table->bigInteger('destination_amount')->nullable(); // transfers: amount credited, destination currency
            $table->bigInteger('base_amount');                    // amount in base currency for reporting
            // False for debt movements: they change balances but are neither income nor spending.
            $table->boolean('affects_reports')->default(true);
            $table->string('description')->default('');
            $table->text('notes')->nullable();
            $table->date('occurred_on');
            $table->timestamps();

            $table->index(['workspace_id', 'occurred_on']);
            $table->index(['wallet_id', 'occurred_on']);
            $table->index(['category_id', 'occurred_on']);
            $table->index(['type', 'occurred_on']);
        });

        Schema::create('transaction_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('keyword');
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_rules');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('recurring_rules');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('workspaces');
    }
};
