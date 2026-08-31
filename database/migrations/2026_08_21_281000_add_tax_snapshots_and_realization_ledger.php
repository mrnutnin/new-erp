<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_open_items', function (Blueprint $table) {
            $table->foreignId('tax_code_id')->nullable()->after('original_amount')->constrained('tax_codes')->nullOnDelete();
            $table->string('tax_kind', 20)->nullable()->after('tax_code_id');
            $table->decimal('tax_rate', 7, 4)->nullable()->after('tax_kind');
            $table->decimal('tax_base', 18, 2)->nullable()->after('tax_rate');
            $table->decimal('tax_amount', 18, 2)->nullable()->after('tax_base');
            $table->date('tax_point_date')->nullable()->after('tax_amount');
        });

        Schema::create('finance_tax_realizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_id')->unique()->constrained('finance_allocations')->restrictOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('finance_settlements')->restrictOnDelete();
            $table->foreignId('open_item_id')->constrained('finance_open_items')->restrictOnDelete();
            $table->string('tax_kind', 20);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->foreignId('deferred_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('actual_account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('tax_base', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->date('tax_point_date');
            $table->date('settlement_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tax_kind', 'tax_point_date', 'settlement_date'], 'finance_tax_realizations_report_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_tax_realizations');
        Schema::table('finance_open_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_code_id');
            $table->dropColumn(['tax_kind', 'tax_rate', 'tax_base', 'tax_amount', 'tax_point_date']);
        });
    }
};
