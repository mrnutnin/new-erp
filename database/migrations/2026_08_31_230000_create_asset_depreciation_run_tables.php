<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_depreciation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 40)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_period_id')->constrained()->restrictOnDelete();
            $table->enum('book_type', ['BOOK', 'TAX']);
            $table->date('run_through_date');
            $table->enum('status', ['CALCULATING', 'DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED', 'FAILED'])->default('DRAFT');
            $table->unsignedInteger('asset_count')->default(0);
            $table->decimal('total_depreciation', 18, 2)->default(0);
            $table->decimal('total_catch_up_adjustment', 18, 2)->default(0);
            $table->char('calculation_hash', 64)->nullable();
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->text('error_message')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->date('reversal_date')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // MySQL has no partial unique index: terminal runs release the period for recalculation.
            $table->unsignedTinyInteger('active_key')->virtualAs("IF(`status` IN ('REVERSED', 'FAILED'), NULL, 1)");
            $table->unique(['branch_id', 'fiscal_period_id', 'book_type', 'active_key'], 'asset_depreciation_runs_active_period_book_unique');
            $table->index(['branch_id', 'status', 'run_through_date']);
            $table->index(['fiscal_period_id', 'book_type']);
        });

        Schema::create('asset_depreciation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_depreciation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_depreciation_book_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('asset_number', 40);
            $table->string('category_code', 30)->nullable();
            $table->string('category_name')->nullable();
            $table->decimal('opening_cost', 18, 2)->default(0);
            $table->decimal('opening_accumulated_depreciation', 18, 2)->default(0);
            $table->decimal('opening_accumulated_impairment', 18, 2)->default(0);
            $table->decimal('period_depreciation', 18, 2)->default(0);
            $table->decimal('catch_up_adjustment', 18, 2)->default(0);
            $table->decimal('closing_cost', 18, 2)->default(0);
            $table->decimal('closing_accumulated_depreciation', 18, 2)->default(0);
            $table->decimal('closing_accumulated_impairment', 18, 2)->default(0);
            $table->decimal('closing_book_value', 18, 2)->default(0);
            $table->json('calculation_input_snapshot');
            $table->json('calculation_explanation')->nullable();
            $table->foreignId('journal_entry_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['asset_depreciation_run_id', 'line_number'], 'asset_depreciation_line_number_unique');
            $table->unique(['asset_depreciation_run_id', 'asset_id'], 'asset_depreciation_line_asset_unique');
            $table->index(['asset_id', 'asset_depreciation_book_id'], 'asset_depreciation_line_asset_book_idx');
        });

        Schema::create('asset_depreciation_policy_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('asset_depreciation_book_id');
            $table->foreign('asset_depreciation_book_id', 'asset_dep_policy_book_fk')->references('id')->on('asset_depreciation_books')->restrictOnDelete();
            $table->date('effective_date');
            $table->enum('status', ['DRAFT', 'APPROVED'])->default('DRAFT');
            $table->json('profile_snapshot');
            $table->string('reason', 500);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['asset_depreciation_book_id', 'effective_date'], 'asset_depreciation_policy_effective_unique');
            $table->index(['status', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_policy_changes');
        Schema::dropIfExists('asset_depreciation_lines');
        Schema::dropIfExists('asset_depreciation_runs');
    }
};
