<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wms_cost_recalculation_requests')) {
            Schema::create('wms_cost_recalculation_requests', function (Blueprint $table): void {
                $table->id();
                $table->string('idempotency_key', 120)->unique();
                $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
                $table->foreignId('trigger_movement_id')->constrained('wms_stock_movements')->restrictOnDelete();
                $table->enum('status', ['PENDING', 'PROCESSING', 'RESOLVED', 'FAILED'])->default('PENDING');
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->index(['warehouse_id', 'item_id', 'status'], 'wms_recost_request_scope_idx');
            });
        } else {
            $indexes = collect(Schema::getIndexes('wms_cost_recalculation_requests'))->pluck('name')->all();
            if (! in_array('wms_recost_request_scope_idx', $indexes, true)) {
                Schema::table('wms_cost_recalculation_requests', function (Blueprint $table): void {
                    $table->index(['warehouse_id', 'item_id', 'status'], 'wms_recost_request_scope_idx');
                });
            }
        }

        Schema::table('wms_stock_cost_layers', function (Blueprint $table): void {
            if (! Schema::hasColumn('wms_stock_cost_layers', 'recost_request_id')) {
                $table->foreignId('recost_request_id')->nullable()->after('cost_status')->constrained('wms_cost_recalculation_requests')->nullOnDelete();
            }
            if (! Schema::hasColumn('wms_stock_cost_layers', 'resolved_by_movement_id')) {
                $table->foreignId('resolved_by_movement_id')->nullable()->after('recost_request_id')->constrained('wms_stock_movements')->restrictOnDelete();
            }
            if (! Schema::hasColumn('wms_stock_cost_layers', 'cost_delta')) {
                $table->decimal('cost_delta', 20, 8)->default(0)->after('resolved_by_movement_id');
            }
            if (! Schema::hasColumn('wms_stock_cost_layers', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('cost_delta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wms_stock_cost_layers', function (Blueprint $table): void {
            $table->dropForeign(['recost_request_id']);
            $table->dropForeign(['resolved_by_movement_id']);
            $table->dropColumn(['recost_request_id', 'resolved_by_movement_id', 'cost_delta', 'resolved_at']);
        });
        Schema::dropIfExists('wms_cost_recalculation_requests');
    }
};
