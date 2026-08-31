<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table): void {
            $table->foreignId('source_sales_intake_id')->nullable()->after('sales_rfq_id')->constrained('sales_intakes')->restrictOnDelete();
            $table->unique('source_sales_intake_id', 'sales_quotations_intake_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table): void {
            $table->dropUnique('sales_quotations_intake_unique');
            $table->dropConstrainedForeignId('source_sales_intake_id');
        });
    }
};
