<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('goods_receipts')) {
            Schema::create('goods_receipts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
                $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
                $table->foreignId('supplier_id')->constrained('parties')->restrictOnDelete();
                $table->string('receipt_number', 80);
                $table->string('idempotency_key', 180);
                $table->date('business_date');
                $table->enum('status', ['DRAFT', 'APPROVED', 'VOID'])->default('DRAFT');
                $table->text('description')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('voided_at')->nullable();
                $table->string('void_reason', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique('idempotency_key', 'gr_idempotency_uq');
                $table->unique(['warehouse_id', 'receipt_number'], 'gr_wh_receipt_uq');
                $table->index(['purchase_order_id', 'status', 'business_date'], 'gr_po_status_date_ix');
            });
        }
        if (! Schema::hasTable('goods_receipt_lines')) {
            Schema::create('goods_receipt_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_order_line_id')->constrained()->restrictOnDelete();
                $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
                $table->foreignId('purchase_uom_id')->constrained('wms_uoms')->restrictOnDelete();
                $table->foreignId('stock_uom_id')->constrained('wms_uoms')->restrictOnDelete();
                $table->decimal('purchase_quantity', 20, 8);
                $table->decimal('factor', 20, 8);
                $table->decimal('stock_quantity', 20, 8);
                $table->decimal('total_cost', 20, 8)->default(0);
                $table->decimal('stock_unit_cost', 20, 8)->default(0);
                $table->decimal('rounding_delta', 20, 8)->default(0);
                $table->json('conversion_snapshot');
                $table->timestamps();
                $table->unique(['goods_receipt_id', 'purchase_order_line_id'], 'grl_receipt_po_line_uq');
                $table->index(['purchase_order_line_id', 'item_id'], 'grl_po_line_item_ix');
            });
        } elseif (! collect(Schema::getIndexes('goods_receipt_lines'))->pluck('name')->contains('grl_receipt_po_line_uq')) {
            Schema::table('goods_receipt_lines', function (Blueprint $table): void {
                $table->unique(['goods_receipt_id', 'purchase_order_line_id'], 'grl_receipt_po_line_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
