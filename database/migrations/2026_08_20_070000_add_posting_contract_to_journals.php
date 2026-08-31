<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('source_event', 80)->nullable()->after('source_type');
            $table->string('source_id', 100)->nullable()->after('source_event');
            $table->char('idempotency_key', 64)->nullable()->after('source_reference')->unique();
            $table->char('posting_hash', 64)->nullable()->after('idempotency_key');

            $table->index(['source_type', 'source_id']);
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->string('subledger_type', 30)->nullable()->after('account_id');
            $table->string('subledger_id', 100)->nullable()->after('subledger_type');

            $table->index(['subledger_type', 'subledger_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex(['subledger_type', 'subledger_id']);
            $table->dropColumn(['subledger_type', 'subledger_id']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['source_event', 'source_id', 'idempotency_key', 'posting_hash']);
        });
    }
};
