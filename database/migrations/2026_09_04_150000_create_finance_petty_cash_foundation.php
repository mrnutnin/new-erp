<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_petty_cash_funds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('finance_bank_accounts')->restrictOnDelete();
            $table->foreignId('custodian_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('fund_limit', 18, 2)->unsigned()->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['warehouse_id', 'bank_account_id'], 'finance_petty_cash_funds_warehouse_cash_unique');
        });

        Schema::create('finance_petty_cash_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('petty_cash_fund_id')->constrained('finance_petty_cash_funds')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('document_number', 40)->unique();
            $table->date('document_date');
            $table->string('payee_name', 255)->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('total_amount', 18, 2)->unsigned()->default(0);
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

            $table->index(['warehouse_id', 'status', 'document_date'], 'finance_petty_cash_vouchers_scope_idx');
            $table->index(['petty_cash_fund_id', 'status', 'document_date'], 'finance_petty_cash_vouchers_fund_idx');
        });

        Schema::create('finance_petty_cash_voucher_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('petty_cash_voucher_id')->constrained('finance_petty_cash_vouchers')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('expense_category_id')->nullable()->constrained('finance_other_categories')->restrictOnDelete();
            $table->string('expense_category_code', 30);
            $table->string('expense_category_name');
            $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('expense_account_code', 30);
            $table->string('expense_account_name');
            $table->string('description', 500)->nullable();
            $table->string('receipt_reference', 100)->nullable();
            $table->decimal('amount', 18, 2)->unsigned();
            $table->timestamps();

            $table->unique(['petty_cash_voucher_id', 'line_number'], 'finance_petty_cash_voucher_lines_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_petty_cash_voucher_lines');
        Schema::dropIfExists('finance_petty_cash_vouchers');
        Schema::dropIfExists('finance_petty_cash_funds');
    }
};
