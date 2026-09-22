<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wms_stock_reservations', 'consumed_quantity')) {
            Schema::table('wms_stock_reservations', function (Blueprint $table): void {
                $table->decimal('consumed_quantity', 20, 8)->default(0)->after('quantity');
            });
        }

        if (! Schema::hasTable('wms_stock_reservation_consumptions')) {
            Schema::create('wms_stock_reservation_consumptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('stock_reservation_id')->constrained('wms_stock_reservations')->restrictOnDelete();
                $table->foreignId('stock_movement_id')->constrained('wms_stock_movements')->restrictOnDelete();
                $table->decimal('quantity', 20, 8);
                $table->timestamps();

                $table->unique('stock_movement_id', 'wms_reservation_consumption_movement_uq');
                $table->index(['stock_reservation_id', 'id'], 'wms_reservation_consumption_reservation_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_stock_reservation_consumptions');

        if (Schema::hasColumn('wms_stock_reservations', 'consumed_quantity')) {
            Schema::table('wms_stock_reservations', fn (Blueprint $table) => $table->dropColumn('consumed_quantity'));
        }
    }
};
