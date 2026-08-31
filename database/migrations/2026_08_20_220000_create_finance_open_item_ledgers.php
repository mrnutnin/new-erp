<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_open_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->enum('ledger_type', ['AR', 'AP']);
            $table->enum('party_type', ['CUSTOMER', 'SUPPLIER']);
            $table->string('party_id', 100);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('journal_entry_line_id')->unique()->constrained('journal_entry_lines')->restrictOnDelete();
            $table->enum('document_type', ['INVOICE', 'CREDIT_NOTE', 'RECEIPT', 'PAYMENT', 'DEPOSIT', 'DEPOSIT_APPLICATION', 'OPENING']);
            $table->string('document_number', 100);
            $table->date('document_date');
            $table->date('posting_date');
            $table->date('due_date')->nullable();
            $table->enum('balance_side', ['DEBIT', 'CREDIT']);
            $table->decimal('original_amount', 18, 2)->unsigned();
            $table->timestamps();

            $table->index(['warehouse_id', 'ledger_type', 'party_type', 'party_id', 'due_date'], 'finance_open_items_aging_idx');
            $table->index(['warehouse_id', 'account_id', 'posting_date'], 'finance_open_items_reconciliation_idx');
            $table->index('document_number', 'finance_open_items_document_idx');
        });

        Schema::create('finance_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debit_open_item_id')->constrained('finance_open_items')->restrictOnDelete();
            $table->foreignId('credit_open_item_id')->constrained('finance_open_items')->restrictOnDelete();
            $table->date('allocation_date');
            $table->decimal('amount', 18, 2)->unsigned();
            $table->string('source_type', 30);
            $table->string('source_id', 100);
            $table->char('idempotency_key', 64)->unique();
            $table->char('allocation_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->date('reversal_date')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->char('reversal_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['debit_open_item_id', 'allocation_date'], 'finance_allocations_debit_date_idx');
            $table->index(['credit_open_item_id', 'allocation_date'], 'finance_allocations_credit_date_idx');
            $table->index(['source_type', 'source_id'], 'finance_allocations_source_idx');
            $table->index('reversal_date', 'finance_allocations_reversal_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_allocations');
        Schema::dropIfExists('finance_open_items');
    }
};
