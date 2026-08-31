<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_physical_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->enum('document_type', ['HS', 'IV']);
            $table->string('document_number', 40);
            $table->enum('source_type', ['SALES_ORDER', 'PRODUCTION_RECEIPT']);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('party_code', 30);
            $table->string('party_name');
            $table->char('party_tax_id', 13)->nullable();
            $table->char('party_branch_code', 5)->nullable();
            $table->text('party_address')->nullable();
            $table->date('document_date');
            $table->date('posting_date')->nullable();
            $table->decimal('subtotal', 18, 2)->unsigned()->default(0);
            $table->decimal('discount_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('tax_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('total_amount', 18, 2)->unsigned()->default(0);
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'document_type', 'document_number'], 'pos_physical_sales_number_unique');
            $table->unique(['source_type', 'source_id', 'document_type'], 'pos_physical_sales_source_unique');
            $table->index(['warehouse_id', 'status', 'document_date'], 'pos_physical_sales_scope_idx');
            $table->index(['source_type', 'source_id'], 'pos_physical_sales_source_idx');
        });

        Schema::create('pos_physical_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('physical_sale_id')->constrained('pos_physical_sales')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('source_line_id')->nullable();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('sale_uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->foreignId('stock_uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('quantity', 18, 8)->unsigned();
            $table->decimal('uom_factor', 18, 8)->unsigned();
            $table->decimal('stock_quantity', 18, 8)->unsigned();
            $table->decimal('unit_price', 18, 4)->unsigned()->default(0);
            $table->decimal('discount_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('line_total', 18, 2)->unsigned()->default(0);
            $table->json('item_snapshot')->nullable();
            $table->json('conversion_snapshot');
            $table->timestamps();

            $table->unique(['physical_sale_id', 'line_number'], 'pos_physical_sale_lines_number_unique');
            $table->index(['item_id', 'stock_uom_id'], 'pos_physical_sale_lines_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_physical_sale_lines');
        Schema::dropIfExists('pos_physical_sales');
    }
};
