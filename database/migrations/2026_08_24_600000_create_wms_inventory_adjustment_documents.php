<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_inventory_adjustment_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('document_number', 80);
            $table->date('document_date');
            $table->enum('status', ['DRAFT', 'APPROVED', 'POSTED', 'VOID', 'REVERSED'])->default('DRAFT');
            $table->enum('reversal_status', ['NONE', 'REVERSED'])->default('NONE');
            $table->string('reason', 500);
            $table->string('idempotency_key', 180)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->unsignedInteger('reversal_revision')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'document_number'], 'wms_adj_documents_number_unique');
            $table->index(['warehouse_id', 'document_date', 'status'], 'wms_adj_documents_scope_date_status_idx');
            $table->unique(['id', 'reversal_revision'], 'wms_adj_documents_reversal_revision_unique');
        });

        Schema::table('wms_inventory_adjustments', function (Blueprint $table): void {
            $table->foreignId('document_id')->nullable()->after('id');
            $table->unsignedInteger('line_number')->nullable()->after('document_id');
            $table->index(['document_id', 'line_number'], 'wms_adj_document_line_idx');
        });

        DB::transaction(function (): void {
            DB::table('wms_inventory_adjustments')->orderBy('id')->each(function (object $line): void {
                if (! in_array($line->status, ['DRAFT', 'APPROVED', 'POSTED'], true)) {
                    throw new RuntimeException('Unsupported legacy Adjustment status: '.$line->status);
                }

                $documentId = DB::table('wms_inventory_adjustment_documents')->insertGetId([
                    'warehouse_id' => $line->warehouse_id,
                    'document_number' => 'ADJ-LEGACY-'.str_pad((string) $line->id, 12, '0', STR_PAD_LEFT),
                    'document_date' => $line->business_date,
                    'status' => $line->status,
                    'reason' => $line->reason,
                    'idempotency_key' => 'adjustment-document:legacy:'.$line->id,
                    'created_by' => $line->created_by,
                    'approved_by' => $line->approved_by,
                    'posted_by' => null,
                    'created_at' => $line->created_at,
                    'updated_at' => $line->updated_at,
                ]);

                DB::table('wms_inventory_adjustments')->where('id', $line->id)->update([
                    'document_id' => $documentId,
                    'line_number' => 1,
                ]);
            });
        });

        Schema::table('wms_inventory_adjustments', function (Blueprint $table): void {
            $table->foreign('document_id', 'wms_adj_document_fk')->references('id')->on('wms_inventory_adjustment_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('wms_inventory_adjustment_documents')->exists()) {
            throw new RuntimeException('Cannot rollback Adjustment documents while header data exists; preserve document history first.');
        }

        Schema::table('wms_inventory_adjustments', function (Blueprint $table): void {
            $table->dropForeign('wms_adj_document_fk');
            $table->dropIndex('wms_adj_document_line_idx');
            $table->dropColumn(['document_id', 'line_number']);
        });

        Schema::dropIfExists('wms_inventory_adjustment_documents');
    }
};
