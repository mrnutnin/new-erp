<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_other_categories', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['INCOME', 'EXPENSE']);
            $table->string('code', 30);
            $table->string('name');
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['kind', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_other_categories');
    }
};
