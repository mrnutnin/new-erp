<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_documents', 'purchase_documents'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('withholding_tax_code_id')->nullable()->after('tax_amount')->constrained('tax_codes')->nullOnDelete();
                $table->decimal('withholding_rate', 7, 4)->default(0)->after('withholding_tax_code_id');
                $table->decimal('withholding_base', 18, 2)->default(0)->after('withholding_rate');
                $table->decimal('withholding_amount', 18, 2)->default(0)->after('withholding_base');
            });
        }

        foreach (['sales_document_lines', 'purchase_document_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('withholding_tax_code_id')->nullable()->after('tax_code_id')->constrained('tax_codes')->nullOnDelete();
                $table->decimal('withholding_rate', 7, 4)->default(0)->after('withholding_tax_code_id');
                $table->decimal('withholding_base', 18, 2)->default(0)->after('withholding_rate');
                $table->decimal('withholding_amount', 18, 2)->default(0)->after('withholding_base');
            });
        }

        Schema::table('finance_open_items', function (Blueprint $table): void {
            $table->foreignId('withholding_tax_code_id')->nullable()->after('tax_point_date')->constrained('tax_codes')->nullOnDelete();
            $table->decimal('withholding_rate', 7, 4)->nullable()->after('withholding_tax_code_id');
            $table->decimal('withholding_base', 18, 2)->nullable()->after('withholding_rate');
            $table->decimal('withholding_amount', 18, 2)->nullable()->after('withholding_base');
        });
    }

    public function down(): void
    {
        Schema::table('finance_open_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('withholding_tax_code_id');
            $table->dropColumn(['withholding_rate', 'withholding_base', 'withholding_amount']);
        });

        foreach (['sales_document_lines', 'purchase_document_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('withholding_tax_code_id');
                $table->dropColumn(['withholding_rate', 'withholding_base', 'withholding_amount']);
            });
        }

        foreach (['sales_documents', 'purchase_documents'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('withholding_tax_code_id');
                $table->dropColumn(['withholding_rate', 'withholding_base', 'withholding_amount']);
            });
        }
    }
};
