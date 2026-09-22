<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_orders', 'required_delivery_date')) {
                $table->date('required_delivery_date')->nullable()->after('valid_until');
            }
        });

        Schema::create('production_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('issue_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('receipt_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('document_number', 50)->unique();
            $table->enum('order_type', ['MAKE_TO_ORDER', 'MAKE_TO_STOCK']);
            $table->enum('status', ['DRAFT', 'RELEASED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])->default('DRAFT');
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines')->nullOnDelete();
            $table->unsignedInteger('source_revision')->nullable();
            $table->date('required_delivery_date')->nullable();
            $table->text('customer_specification')->nullable();
            $table->foreignId('finished_item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('planned_quantity', 20, 8);
            $table->decimal('completed_quantity', 20, 8)->default(0);
            $table->decimal('reject_quantity', 20, 8)->default(0);
            $table->foreignId('bom_revision_id')->constrained('production_bom_revisions')->restrictOnDelete();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_finish_date')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['sales_order_line_id', 'source_revision'], 'production_orders_so_line_revision_uq');
            $table->index(['branch_id', 'status', 'planned_start_date'], 'production_orders_branch_status_idx');
            $table->index(['sales_order_id', 'status'], 'production_orders_sales_order_idx');
        });

        Schema::create('production_order_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('source_bom_line_id')->nullable()->constrained('production_bom_lines')->nullOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('required_quantity', 20, 8);
            $table->string('override_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['production_order_id', 'line_number'], 'production_order_materials_line_uq');
        });

        Schema::create('production_order_scraps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('source_material_line_id')->nullable()->constrained('production_order_materials')->nullOnDelete();
            $table->enum('scrap_type', ['RECOVERABLE_SCRAP', 'NON_RECOVERABLE_SCRAP']);
            $table->foreignId('scrap_item_id')->nullable()->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->decimal('recovery_unit_value', 20, 8)->nullable();
            $table->decimal('recovery_total_value', 20, 8)->nullable();
            $table->string('reason', 500);
            $table->enum('status', ['DRAFT', 'REPORTED', 'POSTED', 'REVERSED'])->default('REPORTED');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->index(['production_order_id', 'scrap_type', 'status'], 'production_order_scraps_order_idx');
        });

        Schema::create('production_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->string('event_type', 80);
            $table->string('source_type', 80)->nullable();
            $table->string('source_id', 80)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['production_order_id', 'occurred_at'], 'production_order_events_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_events');
        Schema::dropIfExists('production_order_scraps');
        Schema::dropIfExists('production_order_materials');
        Schema::dropIfExists('production_orders');
        Schema::table('sales_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_orders', 'required_delivery_date')) {
                $table->dropColumn('required_delivery_date');
            }
        });
    }
};
