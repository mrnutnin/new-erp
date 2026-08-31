<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_physical_sale_tenders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('physical_sale_id')->constrained('pos_physical_sales')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('finance_bank_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->decimal('amount', 18, 2)->unsigned();
            $table->string('reference', 100)->nullable();
            $table->timestamps();
            $table->unique(['physical_sale_id', 'line_number'], 'pos_physical_sale_tenders_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_physical_sale_tenders');
    }
};
