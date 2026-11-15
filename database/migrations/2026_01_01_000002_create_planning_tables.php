<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // All amounts below are minor units of the base currency.
        Schema::create('budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount'); // monthly cap
            $table->boolean('rollover')->default(false);
            $table->timestamps();

            $table->unique(['workspace_id', 'category_id']);
        });

        Schema::create('sinking_funds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon', 50)->default('piggy-bank');
            $table->string('color_hex', 7)->default('#3B82F6');
            $table->bigInteger('target_amount');
            $table->bigInteger('saved_amount')->default(0);
            $table->date('target_date');
            $table->timestamps();
        });

        Schema::create('sinking_fund_contributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sinking_fund_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->date('contributed_on');
            $table->timestamps();
        });

        Schema::create('debts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('contact_name');
            $table->string('direction', 12);
            $table->bigInteger('principal');
            $table->bigInteger('paid_amount')->default(0);
            $table->date('due_date')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('debt_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->date('paid_on');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_payments');
        Schema::dropIfExists('debts');
        Schema::dropIfExists('sinking_fund_contributions');
        Schema::dropIfExists('sinking_funds');
        Schema::dropIfExists('budgets');
    }
};
