<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_document_sequence_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_sequence_id')->constrained('finance_document_sequences')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->string('last_reset_key', 12)->nullable();
            $table->timestamps();
            $table->unique(['document_sequence_id', 'branch_id'], 'finance_sequence_counter_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_document_sequence_counters');
    }
};
