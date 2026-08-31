<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_intakes', function (Blueprint $table): void {
            $table->foreignId('prepared_by')->nullable()->after('party_address')->constrained('users')->nullOnDelete();
            $table->string('order_method', 30)->nullable();
            $table->string('delivery_method', 30)->nullable();
            $table->date('appointment_date')->nullable();
            $table->enum('tax_treatment', ['NONE_VAT', 'VAT_OUT'])->default('NONE_VAT');
            $table->boolean('prices_include_vat')->default(false);
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();
            $table->unsignedTinyInteger('tax_decimal_places')->default(2);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_base', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
        });

        Schema::table('sales_intake_lines', function (Blueprint $table): void {
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_base', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sales_intake_lines', function (Blueprint $table): void {
            $table->dropForeign(['tax_code_id']);
            $table->dropColumn(['discount_amount', 'tax_code_id', 'tax_rate', 'tax_base', 'tax_amount', 'line_total']);
        });
        Schema::table('sales_intakes', function (Blueprint $table): void {
            $table->dropForeign(['prepared_by']);
            $table->dropColumn(['prepared_by', 'order_method', 'delivery_method', 'appointment_date', 'tax_treatment', 'prices_include_vat', 'billing_address', 'shipping_address', 'tax_decimal_places', 'subtotal', 'discount_amount', 'tax_base', 'tax_amount', 'grand_total']);
        });
    }
};
