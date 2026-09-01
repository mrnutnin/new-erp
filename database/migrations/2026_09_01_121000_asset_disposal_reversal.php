<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;

return new class extends Migration {
    public function up(): void
    {
        // This migration was introduced while the local feature branch could
        // already contain the same columns; tolerate that harmless replay.
        try {
        if (! Schema::hasColumn('asset_disposals', 'reversal_of_id')) Schema::table('asset_disposals', fn (Blueprint $table) => $table->foreignId('reversal_of_id')->nullable()->after('journal_entry_id')->constrained('asset_disposals')->nullOnDelete());
        if (! Schema::hasColumn('asset_disposals', 'reversal_journal_entry_id')) Schema::table('asset_disposals', fn (Blueprint $table) => $table->foreignId('reversal_journal_entry_id')->nullable()->after('reversal_of_id')->constrained('journal_entries')->nullOnDelete());
        if (! Schema::hasColumn('asset_disposals', 'reversed_by')) Schema::table('asset_disposals', fn (Blueprint $table) => $table->foreignId('reversed_by')->nullable()->after('cancelled_by')->constrained('users')->nullOnDelete());
        if (! Schema::hasColumn('asset_disposals', 'reversed_at')) Schema::table('asset_disposals', fn (Blueprint $table) => $table->timestamp('reversed_at')->nullable()->after('cancelled_at'));
        if (! Schema::hasColumn('asset_disposals', 'reversal_date')) Schema::table('asset_disposals', fn (Blueprint $table) => $table->date('reversal_date')->nullable());
        if (! Schema::hasColumn('asset_disposals', 'reversal_reason')) Schema::table('asset_disposals', fn (Blueprint $table) => $table->string('reversal_reason', 500)->nullable());
        if (! Schema::hasIndex('asset_disposals', 'asset_disposals_reversal_of_id_index')) Schema::table('asset_disposals', fn (Blueprint $table) => $table->index('reversal_of_id'));
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'Duplicate column name')) throw $e;
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('asset_disposals', 'reversal_of_id')) Schema::table('asset_disposals', fn (Blueprint $table) => $table->dropForeign(['reversal_of_id']));
        if (Schema::hasColumn('asset_disposals', 'reversal_journal_entry_id')) Schema::table('asset_disposals', fn (Blueprint $table) => $table->dropForeign(['reversal_journal_entry_id']));
        if (Schema::hasColumn('asset_disposals', 'reversed_by')) Schema::table('asset_disposals', fn (Blueprint $table) => $table->dropForeign(['reversed_by']));
        if (Schema::hasIndex('asset_disposals', 'asset_disposals_reversal_of_id_index')) Schema::table('asset_disposals', fn (Blueprint $table) => $table->dropIndex(['reversal_of_id']));
        $columns = array_values(array_filter(['reversal_of_id', 'reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'reversal_date', 'reversal_reason'], fn (string $column) => Schema::hasColumn('asset_disposals', $column)));
        if ($columns) Schema::table('asset_disposals', fn (Blueprint $table) => $table->dropColumn($columns));
    }
};
