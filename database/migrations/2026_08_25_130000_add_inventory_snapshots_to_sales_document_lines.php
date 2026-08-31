<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_document_lines', function (Blueprint $table): void {
            $table->foreignId('item_id')->nullable()->after('sales_document_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->nullable()->after('item_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->foreignId('stock_uom_id')->nullable()->after('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('uom_factor', 18, 8)->nullable()->after('unit');
            $table->json('conversion_snapshot')->nullable()->after('uom_factor');
            $table->index(['item_id', 'uom_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_document_lines', function (Blueprint $table): void {
            $table->dropIndex(['item_id', 'uom_id']);
            $table->dropForeign(['item_id']);
            $table->dropForeign(['uom_id']);
            $table->dropForeign(['stock_uom_id']);
            $table->dropColumn(['item_id', 'uom_id', 'stock_uom_id', 'uom_factor', 'conversion_snapshot']);
        });
    }
};
