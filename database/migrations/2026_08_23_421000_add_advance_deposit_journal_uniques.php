<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_advance_deposits', function (Blueprint $table): void {
            $table->unique('journal_entry_id', 'finance_advances_journal_unique');
            $table->unique('reversal_journal_entry_id', 'finance_advances_reversal_journal_unique');
        });
        Schema::table('finance_advance_deposit_applications', function (Blueprint $table): void {
            $table->unique('journal_entry_id', 'finance_adv_apps_journal_unique');
            $table->unique('reversal_journal_entry_id', 'finance_adv_apps_reversal_journal_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_advance_deposit_applications', function (Blueprint $table): void {
            $table->dropUnique('finance_adv_apps_reversal_journal_unique');
            $table->dropUnique('finance_adv_apps_journal_unique');
        });
        Schema::table('finance_advance_deposits', function (Blueprint $table): void {
            $table->dropUnique('finance_advances_reversal_journal_unique');
            $table->dropUnique('finance_advances_journal_unique');
        });
    }
};
