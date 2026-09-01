<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_maintenance_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('title', 255);
            $table->unsignedInteger('interval_days')->nullable();
            $table->unsignedInteger('interval_months')->nullable();
            $table->date('next_due_date');
            $table->date('last_completed_date')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('default_priority', ['LOW', 'NORMAL', 'HIGH', 'CRITICAL'])->default('NORMAL');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_alerted_at')->nullable();
            $table->foreignId('last_completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'is_active', 'next_due_date'], 'asset_maint_sched_due_idx');
            $table->index(['asset_id', 'is_active'], 'asset_maint_sched_asset_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_schedules');
    }
};
