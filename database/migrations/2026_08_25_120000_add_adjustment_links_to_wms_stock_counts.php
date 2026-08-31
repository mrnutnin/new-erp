<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_stock_count_documents', function (Blueprint $table): void {
            $table->json('adjustment_document_ids')->nullable()->after('posted_by');
        });
    }

    public function down(): void
    {
        Schema::table('wms_stock_count_documents', function (Blueprint $table): void {
            $table->dropColumn('adjustment_document_ids');
        });
    }
};
