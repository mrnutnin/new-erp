<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_payment_voucher_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_voucher_id')->constrained('finance_payment_vouchers')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('open_item_id')->nullable()->constrained('finance_open_items')->restrictOnDelete();
            $table->string('open_item_document_number', 100)->nullable();
            $table->decimal('open_item_original_amount', 18, 2)->nullable();
            $table->decimal('amount', 18, 2)->unsigned();
            $table->string('description', 500)->nullable();
            $table->char('allocation_key', 64)->unique();
            $table->timestamps();
            $table->unique(['payment_voucher_id', 'line_number'], 'finance_payment_voucher_lines_number_unique');
            $table->unique(['payment_voucher_id', 'open_item_id'], 'finance_payment_voucher_lines_item_unique');
            $table->index(['open_item_id', 'created_at'], 'finance_payment_voucher_lines_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payment_voucher_lines');
    }
};
