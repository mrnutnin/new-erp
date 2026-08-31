<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->dropUnique('pos_physical_sales_source_unique');
            $table->index(['source_type', 'source_id', 'status'], 'pos_physical_sales_source_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->dropIndex('pos_physical_sales_source_status_idx');
            $table->unique(['source_type', 'source_id', 'document_type'], 'pos_physical_sales_source_unique');
        });
    }
};
