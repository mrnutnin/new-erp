<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales_commission_payment_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 50)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('total_amount', 18, 2);
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'period_from', 'period_to'], 'pos_commission_payment_batch_scope_idx');
        });

        Schema::create('pos_sales_commission_payment_batch_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_batch_id');
            $table->foreign('payment_batch_id', 'pos_commission_payment_line_batch_fk')->references('id')->on('pos_sales_commission_payment_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('commission_record_id');
            $table->foreign('commission_record_id', 'pos_commission_payment_line_record_fk')->references('id')->on('pos_sales_commission_records')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['payment_batch_id', 'commission_record_id'], 'pos_commission_payment_batch_line_once_idx');
            $table->index('commission_record_id', 'pos_commission_payment_batch_record_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sales_commission_payment_batch_lines');
        Schema::dropIfExists('pos_sales_commission_payment_batches');
    }
};
