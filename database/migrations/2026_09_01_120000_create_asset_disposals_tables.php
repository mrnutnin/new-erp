<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_disposals', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 40)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->enum('disposal_type', ['SALE', 'WRITE_OFF']);
            $table->date('disposal_date');
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'CANCELLED'])->default('DRAFT');
            $table->decimal('proceeds', 18, 2)->default(0);
            $table->text('reason');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'status', 'disposal_date']);
        });

        Schema::create('asset_disposal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_disposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->decimal('cost', 18, 2)->default(0);
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);
            $table->decimal('accumulated_impairment', 18, 2)->default(0);
            $table->decimal('carrying_amount', 18, 2)->default(0);
            $table->decimal('proceeds', 18, 2)->default(0);
            $table->decimal('gain_loss', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['asset_disposal_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_disposal_lines');
        Schema::dropIfExists('asset_disposals');
    }
};
