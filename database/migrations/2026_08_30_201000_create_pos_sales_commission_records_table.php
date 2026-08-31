<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales_commission_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_plan_id')->constrained('pos_sales_commission_plans')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('physical_sale_id')->constrained('pos_physical_sales')->restrictOnDelete();
            $table->foreignId('physical_sale_line_id')->nullable()->constrained('pos_physical_sale_lines')->restrictOnDelete();
            $table->string('source_type', 40);
            $table->string('source_id', 80);
            $table->decimal('base_amount', 18, 2);
            $table->decimal('rate_percent', 7, 4)->unsigned();
            $table->decimal('commission_amount', 18, 2);
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('calculated_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('pos_sales_commission_records')->restrictOnDelete();
            $table->json('snapshot');
            $table->string('idempotency_key', 180)->unique();
            $table->timestamps();

            $table->index(['warehouse_id', 'status', 'calculated_at'], 'pos_commission_records_scope_idx');
            $table->index(['physical_sale_id', 'physical_sale_line_id'], 'pos_commission_records_sale_line_idx');
            $table->index(['recipient_user_id', 'status'], 'pos_commission_records_recipient_idx');
            $table->index(['source_type', 'source_id'], 'pos_commission_records_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sales_commission_records');
    }
};
