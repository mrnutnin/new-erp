<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_bank_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('finance_bank_accounts')->restrictOnDelete();
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->nullOnDelete();
            $table->date('statement_date');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('closing_balance', 18, 2)->default(0);
            $table->string('source_file_name')->nullable();
            $table->enum('status', ['DRAFT', 'RECONCILED'])->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['bank_account_id', 'statement_date']);
        });

        Schema::create('accounting_bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained('accounting_bank_statements')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->date('transaction_date');
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('running_balance', 18, 2)->nullable();
            $table->enum('status', ['UNMATCHED', 'MATCHED', 'ADJUSTED'])->default('UNMATCHED');
            $table->foreignId('matched_journal_entry_line_id')->nullable()->constrained('journal_entry_lines', 'id', 'bank_stmt_line_journal_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique(['bank_statement_id', 'line_number'], 'bank_stmt_line_number_uq');
            $table->index(['transaction_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_bank_statement_lines');
        Schema::dropIfExists('accounting_bank_statements');
    }
};
