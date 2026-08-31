<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales_commission_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->string('basis', 20)->index();
            $table->decimal('rate', 7, 4)->unsigned();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'effective_from', 'effective_to'], 'pos_commission_plans_active_dates_idx');
        });

        Schema::create('pos_sales_commission_plan_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_plan_id')->constrained('pos_sales_commission_plans')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['commission_plan_id', 'user_id', 'warehouse_id'], 'pos_commission_plan_user_warehouse_unique');
            $table->index(['user_id', 'warehouse_id'], 'pos_commission_assignment_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sales_commission_plan_assignments');
        Schema::dropIfExists('pos_sales_commission_plans');
    }
};
