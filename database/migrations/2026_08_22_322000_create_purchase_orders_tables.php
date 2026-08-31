<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('parties')->restrictOnDelete();
            $table->string('supplier_code', 30);
            $table->string('supplier_name');
            $table->foreignId('payment_term_id')->nullable()->constrained('finance_payment_terms')->restrictOnDelete();
            $table->string('document_number', 40);
            $table->date('document_date');
            $table->date('expected_date')->nullable();
            $table->decimal('subtotal', 18, 2)->unsigned()->default(0);
            $table->decimal('total_amount', 18, 2)->unsigned()->default(0);
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
            $table->unique(['warehouse_id', 'document_number']);
            $table->index(['warehouse_id', 'status', 'document_date']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('item_id')->nullable()->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->nullable()->constrained('wms_uoms')->restrictOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 18, 4)->unsigned();
            $table->decimal('unit_price', 18, 4)->unsigned();
            $table->decimal('line_total', 18, 2)->unsigned();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'line_number']);
            $table->index(['item_id', 'uom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
