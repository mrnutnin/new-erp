<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')) {
            Schema::table('wms_inventory_adjustment_documents', function (Blueprint $table): void {
                $table->unsignedBigInteger('source_issue_id')->nullable()->after('document_context');
                $table->index('source_issue_id', 'wms_adj_documents_source_issue_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')) {
            Schema::table('wms_inventory_adjustment_documents', function (Blueprint $table): void {
                $table->dropIndex('wms_adj_documents_source_issue_idx');
                $table->dropColumn('source_issue_id');
            });
        }
    }
};
