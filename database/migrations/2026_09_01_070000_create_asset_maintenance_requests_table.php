<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_maintenance_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 40)->unique();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('reported_date');
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->enum('maintenance_type', ['CORRECTIVE', 'PREVENTIVE', 'INSPECTION']);
            $table->enum('priority', ['LOW', 'NORMAL', 'HIGH', 'CRITICAL'])->default('NORMAL');
            $table->string('issue', 1000);
            $table->text('diagnosis')->nullable();
            $table->text('resolution')->nullable();
            $table->foreignId('vendor_party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->boolean('is_under_warranty')->default(false);
            $table->date('planned_date')->nullable();
            $table->date('started_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->unsignedInteger('downtime_minutes')->nullable();
            $table->decimal('estimated_cost', 18, 2)->nullable();
            $table->decimal('actual_cost', 18, 2)->nullable();
            $table->string('source_document_type', 50)->nullable();
            $table->string('source_document_number', 100)->nullable();
            $table->boolean('takes_asset_out_of_service')->default(false);
            $table->enum('status', ['OPEN', 'ASSIGNED', 'IN_PROGRESS', 'WAITING_PARTS', 'COMPLETED', 'CANCELLED'])->default('OPEN');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'reported_date']);
            $table->index(['asset_id', 'status']);
            $table->index(['priority', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_requests');
    }
};
