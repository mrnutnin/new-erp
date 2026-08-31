<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table): void {
            $table->json('discount_approval_snapshot')->nullable()->after('approval_reason');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table): void {
            $table->dropColumn('discount_approval_snapshot');
        });
    }
};
