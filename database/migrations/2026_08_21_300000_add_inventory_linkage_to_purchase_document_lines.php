<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_document_lines', function (Blueprint $table): void {
            $table->foreignId('item_id')->nullable()->after('description')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->nullable()->after('item_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->index(['item_id', 'uom_id'], 'purchase_document_lines_inventory_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_document_lines', function (Blueprint $table): void {
            $table->dropIndex('purchase_document_lines_inventory_idx');
            $table->dropConstrainedForeignId('uom_id');
            $table->dropConstrainedForeignId('item_id');
        });
    }
};
