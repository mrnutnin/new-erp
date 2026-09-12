<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wms_cost_revaluation_runs')) {
            Schema::create('wms_cost_revaluation_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('idempotency_key', 180)->unique('wms_reval_runs_key_uq');
                $table->foreignId('root_allocation_id')->constrained('wms_cost_allocations')->restrictOnDelete();
                $table->string('status', 32)->default('PENDING_APPROVAL');
                $table->string('proposed_unit_cost', 32);
                $table->string('posting_date', 10)->nullable();
                $table->unsignedInteger('nodes_affected')->default(0);
                $table->decimal('estimated_delta_value', 20, 8)->default(0);
                $table->json('shadow_snapshot');
                $table->text('last_error')->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'created_at'], 'wms_reval_runs_status_idx');
            });
        }

        if (! Schema::hasTable('wms_cost_revaluation_deltas')) {
            Schema::create('wms_cost_revaluation_deltas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('run_id')->constrained('wms_cost_revaluation_runs')->restrictOnDelete();
                $table->foreignId('allocation_id')->constrained('wms_cost_allocations')->restrictOnDelete();
                $table->string('idempotency_key', 180)->unique('wms_reval_delta_key_uq');
                $table->string('status', 32)->default('PLANNED');
                $table->string('old_unit_cost', 32);
                $table->string('new_unit_cost', 32);
                $table->decimal('delta_value', 20, 8);
                $table->timestamps();
                $table->unique(['run_id', 'allocation_id'], 'wms_reval_delta_run_alloc_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_cost_revaluation_deltas');
        Schema::dropIfExists('wms_cost_revaluation_runs');
    }
};
