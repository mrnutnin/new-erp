<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->decimal('tax_base', 18, 2)->unsigned()->default(0)->after('discount_amount');
        });
        Schema::table('pos_physical_sale_lines', function (Blueprint $table): void {
            $table->foreignId('tax_code_id')->nullable()->after('discount_amount')->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_rate', 8, 4)->unsigned()->default(0)->after('tax_code_id');
            $table->decimal('tax_base', 18, 2)->unsigned()->default(0)->after('tax_rate');
            $table->decimal('tax_amount', 18, 2)->unsigned()->default(0)->after('tax_base');
        });
    }

    public function down(): void
    {
        Schema::table('pos_physical_sale_lines', function (Blueprint $table): void {
            $table->dropForeign(['tax_code_id']);
            $table->dropColumn(['tax_code_id', 'tax_rate', 'tax_base', 'tax_amount']);
        });
        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->dropColumn('tax_base');
        });
    }
};
