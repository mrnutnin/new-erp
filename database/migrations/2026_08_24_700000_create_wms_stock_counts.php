<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wms_stock_count_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('document_number', 80);
            $table->date('document_date');
            $table->enum('status', ['DRAFT', 'COUNTED', 'APPROVED', 'POSTED', 'VOID', 'REVERSED'])->default('DRAFT');
            $table->string('reason', 500)->nullable();
            $table->string('idempotency_key', 180)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['warehouse_id', 'document_number']);
            $table->index(['warehouse_id', 'document_date', 'status'], 'wms_count_scope_date_status_idx');
        });

        Schema::create('wms_stock_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('wms_stock_count_documents')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('snapshot_quantity', 20, 8)->default(0);
            $table->decimal('counted_quantity', 20, 8)->nullable();
            $table->decimal('variance_quantity', 20, 8)->nullable();
            $table->decimal('snapshot_unit_cost', 20, 8)->default(0);
            $table->decimal('variance_value', 20, 8)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'line_number']);
            $table->unique(['document_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_stock_count_lines');
        Schema::dropIfExists('wms_stock_count_documents');
    }
};
