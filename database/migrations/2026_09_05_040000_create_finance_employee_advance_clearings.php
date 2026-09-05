<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_employee_advance_clearings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_advance_id')->constrained('finance_employee_advances')->restrictOnDelete();
            $table->string('document_number', 100)->unique();
            $table->date('document_date');
            $table->text('description')->nullable();
            $table->decimal('expense_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('vat_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('wht_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('net_expense_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('refund_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('additional_amount', 18, 2)->unsigned()->default(0);
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'CLEARED', 'VOID', 'REVERSED'])->default('DRAFT');
            $table->foreignId('journal_entry_id')->nullable()->unique('eac_journal_entry_uq')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique('eac_reversal_journal_uq')->constrained('journal_entries', 'id', 'eac_reversal_journal_fk')->restrictOnDelete();
            $table->char('idempotency_key', 64)->nullable()->unique();
            $table->char('reversal_key', 64)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status', 'document_date'], 'finance_employee_advance_clearings_scope_idx');
        });
        Schema::create('finance_employee_advance_clearing_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clearing_id')->constrained('finance_employee_advance_clearings')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('expense_category_id')->nullable()->constrained('finance_other_categories', 'id', 'eac_line_category_fk')->restrictOnDelete();
            $table->string('expense_category_code', 30)->nullable();
            $table->string('expense_category_name')->nullable();
            $table->foreignId('expense_account_id')->constrained('accounts', 'id', 'eac_line_account_fk')->restrictOnDelete();
            $table->string('expense_account_code', 30);
            $table->string('expense_account_name');
            $table->string('description', 500)->nullable();
            $table->string('receipt_reference', 100)->nullable();
            $table->decimal('amount', 18, 2)->unsigned();
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes', 'id', 'eac_line_tax_fk')->nullOnDelete();
            $table->string('tax_code_code', 30)->nullable();
            $table->decimal('tax_rate', 7, 4)->nullable();
            $table->decimal('tax_amount', 18, 2)->unsigned()->default(0);
            $table->foreignId('withholding_tax_code_id')->nullable()->constrained('tax_codes', 'id', 'eac_line_wht_fk')->nullOnDelete();
            $table->string('withholding_tax_code', 30)->nullable();
            $table->decimal('withholding_rate', 7, 4)->nullable();
            $table->decimal('withholding_amount', 18, 2)->unsigned()->default(0);
            $table->timestamps();
            $table->unique(['clearing_id', 'line_number'], 'finance_employee_advance_clearing_lines_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_employee_advance_clearing_lines');
        Schema::dropIfExists('finance_employee_advance_clearings');
    }
};
