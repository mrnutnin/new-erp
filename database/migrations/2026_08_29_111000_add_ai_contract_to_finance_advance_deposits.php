<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('finance_advance_deposits', 'receipt_date')) {
            Schema::table('finance_advance_deposits', function (Blueprint $table): void {
                $table->date('receipt_date')->nullable()->after('document_date');
                $table->string('currency_code', 3)->default('THB')->after('posting_date');
                $table->enum('tax_treatment', ['VAT_OUT', 'NONE_VAT'])->default('VAT_OUT')->after('currency_code');
                $table->boolean('prices_include_vat')->default(true)->after('tax_treatment');
                $table->boolean('is_tax_point')->default(false)->after('prices_include_vat');
                $table->foreignId('tax_code_id')->nullable()->after('is_tax_point')->constrained('tax_codes')->restrictOnDelete();
                $table->decimal('tax_rate', 8, 4)->unsigned()->default(0)->after('tax_code_id');
                $table->decimal('tax_base', 18, 2)->unsigned()->default(0)->after('tax_rate');
                $table->decimal('tax_amount', 18, 2)->unsigned()->default(0)->after('tax_base');
                $table->date('tax_point_date')->nullable()->after('tax_amount');
                $table->foreignId('withholding_tax_code_id')->nullable()->after('tax_point_date')->constrained('tax_codes')->restrictOnDelete();
                $table->decimal('withholding_rate', 8, 4)->unsigned()->default(0)->after('withholding_tax_code_id');
                $table->decimal('withholding_base', 18, 2)->unsigned()->default(0)->after('withholding_rate');
                $table->decimal('withholding_amount', 18, 2)->unsigned()->default(0)->after('withholding_base');
                $table->string('withholding_certificate_reference', 100)->nullable()->after('withholding_amount');
                $table->decimal('net_amount', 18, 2)->unsigned()->default(0)->after('original_amount');
                $table->decimal('balance_amount', 18, 2)->unsigned()->default(0)->after('applied_amount');
            });
        }

        if (! Schema::hasTable('finance_advance_deposit_tenders')) {
            Schema::create('finance_advance_deposit_tenders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('advance_deposit_id')->constrained('finance_advance_deposits')->cascadeOnDelete();
                $table->foreignId('bank_account_id')->constrained('finance_bank_accounts')->restrictOnDelete();
                $table->unsignedSmallInteger('line_number');
                $table->decimal('amount', 18, 2)->unsigned();
                $table->string('reference', 100)->nullable();
                $table->timestamps();
                $table->unique(['advance_deposit_id', 'line_number'], 'finance_advance_deposit_tenders_line_unique');
            });
        }

        if (! Schema::hasColumn('pos_physical_sales', 'tax_treatment')) {
            Schema::table('pos_physical_sales', function (Blueprint $table): void {
                $table->enum('tax_treatment', ['VAT_OUT', 'NONE_VAT'])->default('VAT_OUT')->after('document_date');
                $table->boolean('prices_include_vat')->default(true)->after('tax_treatment');
            });
        }

        Schema::table('finance_document_sequences', function (Blueprint $table): void {
            $table->enum('document_type', ['RECEIPT', 'PAYMENT', 'SALES_INVOICE', 'SALES_CREDIT_NOTE', 'PURCHASE_INVOICE', 'PURCHASE_CREDIT_NOTE', 'PURCHASE_ORDER', 'INVENTORY_ADJUSTMENT', 'INVENTORY_ISSUE', 'INVENTORY_RETURN', 'SALES_RFQ', 'SALES_INTAKE', 'SALES_QUOTATION', 'SALES_ORDER', 'PHYSICAL_SALE_HS', 'PHYSICAL_SALE_IV', 'SALES_RETURN', 'CUSTOMER', 'ADVANCE_DEPOSIT_AI'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('finance_document_sequences', function (Blueprint $table): void {
            $table->enum('document_type', ['RECEIPT', 'PAYMENT', 'SALES_INVOICE', 'SALES_CREDIT_NOTE', 'PURCHASE_INVOICE', 'PURCHASE_CREDIT_NOTE', 'PURCHASE_ORDER', 'INVENTORY_ADJUSTMENT', 'INVENTORY_ISSUE', 'INVENTORY_RETURN', 'SALES_RFQ', 'SALES_INTAKE', 'SALES_QUOTATION', 'SALES_ORDER', 'PHYSICAL_SALE_HS', 'PHYSICAL_SALE_IV', 'SALES_RETURN', 'CUSTOMER'])->change();
        });
        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->dropColumn(['tax_treatment', 'prices_include_vat']);
        });
        Schema::dropIfExists('finance_advance_deposit_tenders');
        Schema::table('finance_advance_deposits', function (Blueprint $table): void {
            $table->dropForeign(['tax_code_id', 'withholding_tax_code_id']);
            $table->dropColumn(['receipt_date', 'currency_code', 'tax_treatment', 'prices_include_vat', 'is_tax_point', 'tax_code_id', 'tax_rate', 'tax_base', 'tax_amount', 'tax_point_date', 'withholding_tax_code_id', 'withholding_rate', 'withholding_base', 'withholding_amount', 'withholding_certificate_reference', 'net_amount', 'balance_amount']);
        });
    }
};
