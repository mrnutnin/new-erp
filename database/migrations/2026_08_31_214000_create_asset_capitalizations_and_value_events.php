<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $assetIndexes = collect(Schema::getIndexes('assets'))->pluck('name')->all();
        Schema::table('assets', function (Blueprint $table) use ($assetIndexes): void {
            // A purchase invoice line can be allocated to several assets; posting validates its total under a source-row lock.
            if (in_array('assets_source_type_source_line_id_unique', $assetIndexes, true)) {
                $table->dropUnique('assets_source_type_source_line_id_unique');
            }
            if (! in_array('assets_source_reference_idx', $assetIndexes, true)) {
                $table->index(['source_type', 'source_id', 'source_line_id'], 'assets_source_reference_idx');
            }
        });

        if (! Schema::hasTable('asset_capitalizations')) {
            Schema::create('asset_capitalizations', function (Blueprint $table): void {
                $table->id();
                $table->string('document_number', 40)->unique();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->date('document_date');
                $table->enum('source_type', ['PURCHASE_DOCUMENT', 'PAYMENT_VOUCHER', 'OPENING', 'MANUAL_RECLASS']);
                $table->unsignedBigInteger('source_id')->nullable();
                $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED', 'VOID'])->default('DRAFT');
                $table->text('description')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('asset_capitalizations')->restrictOnDelete();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->date('reversal_date')->nullable();
                $table->string('reversal_reason', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['branch_id', 'status', 'document_date']);
                $table->index(['source_type', 'source_id']);
            });
        }

        if (! Schema::hasTable('asset_capitalization_lines')) {
            Schema::create('asset_capitalization_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('asset_capitalization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('asset_id')->constrained()->restrictOnDelete();
                $table->unsignedSmallInteger('line_number');
                $table->string('source_type', 30)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->unsignedBigInteger('source_line_id')->nullable();
                $table->decimal('capitalized_cost', 18, 2);
                $table->foreignId('clearing_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
                $table->string('description', 500)->nullable();
                $table->timestamps();
                $table->unique(['asset_capitalization_id', 'line_number'], 'asset_cap_line_number_uq');
                // Deliberately non-unique: one eligible purchase line may fund multiple assets.
                $table->index(['source_type', 'source_id', 'source_line_id'], 'asset_capitalization_line_source_idx');
                $table->index('asset_id');
            });
        }

        if (! Schema::hasTable('asset_value_events')) {
            Schema::create('asset_value_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('asset_id')->constrained()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->date('event_date');
                $table->enum('event_type', ['OPENING', 'CAPITALIZATION', 'ADDITION', 'IMPROVEMENT', 'IMPAIRMENT', 'IMPAIRMENT_REVERSAL', 'DISPOSAL', 'WRITE_OFF', 'REVERSAL']);
                $table->decimal('cost_delta', 18, 2)->default(0);
                $table->decimal('depreciation_delta', 18, 2)->default(0);
                $table->decimal('impairment_delta', 18, 2)->default(0);
                $table->string('source_type', 30)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->unsignedBigInteger('source_line_id')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('reversal_of_event_id')->nullable()->constrained('asset_value_events')->restrictOnDelete();
                $table->char('idempotency_key', 64)->unique();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['asset_id', 'event_date']);
                $table->index(['branch_id', 'event_type', 'event_date']);
                $table->index(['source_type', 'source_id', 'source_line_id'], 'asset_value_event_source_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_value_events');
        Schema::dropIfExists('asset_capitalization_lines');
        Schema::dropIfExists('asset_capitalizations');

        Schema::table('assets', function (Blueprint $table): void {
            $table->dropIndex('assets_source_reference_idx');
            $table->unique(['source_type', 'source_line_id']);
        });
    }
};
