<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_document_sequences', function (Blueprint $table): void {
            $table->enum('document_type', ['RECEIPT', 'PAYMENT', 'SALES_INVOICE', 'SALES_CREDIT_NOTE', 'PURCHASE_INVOICE', 'PURCHASE_CREDIT_NOTE', 'PURCHASE_ORDER'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('finance_document_sequences', function (Blueprint $table): void {
            $table->string('document_type', 40)->change();
        });
    }
};
