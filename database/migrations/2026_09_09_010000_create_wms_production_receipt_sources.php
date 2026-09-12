<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wms_production_receipt_sources')) {
            Schema::create('wms_production_receipt_sources', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('receipt_document_id');
                $table->unsignedBigInteger('issue_document_id');
                $table->unsignedBigInteger('issue_line_id');
                $table->unsignedBigInteger('source_allocation_id');
                $table->unsignedInteger('source_allocation_revision')->default(0);
                $table->decimal('consumed_quantity', 20, 8);
                $table->decimal('consumed_value', 20, 8);
                $table->unsignedInteger('position')->default(1);
                $table->timestamps();

                $table->unique(['receipt_document_id', 'source_allocation_id'], 'wms_prs_receipt_alloc_uq');
                $table->index(['source_allocation_id', 'receipt_document_id'], 'wms_prs_alloc_receipt_idx');
                $table->index(['issue_document_id', 'issue_line_id'], 'wms_prs_issue_line_idx');
            });
        }

        $this->backfillLegacySources();
    }

    private function backfillLegacySources(): void
    {
        if (! Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')
            || ! Schema::hasTable('wms_issue_lines')
            || ! Schema::hasTable('wms_cost_allocations')) {
            return;
        }

        DB::table('wms_inventory_adjustment_documents')
            ->where('document_context', 'PRODUCTION_RECEIPT')
            ->whereNotNull('source_issue_id')
            ->orderBy('id')
            ->select(['id', 'source_issue_id'])
            ->chunkById(100, function ($documents): void {
                foreach ($documents as $document) {
                    $rows = DB::table('wms_issue_lines as line')
                        ->join('wms_cost_allocations as allocation', 'allocation.stock_movement_id', '=', 'line.stock_movement_id')
                        ->where('line.document_id', $document->source_issue_id)
                        ->where('allocation.status', '!=', 'REVERSED')
                        ->orderBy('line.line_number')
                        ->orderBy('allocation.id')
                        ->when(Schema::hasColumn('wms_issue_lines', 'deleted_at'), fn ($query) => $query->whereNull('line.deleted_at'))
                        ->select([
                            'line.id as issue_line_id', 'line.document_id as issue_document_id',
                            'allocation.id as source_allocation_id', 'allocation.revision as source_allocation_revision',
                        ])
                        ->selectRaw('ABS(allocation.quantity) as consumed_quantity, ABS(allocation.value) as consumed_value')
                        ->get();

                    $now = now();
                    $payload = $rows->values()->map(fn ($row, int $index): array => [
                        'receipt_document_id' => (int) $document->id,
                        'issue_document_id' => (int) $row->issue_document_id,
                        'issue_line_id' => (int) $row->issue_line_id,
                        'source_allocation_id' => (int) $row->source_allocation_id,
                        'source_allocation_revision' => (int) $row->source_allocation_revision,
                        'consumed_quantity' => (string) $row->consumed_quantity,
                        'consumed_value' => (string) $row->consumed_value,
                        'position' => $index + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();

                    if ($payload !== []) {
                        DB::table('wms_production_receipt_sources')->upsert(
                            $payload,
                            ['receipt_document_id', 'source_allocation_id'],
                            ['issue_document_id', 'issue_line_id', 'source_allocation_revision', 'consumed_quantity', 'consumed_value', 'position', 'updated_at'],
                        );
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_production_receipt_sources');
    }
};
