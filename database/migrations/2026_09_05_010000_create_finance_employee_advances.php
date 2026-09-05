<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_employee_advances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('finance_bank_accounts')->restrictOnDelete();
            $table->string('document_number', 100)->unique();
            $table->date('document_date');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 18, 2)->unsigned();
            $table->text('purpose');
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'PARTIAL', 'CLEARED', 'VOID', 'REVERSED'])->default('DRAFT');
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
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
            $table->char('idempotency_key', 64)->unique();
            $table->char('reversal_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['branch_id', 'warehouse_id', 'employee_user_id', 'status'], 'finance_employee_advances_scope_status_idx');
            $table->index(['due_date', 'status'], 'finance_employee_advances_due_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_employee_advances');
    }
};
