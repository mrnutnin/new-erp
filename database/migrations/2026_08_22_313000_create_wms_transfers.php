<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('document_number', 40);
            $table->date('document_date');
            $table->enum('status', ['DRAFT', 'DISPATCHED', 'ACCEPTED', 'PARTIALLY_ACCEPTED', 'REJECTED', 'VOID'])->default('DRAFT');
            $table->string('idempotency_key', 160)->unique();
            $table->text('dispatch_reason')->nullable();
            $table->text('reject_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['source_warehouse_id', 'document_number'], 'wms_transfers_source_doc_unique');
            $table->index(['destination_warehouse_id', 'status', 'document_date'], 'wms_transfers_dest_status_date_idx');
        });

        Schema::create('wms_transfer_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained('wms_transfers')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->decimal('planned_quantity', 20, 8);
            $table->decimal('planned_base_quantity', 20, 8);
            $table->timestamps();
            $table->unique(['transfer_id', 'line_number'], 'wms_transfer_lines_doc_line_unique');
            $table->index(['item_id', 'uom_id'], 'wms_transfer_lines_item_uom_idx');
        });

        Schema::create('wms_transfer_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained('wms_transfers')->restrictOnDelete();
            $table->foreignId('transfer_line_id')->constrained('wms_transfer_lines')->restrictOnDelete();
            $table->enum('event_type', ['DISPATCH', 'ACCEPT', 'REJECT']);
            $table->decimal('quantity', 20, 8);
            $table->decimal('base_quantity', 20, 8);
            $table->date('business_date');
            $table->foreignId('stock_movement_id')->nullable()->constrained('wms_stock_movements')->restrictOnDelete();
            $table->string('idempotency_key', 180)->unique();
            $table->string('source_reference', 100);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['transfer_line_id', 'event_type'], 'wms_transfer_events_line_type_idx');
            $table->index(['transfer_id', 'business_date'], 'wms_transfer_events_doc_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_transfer_events');
        Schema::dropIfExists('wms_transfer_lines');
        Schema::dropIfExists('wms_transfers');
    }
};
