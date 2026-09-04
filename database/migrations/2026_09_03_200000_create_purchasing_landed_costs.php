<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchasing_landed_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('document_number', 80)->unique();
            $table->date('business_date');
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'VOID'])->default('DRAFT');
            $table->enum('allocation_basis', ['VALUE', 'QUANTITY', 'WEIGHT']);
            $table->string('currency_code', 3)->default('THB');
            $table->decimal('total_amount', 20, 8)->default(0);
            $table->string('idempotency_key', 180)->unique();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'business_date', 'status'], 'purchasing_landed_cost_scope_idx');
        });

        Schema::create('purchasing_landed_cost_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landed_cost_id')->constrained('purchasing_landed_costs')->cascadeOnDelete();
            $table->string('expense_source_type', 40);
            $table->unsignedBigInteger('expense_source_id')->nullable();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 20, 8);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->string('description', 500)->nullable();
            $table->timestamps();
            $table->index(['expense_source_type', 'expense_source_id'], 'purchasing_landed_cost_source_idx');
        });

        Schema::create('purchasing_landed_cost_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landed_cost_id')->constrained('purchasing_landed_costs')->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->decimal('selected_value', 20, 8);
            $table->decimal('allocated_amount', 20, 8)->default(0);
            $table->timestamps();
            $table->unique(['landed_cost_id', 'goods_receipt_id'], 'purchasing_landed_cost_receipt_unique');
        });

        Schema::create('purchasing_landed_cost_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landed_cost_id')->constrained('purchasing_landed_costs')->restrictOnDelete();
            $table->foreignId('landed_cost_line_id')->constrained('purchasing_landed_cost_lines')->restrictOnDelete();
            $table->foreignId('goods_receipt_line_id')->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('basis_amount', 20, 8);
            $table->decimal('allocation_ratio', 20, 12);
            $table->decimal('allocated_amount', 20, 8);
            $table->foreignId('wms_cost_allocation_id')->nullable()->unique();
            $table->foreign('wms_cost_allocation_id', 'plca_wms_allocation_fk')->references('id')->on('wms_cost_allocations')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->enum('status', ['PENDING', 'POSTED', 'REVERSED'])->default('PENDING');
            $table->string('idempotency_key', 180)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['landed_cost_id', 'status'], 'purchasing_landed_cost_allocation_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchasing_landed_cost_allocations');
        Schema::dropIfExists('purchasing_landed_cost_receipts');
        Schema::dropIfExists('purchasing_landed_cost_lines');
        Schema::dropIfExists('purchasing_landed_costs');
    }
};
