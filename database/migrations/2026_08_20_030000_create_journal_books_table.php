<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_books', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique();
            $table->string('name');
            $table->enum('type', ['PURCHASE', 'SALES', 'RECEIPT', 'PAYMENT', 'GENERAL'])->unique();
            $table->string('sequence_prefix', 10)->unique();
            $table->unsignedTinyInteger('sort_order')->unique();
            $table->boolean('is_system')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_books');
    }
};
