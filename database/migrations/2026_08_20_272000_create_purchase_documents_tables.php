<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->enum('document_type', ['INVOICE', 'CREDIT_NOTE']);
            $table->foreignId('original_document_id')->nullable()->constrained('purchase_documents')->restrictOnDelete();
            $table->string('document_number', 40);
            $table->date('document_date');
            $table->date('posting_date')->nullable();
            $table->foreignId('supplier_id')->constrained('parties')->restrictOnDelete();
            $table->string('supplier_code', 30);
            $table->string('supplier_name');
            $table->char('supplier_tax_id', 13)->nullable();
            $table->char('supplier_branch_code', 5)->default('00000');
            $table->text('supplier_address')->nullable();
            $table->foreignId('payment_term_id')->nullable()->constrained('finance_payment_terms')->restrictOnDelete();
            $table->date('due_date')->nullable();
            $table->string('tax_treatment', 20)->default('NONE_VAT');
            $table->boolean('prices_include_vat')->default(false);
            $table->unsignedTinyInteger('tax_decimal_places')->default(2);
            $table->decimal('subtotal', 18, 2)->unsigned();
            $table->decimal('tax_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('gross_amount', 18, 2)->unsigned();
            $table->decimal('rounding_amount', 18, 2)->default(0);
            $table->enum('status', ['DRAFT', 'APPROVED', 'POSTED', 'VOID'])->default('DRAFT');
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->string('description', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_reason', 500)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'document_type', 'document_number'], 'purchase_documents_number_unique');
            $table->index(['warehouse_id', 'document_type', 'document_date', 'status'], 'purchase_documents_list_idx');
            $table->index(['supplier_id', 'status', 'due_date'], 'purchase_documents_supplier_idx');
        });

        Schema::create('purchase_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_document_id')->constrained('purchase_documents')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('description', 500);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('quantity', 18, 4)->unsigned();
            $table->decimal('unit_price', 18, 4)->unsigned();
            $table->decimal('discount_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('net_amount', 18, 2)->unsigned();
            $table->decimal('tax_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('gross_amount', 18, 2)->unsigned();
            $table->timestamps();

            $table->unique(['purchase_document_id', 'line_number'], 'purchase_document_lines_number_unique');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_document_lines');
        Schema::dropIfExists('purchase_documents');
    }
};
