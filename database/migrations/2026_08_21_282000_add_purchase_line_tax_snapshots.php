<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_document_lines', function (Blueprint $table) {
            $table->foreignId('tax_code_id')->nullable()->after('account_id')->constrained('tax_codes')->nullOnDelete();
            $table->decimal('tax_rate', 7, 4)->default(0)->after('tax_code_id');
            $table->decimal('tax_base', 18, 2)->default(0)->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_document_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_code_id');
            $table->dropColumn(['tax_rate', 'tax_base']);
        });
    }
};
