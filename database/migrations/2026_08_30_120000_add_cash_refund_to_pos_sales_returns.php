<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales_returns', function (Blueprint $table): void {
            $table->foreignId('refund_bank_account_id')->nullable()->after('cogs_journal_entry_id')->constrained('finance_bank_accounts')->nullOnDelete();
            $table->decimal('refund_amount', 18, 2)->unsigned()->default(0)->after('refund_bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('refund_bank_account_id');
            $table->dropColumn('refund_amount');
        });
    }
};
