<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_petty_cash_funds', function (Blueprint $table): void {
            $table->index(['warehouse_id', 'bank_account_id'], 'finance_petty_cash_funds_warehouse_cash_idx');
        });

        Schema::table('finance_petty_cash_funds', function (Blueprint $table): void {
            $table->dropUnique('finance_petty_cash_funds_warehouse_cash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_petty_cash_funds', function (Blueprint $table): void {
            $table->dropIndex('finance_petty_cash_funds_warehouse_cash_idx');
            $table->unique(['warehouse_id', 'bank_account_id'], 'finance_petty_cash_funds_warehouse_cash_unique');
        });
    }
};
