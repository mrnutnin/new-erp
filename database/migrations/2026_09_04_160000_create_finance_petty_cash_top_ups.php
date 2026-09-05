<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_petty_cash_top_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('petty_cash_fund_id')->constrained('finance_petty_cash_funds')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('document_number', 40)->unique();
            $table->date('document_date');
            $table->foreignId('source_bank_account_id')->constrained('finance_bank_accounts')->restrictOnDelete();
            $table->string('source_bank_account_code', 30);
            $table->string('source_bank_account_name');
            $table->foreignId('source_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('source_account_code', 30);
            $table->string('source_account_name');
            $table->foreignId('cash_bank_account_id')->constrained('finance_bank_accounts')->restrictOnDelete();
            $table->string('cash_bank_account_code', 30);
            $table->string('cash_bank_account_name');
            $table->foreignId('cash_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('cash_account_code', 30);
            $table->string('cash_account_name');
            $table->decimal('amount', 18, 2)->unsigned();
            $table->string('description', 500)->nullable();
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED', 'VOID'])->default('DRAFT');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->nullOnDelete();
            $table->char('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->nullOnDelete();
            $table->char('reversal_key', 64)->nullable()->unique();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'status', 'document_date'], 'finance_petty_cash_top_ups_scope_idx');
            $table->index(['petty_cash_fund_id', 'status', 'document_date'], 'finance_petty_cash_top_ups_fund_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_petty_cash_top_ups');
    }
};
