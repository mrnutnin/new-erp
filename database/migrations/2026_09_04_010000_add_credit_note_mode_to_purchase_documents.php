<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_documents', function (Blueprint $table): void {
            $table->enum('credit_note_mode', ['RETURN', 'NON_RETURN'])->default('NON_RETURN')->after('document_type');
            $table->index(['document_type', 'credit_note_mode', 'status'], 'purchase_documents_credit_mode_ix');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_documents', function (Blueprint $table): void {
            $table->dropIndex('purchase_documents_credit_mode_ix');
            $table->dropColumn('credit_note_mode');
        });
    }
};
