<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('parties')->restrictOnDelete();
            $table->foreignId('purchase_document_id')->nullable()->constrained('purchase_documents')->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('credit_note_id')->nullable()->unique()->constrained('purchase_documents')->restrictOnDelete();
            $table->string('return_number', 80);
            $table->string('idempotency_key', 180)->unique();
            $table->date('return_date');
            $table->string('reason', 500);
            $table->string('supplier_code', 30);
            $table->string('supplier_name');
            $table->char('supplier_tax_id', 13)->nullable();
            $table->char('supplier_branch_code', 5)->default('00000');
            $table->text('supplier_address')->nullable();
            $table->string('tax_treatment', 20)->default('NONE_VAT');
            $table->boolean('prices_include_vat')->default(false);
            $table->unsignedTinyInteger('tax_decimal_places')->default(2);
            $table->decimal('subtotal', 18, 2)->unsigned()->default(0);
            $table->decimal('tax_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('gross_amount', 18, 2)->unsigned()->default(0);
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'VOID'])->default('DRAFT');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'return_number'], 'purchase_returns_number_uq');
            $table->index(['supplier_id', 'status', 'return_date'], 'purchase_returns_supplier_status_ix');
            $table->index(['purchase_document_id', 'goods_receipt_id'], 'purchase_returns_source_ix');
        });

        Schema::create('purchase_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('goods_receipt_line_id')->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->foreignId('purchase_document_line_id')->nullable()->constrained('purchase_document_lines')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('purchase_uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->foreignId('stock_uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('purchase_quantity', 20, 8)->unsigned();
            $table->decimal('stock_quantity', 20, 8)->unsigned();
            $table->decimal('factor', 20, 8)->unsigned();
            $table->decimal('unit_cost', 20, 8)->unsigned()->default(0);
            $table->decimal('total_cost', 20, 8)->unsigned()->default(0);
            $table->decimal('net_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('tax_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('gross_amount', 18, 2)->unsigned()->default(0);
            $table->string('reason', 500)->nullable();
            $table->json('source_snapshot');
            $table->timestamps();

            $table->unique(['purchase_return_id', 'goods_receipt_line_id'], 'purchase_return_lines_source_uq');
            $table->index(['goods_receipt_line_id', 'purchase_return_id'], 'purchase_return_lines_receipt_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
    }
};
