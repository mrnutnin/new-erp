<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_advance_deposits', function (Blueprint $table): void {
            $table->foreignId('refund_bank_account_id')->nullable()->after('reversal_journal_entry_id')->constrained('finance_bank_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_advance_deposits', function (Blueprint $table): void {
            $table->dropForeign(['refund_bank_account_id']);
            $table->dropColumn('refund_bank_account_id');
        });
    }
};
