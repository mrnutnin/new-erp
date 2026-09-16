<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->char('tax_branch_code', 5)->nullable()->after('tax_id');
        });

        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->enum('tax_invoice_type', ['ABBREVIATED', 'FULL'])->nullable()->after('document_type');
            $table->string('issuer_company_name')->nullable()->after('party_address');
            $table->text('issuer_company_address')->nullable()->after('issuer_company_name');
            $table->char('issuer_tax_id', 13)->nullable()->after('issuer_company_address');
            $table->char('issuer_tax_branch_code', 5)->nullable()->after('issuer_tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->dropColumn(['tax_invoice_type', 'issuer_company_name', 'issuer_company_address', 'issuer_tax_id', 'issuer_tax_branch_code']);
        });

        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn('tax_branch_code');
        });
    }
};
