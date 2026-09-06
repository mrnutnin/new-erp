<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_petty_cash_clearings', function (Blueprint $table): void {
            if (! Schema::hasColumn('finance_petty_cash_clearings', 'journal_entry_id')) {
                $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->nullOnDelete();
            }
            if (! Schema::hasColumn('finance_petty_cash_clearings', 'idempotency_key')) {
                $table->char('idempotency_key', 64)->nullable();
            }
            if (! Schema::hasColumn('finance_petty_cash_clearings', 'reversal_journal_entry_id')) {
                $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->nullOnDelete();
            }
            if (! Schema::hasColumn('finance_petty_cash_clearings', 'reversal_key')) {
                $table->char('reversal_key', 64)->nullable();
            }
            if (! Schema::hasColumn('finance_petty_cash_clearings', 'reversed_by')) {
                $table->foreignId('reversed_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('finance_petty_cash_clearings', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            }
            if (! Schema::hasColumn('finance_petty_cash_clearings', 'reversal_reason')) {
                $table->string('reversal_reason', 500)->nullable()->after('reversed_at');
            }
        });

        $indexes = collect(DB::select('SHOW INDEX FROM finance_petty_cash_clearings'))
            ->pluck('Key_name')->unique()->all();
        if (Schema::hasColumn('finance_petty_cash_clearings', 'idempotency_key') && ! in_array('finance_petty_cash_clearings_idempotency_key_unique', $indexes, true)) {
            Schema::table('finance_petty_cash_clearings', fn (Blueprint $table) => $table->unique('idempotency_key', 'finance_petty_cash_clearings_idempotency_key_unique'));
        }
        if (Schema::hasColumn('finance_petty_cash_clearings', 'reversal_key') && ! in_array('finance_petty_cash_clearings_reversal_key_unique', $indexes, true)) {
            Schema::table('finance_petty_cash_clearings', fn (Blueprint $table) => $table->unique('reversal_key', 'finance_petty_cash_clearings_reversal_key_unique'));
        }

        $now = now();
        $revenueType = DB::table('account_types')->where('code', 'REVENUE')->value('id');
        $expenseType = DB::table('account_types')->where('code', 'EXPENSE')->value('id');
        if ($revenueType && $expenseType) {
            DB::table('accounts')->updateOrInsert(['code' => '42300'], ['account_type_id' => $revenueType, 'name' => 'เงินเกินจากเงินสดย่อย', 'level' => 1, 'normal_balance' => 'CREDIT', 'statement_section' => 'PROFIT_LOSS', 'reporting_profile' => 'PAE', 'is_postable' => true, 'is_active' => true, 'updated_by' => 1, 'updated_at' => $now]);
            DB::table('accounts')->updateOrInsert(['code' => '52300'], ['account_type_id' => $expenseType, 'name' => 'เงินขาดจากเงินสดย่อย', 'level' => 1, 'normal_balance' => 'DEBIT', 'statement_section' => 'PROFIT_LOSS', 'reporting_profile' => 'PAE', 'is_postable' => true, 'is_active' => true, 'updated_by' => 1, 'updated_at' => $now]);
            foreach ([['PETTY_CASH_VARIANCE_GAIN', '42300'], ['PETTY_CASH_VARIANCE_LOSS', '52300']] as [$key, $code]) {
                DB::table('accounting_account_mappings')->updateOrInsert(['event_code' => 'petty_cash_clearing', 'key' => $key], ['account_id' => DB::table('accounts')->where('code', $code)->value('id'), 'is_active' => true, 'version' => 1, 'created_by' => 1, 'updated_by' => 1, 'updated_at' => $now, 'created_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('accounting_account_mappings')->where('event_code', 'petty_cash_clearing')->delete();
        Schema::table('finance_petty_cash_clearings', function (Blueprint $table): void {
            $table->dropForeign(['journal_entry_id']);
            $table->dropForeign(['reversal_journal_entry_id']);
            $table->dropForeign(['posted_by']);
            $table->dropForeign(['reversed_by']);
            $table->dropUnique(['idempotency_key']);
            $table->dropUnique(['reversal_key']);
            $table->dropColumn(['journal_entry_id', 'idempotency_key', 'reversal_journal_entry_id', 'reversal_key', 'posted_by', 'posted_at', 'reversed_by', 'reversed_at', 'reversal_reason']);
        });
    }
};
