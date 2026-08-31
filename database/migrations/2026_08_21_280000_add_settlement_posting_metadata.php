<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_settlements', function (Blueprint $table) {
            $table->foreignId('posted_by')->nullable()->after('journal_entry_id')->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->after('posted_by');
            $table->unique('journal_entry_id', 'finance_settlements_journal_unique');
        });

        Schema::table('finance_settlement_allocation_intents', function (Blueprint $table) {
            $table->foreignId('allocation_id')->nullable()->after('amount')->constrained('finance_allocations')->restrictOnDelete();
            $table->unique('allocation_id', 'finance_settlement_intents_allocation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_settlement_allocation_intents', function (Blueprint $table) {
            $table->dropUnique('finance_settlement_intents_allocation_unique');
            $table->dropConstrainedForeignId('allocation_id');
        });
        Schema::table('finance_settlements', function (Blueprint $table) {
            $table->dropUnique('finance_settlements_journal_unique');
            $table->dropConstrainedForeignId('posted_by');
            $table->dropColumn('posted_at');
        });
    }
};
