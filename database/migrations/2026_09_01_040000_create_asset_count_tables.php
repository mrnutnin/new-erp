<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_counts', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 40)->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('asset_locations')->restrictOnDelete();
            $table->foreignId('asset_category_id')->nullable()->constrained('asset_categories')->restrictOnDelete();
            $table->date('freeze_date');
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'CANCELLED'])->default('DRAFT');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'freeze_date']);
        });

        Schema::create('asset_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_count_id')->constrained('asset_counts')->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('asset_number_snapshot', 40);
            $table->string('asset_name_snapshot');
            $table->foreignId('expected_location_id')->nullable()->constrained('asset_locations')->restrictOnDelete();
            $table->foreignId('expected_custodian_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('scanned_code', 100)->nullable();
            $table->foreignId('found_location_id')->nullable()->constrained('asset_locations')->restrictOnDelete();
            $table->foreignId('found_custodian_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('result', ['FOUND', 'MISSING', 'WRONG_LOCATION', 'DAMAGED'])->default('FOUND');
            $table->string('note', 500)->nullable();
            $table->boolean('follow_up_required')->default(false);
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['asset_count_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_count_lines');
        Schema::dropIfExists('asset_counts');
    }
};
