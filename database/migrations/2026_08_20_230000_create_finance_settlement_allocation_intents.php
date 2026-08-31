<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_settlement_allocation_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('finance_settlements')->cascadeOnDelete();
            $table->foreignId('open_item_id')->constrained('finance_open_items')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->decimal('amount', 18, 2)->unsigned();
            $table->timestamps();

            $table->unique(['settlement_id', 'open_item_id'], 'finance_settlement_intents_item_unique');
            $table->unique(['settlement_id', 'line_number'], 'finance_settlement_intents_line_unique');
            $table->index('open_item_id', 'finance_settlement_intents_open_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settlement_allocation_intents');
    }
};
