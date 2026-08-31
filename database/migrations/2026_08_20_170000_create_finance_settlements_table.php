<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_settlements', function (Blueprint $table) {
            $table->id();
            $table->enum('document_type', ['RECEIPT', 'PAYMENT']);
            $table->string('document_number', 40)->unique();
            $table->date('document_date');
            $table->date('settlement_date');
            $table->string('party_type', 30)->nullable();
            $table->string('party_id', 100)->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained('finance_bank_accounts')->nullOnDelete();
            $table->foreignId('payment_term_id')->nullable()->constrained('finance_payment_terms')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('withholding_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->default(0);
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['document_type', 'settlement_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settlements');
    }
};
