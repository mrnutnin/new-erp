<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->enum('normal_balance', ['DEBIT', 'CREDIT']);
            $table->enum('statement_section', ['BALANCE_SHEET', 'PROFIT_LOSS']);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('level');
            $table->enum('normal_balance', ['DEBIT', 'CREDIT']);
            $table->enum('statement_section', ['BALANCE_SHEET', 'PROFIT_LOSS']);
            $table->enum('reporting_profile', ['PAE', 'NPAE'])->nullable();
            $table->enum('control_account_type', ['AR', 'AP', 'INVENTORY', 'CASH', 'BANK', 'CREDIT_CARD', 'CHEQUE', 'FIXED_ASSET', 'INPUT_VAT', 'OUTPUT_VAT', 'WITHHOLDING_TAX', 'WIP'])->nullable();
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_type_id', 'is_active']);
            $table->index(['parent_id', 'is_active']);
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->date('start_date')->unique();
            $table->date('end_date')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('period_number');
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['OPEN', 'SOFT_CLOSE', 'LOCKED'])->default('OPEN');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 500)->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'period_number']);
            $table->index(['start_date', 'end_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('account_types');
    }
};
