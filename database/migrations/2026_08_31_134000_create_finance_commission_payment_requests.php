<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_employee_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('party_id')->unique()->constrained('parties')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('finance_commission_payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 50)->unique();
            $table->unsignedBigInteger('payment_batch_id');
            $table->foreign('payment_batch_id', 'finance_commission_request_parent_fk')->references('id')->on('pos_sales_commission_payment_batches')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('supplier_party_id')->constrained('parties')->restrictOnDelete();
            $table->date('document_date');
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('payment_voucher_id')->nullable()->constrained('finance_payment_vouchers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_batch_id', 'recipient_user_id'], 'finance_commission_request_once_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_commission_payment_requests');
        Schema::dropIfExists('finance_employee_suppliers');
    }
};
