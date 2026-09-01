<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_depreciable')->default(true);
            $table->decimal('capitalization_threshold', 18, 2)->default(0);
            $table->enum('book_method', ['STRAIGHT_LINE'])->default('STRAIGHT_LINE');
            $table->unsignedInteger('book_useful_life_months')->nullable();
            $table->decimal('book_residual_value_percent', 9, 4)->default(0);
            $table->enum('tax_method', ['STRAIGHT_LINE'])->default('STRAIGHT_LINE');
            $table->unsignedInteger('tax_useful_life_months')->nullable();
            $table->decimal('tax_rate_percent', 9, 4)->nullable();
            $table->decimal('tax_cost_cap', 18, 2)->nullable();
            foreach (['asset_account_id', 'accumulated_depreciation_account_id', 'depreciation_expense_account_id', 'accumulated_impairment_account_id', 'impairment_loss_account_id', 'disposal_gain_account_id', 'disposal_loss_account_id'] as $column) {
                $table->foreignId($column)->nullable()->constrained('accounts')->restrictOnDelete();
            }
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asset_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('asset_locations')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->enum('location_type', ['BRANCH', 'WAREHOUSE', 'BUILDING', 'FLOOR', 'ROOM', 'SITE', 'OTHER'])->default('OTHER');
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->string('asset_number', 40)->unique();
            $table->string('tag_number', 100)->nullable()->unique();
            $table->string('barcode_value', 100)->nullable()->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('asset_locations')->restrictOnDelete();
            $table->foreignId('custodian_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('manufacturer')->nullable();
            $table->date('acquisition_date');
            $table->date('placed_in_service_date')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->date('warranty_end_date')->nullable();
            $table->string('insurance_policy_number')->nullable();
            $table->date('insurance_end_date')->nullable();
            $table->decimal('original_cost', 18, 2)->default(0);
            $table->char('currency_code', 3)->default('THB');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('book_cost', 18, 2)->default(0);
            $table->decimal('book_accumulated_depreciation', 18, 2)->default(0);
            $table->decimal('book_accumulated_impairment', 18, 2)->default(0);
            $table->decimal('book_value', 18, 2)->default(0);
            $table->enum('status', ['DRAFT', 'REGISTERED', 'ACTIVE', 'SUSPENDED', 'UNDER_REPAIR', 'HELD_FOR_DISPOSAL', 'DISPOSED', 'WRITTEN_OFF'])->default('DRAFT');
            $table->boolean('is_depreciation_suspended')->default(false);
            $table->string('status_reason', 500)->nullable();
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['source_type', 'source_line_id']);
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'acquisition_date']);
        });

        Schema::create('asset_depreciation_books', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->enum('book_type', ['BOOK', 'TAX']);
            $table->enum('method', ['STRAIGHT_LINE'])->default('STRAIGHT_LINE');
            $table->decimal('depreciable_cost', 18, 2);
            $table->decimal('residual_value', 18, 2)->default(0);
            $table->unsignedInteger('useful_life_months')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('tax_rate_percent', 9, 4)->nullable();
            $table->decimal('tax_cost_cap', 18, 2)->nullable();
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);
            $table->date('last_depreciation_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['asset_id', 'book_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_books');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_locations');
        Schema::dropIfExists('asset_categories');
    }
};
