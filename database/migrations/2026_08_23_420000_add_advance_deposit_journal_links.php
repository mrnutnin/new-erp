<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_advance_deposits', function (Blueprint $table): void {
            if (! Schema::hasColumn('finance_advance_deposits', 'journal_entry_id')) {
                $table->foreignId('journal_entry_id')->nullable()->after('source_settlement_id');
                $table->foreign('journal_entry_id', 'finance_advances_journal_fk')->references('id')->on('journal_entries')->restrictOnDelete();
            }
            if (! Schema::hasColumn('finance_advance_deposits', 'reversal_journal_entry_id')) {
                $table->foreignId('reversal_journal_entry_id')->nullable()->after('journal_entry_id');
                $table->foreign('reversal_journal_entry_id', 'finance_advances_reversal_journal_fk')->references('id')->on('journal_entries')->restrictOnDelete();
            }
        });
        Schema::table('finance_advance_deposit_applications', function (Blueprint $table): void {
            if (! Schema::hasColumn('finance_advance_deposit_applications', 'journal_entry_id')) {
                $table->foreignId('journal_entry_id')->nullable()->after('open_item_id');
                $table->foreign('journal_entry_id', 'finance_adv_apps_journal_fk')->references('id')->on('journal_entries')->restrictOnDelete();
            }
            if (! Schema::hasColumn('finance_advance_deposit_applications', 'reversal_journal_entry_id')) {
                $table->foreignId('reversal_journal_entry_id')->nullable()->after('journal_entry_id');
                $table->foreign('reversal_journal_entry_id', 'finance_adv_apps_reversal_journal_fk')->references('id')->on('journal_entries')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('finance_advance_deposit_applications', function (Blueprint $table): void {
            $table->dropForeign('finance_adv_apps_reversal_journal_fk');
            $table->dropForeign('finance_adv_apps_journal_fk');
            $table->dropColumn(['journal_entry_id', 'reversal_journal_entry_id']);
        });
        Schema::table('finance_advance_deposits', function (Blueprint $table): void {
            $table->dropForeign('finance_advances_reversal_journal_fk');
            $table->dropForeign('finance_advances_journal_fk');
            $table->dropColumn(['journal_entry_id', 'reversal_journal_entry_id']);
        });
    }
};
