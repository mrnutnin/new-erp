<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wms_issue_documents', function (Blueprint $t): void {
            $t->id(); $t->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->string('document_number', 80)->unique(); $t->date('document_date');
            $t->enum('status', ['DRAFT','APPROVED','POSTED','VOID'])->default('DRAFT');
            $t->string('issue_type', 40)->default('GENERAL'); $t->string('reason', 500);
            $t->string('idempotency_key', 180)->unique(); $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps(); $t->softDeletes(); $t->index(['warehouse_id','document_date','status']);
        });
        Schema::create('wms_issue_lines', function (Blueprint $t): void {
            $t->id(); $t->foreignId('document_id')->constrained('wms_issue_documents')->restrictOnDelete();
            $t->foreignId('item_id')->constrained('wms_items')->restrictOnDelete(); $t->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $t->decimal('quantity',20,8); $t->foreignId('stock_movement_id')->nullable()->unique()->constrained('wms_stock_movements')->restrictOnDelete();
            $t->foreignId('cost_allocation_id')->nullable()->constrained('wms_cost_allocations')->restrictOnDelete(); $t->unsignedInteger('line_number');
            $t->timestamps(); $t->softDeletes(); $t->index(['document_id','line_number']);
        });
        Schema::create('wms_issue_returns', function (Blueprint $t): void {
            $t->id(); $t->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->string('document_number',80)->unique(); $t->date('document_date'); $t->enum('status',['DRAFT','APPROVED','POSTED','VOID'])->default('DRAFT');
            $t->string('reason',500); $t->foreignId('issue_document_id')->constrained('wms_issue_documents')->restrictOnDelete();
            $t->string('idempotency_key',180)->unique(); $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps(); $t->softDeletes(); $t->index(['warehouse_id','document_date','status']);
        });
        Schema::create('wms_issue_return_lines', function (Blueprint $t): void {
            $t->id(); $t->foreignId('return_id')->constrained('wms_issue_returns')->restrictOnDelete(); $t->foreignId('issue_line_id')->constrained('wms_issue_lines')->restrictOnDelete();
            $t->decimal('quantity',20,8); $t->foreignId('stock_movement_id')->nullable()->unique()->constrained('wms_stock_movements')->restrictOnDelete(); $t->foreignId('cost_allocation_id')->nullable()->constrained('wms_cost_allocations')->restrictOnDelete(); $t->unsignedInteger('line_number'); $t->timestamps();
            $t->softDeletes(); $t->index(['return_id','line_number']);
        });
    }
    public function down(): void { Schema::dropIfExists('wms_issue_return_lines'); Schema::dropIfExists('wms_issue_returns'); Schema::dropIfExists('wms_issue_lines'); Schema::dropIfExists('wms_issue_documents'); }
};
