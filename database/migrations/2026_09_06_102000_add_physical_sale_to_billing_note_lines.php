<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pos_billing_note_lines', function (Blueprint $table): void {
            $table->dropForeign(['sales_document_id']);
            $table->unsignedBigInteger('sales_document_id')->nullable()->change();
            $table->foreignId('physical_sale_id')->nullable()->after('sales_document_id')->constrained('pos_physical_sales')->restrictOnDelete();
            $table->index('physical_sale_id', 'pos_billing_note_lines_physical_sale_id_idx');
        });
        Schema::table('pos_billing_note_lines', function (Blueprint $table): void {
            $table->foreign('sales_document_id')->references('id')->on('sales_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_billing_note_lines', function (Blueprint $table): void {
            $table->dropForeign(['physical_sale_id']);
            $table->dropIndex('pos_billing_note_lines_physical_sale_id_idx');
            $table->dropColumn('physical_sale_id');
            $table->dropForeign(['sales_document_id']);
            $table->unsignedBigInteger('sales_document_id')->nullable(false)->change();
            $table->foreign('sales_document_id')->references('id')->on('sales_documents')->restrictOnDelete();
        });
    }
};
