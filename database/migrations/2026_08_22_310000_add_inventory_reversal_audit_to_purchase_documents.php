<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_documents', function (Blueprint $table): void {
            // Keep the accounting POSTED state immutable. Reversal is an
            // auditable child revision rather than a destructive status change.
            $table->enum('reversal_status', ['NONE', 'REVERSED'])->default('NONE')->after('status');
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->after('posted_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_at');
            $table->unsignedInteger('reversal_revision')->default(0)->after('reversal_reason');
            $table->unique(['id', 'reversal_revision'], 'purchase_documents_reversal_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_documents', function (Blueprint $table): void {
            $table->dropUnique('purchase_documents_reversal_revision_unique');
            $table->dropForeign(['reversal_journal_entry_id']);
            $table->dropForeign(['reversed_by']);
            $table->dropColumn([
                'reversal_status', 'reversal_journal_entry_id', 'reversed_by', 'reversed_at',
                'reversal_reason', 'reversal_revision',
            ]);
        });
    }
};
