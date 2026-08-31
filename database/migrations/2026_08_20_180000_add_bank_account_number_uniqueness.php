<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_bank_accounts', function (Blueprint $table) {
            $table->unique(['warehouse_id', 'account_number'], 'finance_bank_warehouse_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_bank_accounts', function (Blueprint $table) {
            $table->dropUnique('finance_bank_warehouse_number_unique');
        });
    }
};
