<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pos_billing_note_lines', function (Blueprint $table): void {
            $table->dropForeign(['sales_document_id']);
            $table->dropUnique('pos_billing_note_lines_sales_document_id_unique');
            $table->index('sales_document_id', 'pos_billing_note_lines_sales_document_id_idx');
            $table->foreign('sales_document_id')->references('id')->on('sales_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_billing_note_lines', function (Blueprint $table): void {
            $table->dropForeign(['sales_document_id']);
            $table->dropIndex('pos_billing_note_lines_sales_document_id_idx');
            $table->unique('sales_document_id');
            $table->foreign('sales_document_id')->references('id')->on('sales_documents')->restrictOnDelete();
        });
    }
};
