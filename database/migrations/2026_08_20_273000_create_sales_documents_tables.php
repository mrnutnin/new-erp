<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->enum('document_type', ['INVOICE', 'CREDIT_NOTE']);
            $table->string('document_number', 40);
            $table->foreignId('source_invoice_id')->nullable()->constrained('sales_documents')->restrictOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignId('payment_term_id')->nullable()->constrained('finance_payment_terms')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->date('document_date');
            $table->date('posting_date')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('price_includes_vat')->default(false);
            $table->unsignedTinyInteger('tax_decimal_places')->default(2);
            $table->string('party_code', 30);
            $table->string('party_name');
            $table->char('party_tax_id', 13)->nullable();
            $table->char('party_branch_code', 5)->nullable();
            $table->text('party_address')->nullable();
            $table->decimal('subtotal', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_base', 18, 2);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2);
            $table->enum('status', ['DRAFT', 'APPROVED', 'POSTED', 'VOID'])->default('DRAFT');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_reason', 500)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'document_type', 'document_number'], 'sales_documents_number_unique');
            $table->index(['warehouse_id', 'status', 'document_date'], 'sales_documents_scope_idx');
            $table->index(['party_id', 'document_date'], 'sales_documents_party_idx');
        });

        Schema::create('sales_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained('sales_documents')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('description', 500);
            $table->decimal('quantity', 18, 4);
            $table->string('unit', 30);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->foreignId('revenue_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('tax_code_id')->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_base', 18, 2);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();

            $table->unique(['sales_document_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_lines');
        Schema::dropIfExists('sales_documents');
    }
};
