<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('physical_sale_id')->constrained('pos_physical_sales')->restrictOnDelete();
            $table->string('document_number', 40);
            $table->date('document_date');
            $table->string('reason', 500);
            $table->string('party_code', 30);
            $table->string('party_name');
            $table->text('party_address')->nullable();
            $table->decimal('total_amount', 18, 2)->unsigned()->default(0);
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'document_number'], 'pos_sales_returns_number_unique');
            $table->index(['warehouse_id', 'status', 'document_date'], 'pos_sales_returns_scope_idx');
            $table->index('physical_sale_id', 'pos_sales_returns_sale_idx');
        });

        Schema::create('pos_sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('pos_sales_returns')->cascadeOnDelete();
            $table->foreignId('physical_sale_line_id')->constrained('pos_physical_sale_lines')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('quantity', 18, 8)->unsigned();
            $table->decimal('unit_price', 18, 4)->unsigned()->default(0);
            $table->decimal('line_total', 18, 2)->unsigned()->default(0);
            $table->json('item_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['sales_return_id', 'line_number'], 'pos_sales_return_lines_number_unique');
            $table->unique(['sales_return_id', 'physical_sale_line_id'], 'pos_sales_return_lines_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sales_return_lines');
        Schema::dropIfExists('pos_sales_returns');
    }
};
