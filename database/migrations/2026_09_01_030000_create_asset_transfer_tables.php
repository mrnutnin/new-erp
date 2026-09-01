<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 40)->unique();
            $table->foreignId('source_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('destination_branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('document_date');
            $table->string('reason', 500);
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'CANCELLED'])->default('DRAFT');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['source_branch_id', 'status']);
            $table->index(['destination_branch_id', 'status']);
            $table->index(['source_branch_id', 'document_date']);
        });

        Schema::create('asset_transfer_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_transfer_id')->constrained('asset_transfers')->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('old_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('new_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('old_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('new_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('old_location_id')->nullable()->constrained('asset_locations')->restrictOnDelete();
            $table->foreignId('new_location_id')->nullable()->constrained('asset_locations')->restrictOnDelete();
            $table->foreignId('old_custodian_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('new_custodian_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('asset_number_snapshot', 40);
            $table->string('asset_name_snapshot');
            $table->timestamps();
            $table->unique(['asset_transfer_id', 'asset_id']);
            $table->index(['asset_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_transfer_lines');
        Schema::dropIfExists('asset_transfers');
    }
};
