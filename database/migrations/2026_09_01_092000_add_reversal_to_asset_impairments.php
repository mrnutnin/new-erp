<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_impairments', function (Blueprint $table): void {
            $table->foreignId('reversal_of_id')->nullable()->after('journal_entry_id')->constrained('asset_impairments');
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('reversal_of_id')->constrained('journal_entries');
            $table->text('reversal_reason')->nullable()->after('cancellation_reason');
            $table->index('reversal_of_id', 'asset_impairments_reversal_idx');
        });
    }

    public function down(): void
    {
        Schema::table('asset_impairments', function (Blueprint $table): void {
            $table->dropIndex('asset_impairments_reversal_idx');
            $table->dropForeign(['reversal_journal_entry_id']);
            $table->dropForeign(['reversal_of_id']);
            $table->dropColumn(['reversal_journal_entry_id', 'reversal_of_id', 'reversal_reason']);
        });
    }
};
