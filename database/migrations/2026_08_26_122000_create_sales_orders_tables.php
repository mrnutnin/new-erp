<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_quotation_id')->constrained('sales_quotations')->restrictOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('document_number', 40);
            $table->string('party_code', 30);
            $table->string('party_name');
            $table->char('party_tax_id', 13)->nullable();
            $table->char('party_branch_code', 5)->nullable();
            $table->text('party_address')->nullable();
            $table->date('document_date');
            $table->date('valid_until')->nullable();
            $table->enum('status', ['DRAFT', 'CANCELLED'])->default('DRAFT');
            $table->decimal('subtotal', 18, 2)->unsigned()->default(0);
            $table->decimal('discount_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('total_amount', 18, 2)->unsigned()->default(0);
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('sales_quotation_id', 'sales_orders_quotation_unique');
            $table->unique(['warehouse_id', 'document_number'], 'sales_orders_number_unique');
            $table->index(['warehouse_id', 'status', 'document_date'], 'sales_orders_scope_idx');
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('source_quotation_line_id')->constrained('sales_quotation_lines')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('item_id')->nullable()->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->nullable()->constrained('wms_uoms')->restrictOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 2)->unsigned()->default(0);
            $table->decimal('discount_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('line_total', 18, 2)->unsigned()->default(0);
            $table->json('item_snapshot')->nullable();
            $table->json('uom_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['sales_order_id', 'line_number'], 'sales_order_lines_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
