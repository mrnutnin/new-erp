<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_petty_cash_clearings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('petty_cash_fund_id')->constrained('finance_petty_cash_funds')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('clearing_date');
            $table->decimal('expected_amount', 18, 2);
            $table->decimal('actual_amount', 18, 2);
            $table->decimal('variance_amount', 18, 2);
            $table->string('reason', 500)->nullable();
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED', 'VOID'])->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['warehouse_id', 'petty_cash_fund_id', 'status', 'clearing_date'], 'finance_petty_cash_clearings_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_petty_cash_clearings');
    }
};
