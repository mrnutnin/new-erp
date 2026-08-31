<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->enum('movement_type', ['RECEIPT', 'ISSUE', 'TRANSFER', 'ADJUSTMENT', 'COUNT']);
            $table->enum('direction', ['IN', 'OUT']);
            $table->enum('status', ['DRAFT', 'POSTED', 'REVERSED'])->default('DRAFT');
            $table->decimal('quantity', 20, 8);
            $table->decimal('base_quantity', 20, 8);
            $table->date('business_date');
            $table->string('source_type', 80)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->string('source_reference', 100)->nullable();
            $table->string('transfer_key', 100)->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'item_id', 'business_date', 'status'], 'wms_stock_movements_balance_idx');
            $table->index(['source_type', 'source_id'], 'wms_stock_movements_source_idx');
            $table->index('transfer_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_stock_movements');
    }
};
