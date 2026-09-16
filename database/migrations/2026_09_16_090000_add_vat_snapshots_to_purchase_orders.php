<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('tax_treatment', 20)->default('NONE_VAT')->after('expected_date');
            $table->boolean('prices_include_vat')->default(false)->after('tax_treatment');
            $table->unsignedTinyInteger('tax_decimal_places')->default(2)->after('prices_include_vat');
            $table->decimal('tax_amount', 18, 2)->unsigned()->default(0)->after('subtotal');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->foreignId('tax_code_id')->nullable()->after('uom_id')->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_rate', 8, 4)->unsigned()->default(0)->after('line_total');
            $table->decimal('tax_base', 18, 2)->unsigned()->default(0)->after('tax_rate');
            $table->decimal('tax_amount', 18, 2)->unsigned()->default(0)->after('tax_base');
            $table->decimal('gross_amount', 18, 2)->unsigned()->default(0)->after('tax_amount');
        });

        DB::table('purchase_order_lines')->update([
            'tax_base' => DB::raw('line_total'),
            'gross_amount' => DB::raw('line_total'),
        ]);
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_code_id');
            $table->dropColumn(['tax_rate', 'tax_base', 'tax_amount', 'gross_amount']);
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['tax_treatment', 'prices_include_vat', 'tax_decimal_places', 'tax_amount']);
        });
    }
};
