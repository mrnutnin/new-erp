<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('document_number', 40);
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('party_code', 30);
            $table->string('party_name');
            $table->char('party_tax_id', 13)->nullable();
            $table->char('party_branch_code', 5)->nullable();
            $table->text('party_address')->nullable();
            $table->date('document_date');
            $table->date('valid_until')->nullable();
            $table->enum('status', ['DRAFT', 'SENT', 'CLOSED', 'CANCELLED'])->default('DRAFT');
            $table->string('description', 500)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'document_number'], 'sales_rfqs_number_unique');
            $table->index(['warehouse_id', 'status', 'document_date'], 'sales_rfqs_scope_idx');
            $table->index(['party_id', 'document_date'], 'sales_rfqs_party_idx');
        });

        Schema::create('sales_rfq_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_rfq_id')->constrained('sales_rfqs')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('item_id')->nullable()->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->nullable()->constrained('wms_uoms')->restrictOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 18, 4);
            $table->json('item_snapshot')->nullable();
            $table->json('uom_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['sales_rfq_id', 'line_number'], 'sales_rfq_lines_number_unique');
            $table->index(['item_id', 'uom_id'], 'sales_rfq_lines_master_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_rfq_lines');
        Schema::dropIfExists('sales_rfqs');
    }
};
