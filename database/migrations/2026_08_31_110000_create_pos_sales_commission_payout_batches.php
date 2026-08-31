<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales_commission_payout_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 50)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('finance_bank_accounts')->restrictOnDelete();
            $table->string('currency_code', 3)->default('THB');
            $table->date('document_date');
            $table->decimal('total_amount', 18, 2);
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'warehouse_id', 'recipient_user_id', 'status'], 'pos_commission_payout_scope_idx');
        });

        Schema::create('pos_sales_commission_payout_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payout_batch_id')->constrained('pos_sales_commission_payout_batches')->cascadeOnDelete();
            $table->foreignId('commission_record_id')->constrained('pos_sales_commission_records')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['payout_batch_id', 'commission_record_id'], 'pos_commission_payout_line_once_idx');
            $table->index('commission_record_id', 'pos_commission_payout_record_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sales_commission_payout_lines');
        Schema::dropIfExists('pos_sales_commission_payout_batches');
    }
};
