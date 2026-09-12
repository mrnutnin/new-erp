<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wms_inventory_adjustment_documents', 'document_context')) {
            Schema::table('wms_inventory_adjustment_documents', function (Blueprint $table): void {
                $table->string('document_context', 40)->default('INVENTORY_ADJUSTMENT')->after('direction');
                $table->index(['warehouse_id', 'document_context', 'document_date'], 'wms_adj_documents_context_date_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('wms_inventory_adjustment_documents', 'document_context')) {
            Schema::table('wms_inventory_adjustment_documents', function (Blueprint $table): void {
                $table->dropIndex('wms_adj_documents_context_date_idx');
                $table->dropColumn('document_context');
            });
        }
    }
};
