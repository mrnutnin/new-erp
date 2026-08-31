<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_settlement_tenders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('settlement_id')->constrained('finance_settlements')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('finance_bank_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->decimal('amount', 18, 2)->unsigned();
            $table->string('reference', 100)->nullable();
            $table->timestamps();

            $table->unique(['settlement_id', 'line_number'], 'finance_settlement_tenders_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settlement_tenders');
    }
};
