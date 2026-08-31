<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_withholding_realizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_id')->unique()->constrained('finance_allocations')->restrictOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('finance_settlements')->restrictOnDelete();
            $table->foreignId('open_item_id')->constrained('finance_open_items')->restrictOnDelete();
            $table->foreignId('tax_code_id')->constrained('tax_codes')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('direction', 20);
            $table->decimal('tax_base', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->date('settlement_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['direction', 'settlement_date'], 'finance_wht_realizations_report_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_withholding_realizations');
    }
};
