<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_payment_vouchers')) {
            Schema::create('finance_payment_vouchers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->enum('voucher_type', ['PRE_PAYMENT', 'PAYMENT']);
                $table->string('document_number', 40)->unique();
                $table->date('document_date');
                $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
                $table->foreignId('bank_account_id')->nullable()->constrained('finance_bank_accounts')->nullOnDelete();
                $table->decimal('amount', 18, 2)->default(0);
                $table->string('description', 500)->nullable();
                $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'VOID'])->default('DRAFT');
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('settlement_id')->nullable()->unique()->constrained('finance_settlements')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['warehouse_id', 'voucher_type', 'document_date', 'status'], 'finance_voucher_scope_idx');
            });
        } else {
            $indexes = collect(Schema::getIndexes('finance_payment_vouchers'))->pluck('name')->all();
            if (! in_array('finance_voucher_scope_idx', $indexes, true)) {
                Schema::table('finance_payment_vouchers', function (Blueprint $table): void {
                    $table->index(['warehouse_id', 'voucher_type', 'document_date', 'status'], 'finance_voucher_scope_idx');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payment_vouchers');
    }
};
