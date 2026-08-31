<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_document_lines', function (Blueprint $table): void {
            $table->json('price_snapshot')->nullable()->after('conversion_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('sales_document_lines', function (Blueprint $table): void {
            $table->dropColumn('price_snapshot');
        });
    }
};
