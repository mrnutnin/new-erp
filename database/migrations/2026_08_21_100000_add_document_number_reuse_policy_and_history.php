<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('finance_document_sequences', 'number_reuse_policy')) {
            Schema::table('finance_document_sequences', function (Blueprint $table): void {
                $table->string('number_reuse_policy', 32)->default('NEVER_REUSE')->after('is_active');
            });
        }

        if (! Schema::hasTable('finance_document_sequence_histories')) {
            Schema::create('finance_document_sequence_histories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('document_sequence_id')->nullable()->constrained('finance_document_sequences')->nullOnDelete();
                $table->string('source_type', 80);
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('document_number', 40);
                $table->date('document_date');
                $table->enum('status', ['ACTIVE', 'SUPERSEDED', 'RELEASED', 'REUSED'])->default('ACTIVE');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['document_sequence_id', 'document_number'], 'finance_seq_hist_doc_idx');
                $table->index(['source_type', 'source_id'], 'finance_seq_hist_source_idx');
            });
        } else {
            $indexes = collect(Schema::getIndexes('finance_document_sequence_histories'))->pluck('name')->all();
            Schema::table('finance_document_sequence_histories', function (Blueprint $table) use ($indexes): void {
                if (! in_array('finance_seq_hist_doc_idx', $indexes, true)) {
                    $table->index(['document_sequence_id', 'document_number'], 'finance_seq_hist_doc_idx');
                }
                if (! in_array('finance_seq_hist_source_idx', $indexes, true)) {
                    $table->index(['source_type', 'source_id'], 'finance_seq_hist_source_idx');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_document_sequence_histories');
        if (Schema::hasColumn('finance_document_sequences', 'number_reuse_policy')) {
            Schema::table('finance_document_sequences', function (Blueprint $table): void {
                $table->dropColumn('number_reuse_policy');
            });
        }
    }
};
