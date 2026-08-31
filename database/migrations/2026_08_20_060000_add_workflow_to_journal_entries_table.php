<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('reversal_of_id')->nullable()->after('status')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->after('reversal_of_id')->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable()->after('validated_by');
            $table->string('validation_reason', 500)->nullable()->after('validated_at');
            $table->foreignId('posted_by')->nullable()->after('validation_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->after('posted_by');
            $table->string('posting_reason', 500)->nullable()->after('posted_at');
            $table->foreignId('reversed_by')->nullable()->after('posting_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_at');

            $table->unique('reversal_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_id']);
            $table->dropUnique(['reversal_of_id']);
            $table->dropColumn('reversal_of_id');
            $table->dropConstrainedForeignId('validated_by');
            $table->dropConstrainedForeignId('posted_by');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn([
                'validated_at', 'validation_reason', 'posted_at', 'posting_reason',
                'reversed_at', 'reversal_reason',
            ]);
        });
    }
};
