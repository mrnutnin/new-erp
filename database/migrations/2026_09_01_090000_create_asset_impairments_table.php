<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_impairments', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 50)->unique();
            $table->foreignId('asset_id')->constrained('assets');
            $table->foreignId('branch_id')->constrained('branches');
            $table->date('assessment_date');
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'CANCELLED'])->default('DRAFT');
            $table->decimal('carrying_amount', 18, 2)->default(0);
            $table->decimal('recoverable_amount', 18, 2)->default(0);
            $table->decimal('impairment_amount', 18, 2)->default(0);
            $table->string('evidence_reference', 255)->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'status'], 'asset_impairments_branch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_impairments');
    }
};
