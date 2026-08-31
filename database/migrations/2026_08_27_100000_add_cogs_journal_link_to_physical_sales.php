<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pos_physical_sales', 'cogs_journal_entry_id')) {
            Schema::table('pos_physical_sales', function (Blueprint $table): void {
                $table->foreignId('cogs_journal_entry_id')->nullable()->after('journal_entry_id')
                    ->constrained('journal_entries')->restrictOnDelete();
                $table->unique('cogs_journal_entry_id', 'pos_physical_sales_cogs_journal_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_physical_sales', 'cogs_journal_entry_id')) {
            Schema::table('pos_physical_sales', function (Blueprint $table): void {
                $table->dropForeign(['cogs_journal_entry_id']);
                $table->dropUnique('pos_physical_sales_cogs_journal_unique');
                $table->dropColumn('cogs_journal_entry_id');
            });
        }
    }
};
