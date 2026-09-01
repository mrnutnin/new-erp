<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_opening_balance_batches')) {
            Schema::create('asset_opening_balance_batches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->string('batch_reference', 100);
                $table->string('source_system', 30)->default('OPENING_BALANCE');
                $table->date('cutover_date');
                $table->string('reconciliation_reference', 100);
                $table->enum('status', ['DRAFT', 'VALIDATED', 'COMMITTED'])->default('DRAFT');
                $table->unsignedInteger('total_rows')->default(0);
                $table->decimal('total_opening_cost', 18, 2)->default(0);
                $table->decimal('total_accumulated_depreciation', 18, 2)->default(0);
                $table->decimal('total_accumulated_impairment', 18, 2)->default(0);
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('validated_at')->nullable();
                $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('committed_at')->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'batch_reference'], 'asset_opening_batch_scope_uq');
                $table->index(['branch_id', 'status', 'cutover_date'], 'asset_opening_batch_status_idx');
            });
        }

        $batchIndexes = collect(Schema::getIndexes('asset_opening_balance_batches'))->pluck('name')->all();
        if (! in_array('asset_opening_batch_status_idx', $batchIndexes, true)) {
            Schema::table('asset_opening_balance_batches', fn (Blueprint $table) => $table->index(['branch_id', 'status', 'cutover_date'], 'asset_opening_batch_status_idx'));
        }

        if (! Schema::hasTable('asset_opening_balance_lines')) {
            Schema::create('asset_opening_balance_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('asset_opening_balance_batch_id');
                $table->string('row_key', 100);
                $table->string('source_reference', 100)->nullable();
                $table->json('asset_payload');
                $table->decimal('opening_cost', 18, 2);
                $table->decimal('opening_accumulated_depreciation', 18, 2)->default(0);
                $table->decimal('opening_accumulated_impairment', 18, 2)->default(0);
                $table->decimal('opening_book_value', 18, 2);
                $table->foreignId('asset_id')->nullable();
                $table->timestamps();

                $table->unique(['asset_opening_balance_batch_id', 'row_key'], 'asset_opening_line_row_uq');
                $table->unique(['asset_opening_balance_batch_id', 'asset_id'], 'asset_opening_line_asset_uq');
            });
        }

        $lineForeignKeys = collect(Schema::getForeignKeys('asset_opening_balance_lines'))->pluck('name')->all();
        $lineIndexes = collect(Schema::getIndexes('asset_opening_balance_lines'))->pluck('name')->all();
        Schema::table('asset_opening_balance_lines', function (Blueprint $table) use ($lineForeignKeys, $lineIndexes): void {
            if (! in_array('asset_open_line_batch_fk', $lineForeignKeys, true)) {
                $table->foreign('asset_opening_balance_batch_id', 'asset_open_line_batch_fk')->references('id')->on('asset_opening_balance_batches')->restrictOnDelete();
            }
            if (! in_array('asset_open_line_asset_fk', $lineForeignKeys, true)) {
                $table->foreign('asset_id', 'asset_open_line_asset_fk')->references('id')->on('assets')->restrictOnDelete();
            }
            if (! in_array('asset_opening_line_row_uq', $lineIndexes, true)) {
                $table->unique(['asset_opening_balance_batch_id', 'row_key'], 'asset_opening_line_row_uq');
            }
            if (! in_array('asset_opening_line_asset_uq', $lineIndexes, true)) {
                $table->unique(['asset_opening_balance_batch_id', 'asset_id'], 'asset_opening_line_asset_uq');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_opening_balance_lines');
        Schema::dropIfExists('asset_opening_balance_batches');
    }
};
