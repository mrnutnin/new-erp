<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('on_hand', 20, 8)->default(0);
            $table->decimal('reserved', 20, 8)->default(0);
            $table->decimal('available', 20, 8)->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'item_id', 'uom_id']);
        });
        Schema::create('wms_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->enum('status', ['OPEN', 'RELEASED', 'CONSUMED'])->default('OPEN')->index();
            $table->string('source_type', 80)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_stock_reservations');
        Schema::dropIfExists('wms_stock_balances');
    }
};
