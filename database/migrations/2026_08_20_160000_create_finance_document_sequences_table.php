<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 40);
            $table->string('name');
            $table->string('prefix', 20);
            $table->string('number_format', 80)->default('{PREFIX}-{YYYY}-{NUMBER:6}');
            $table->enum('reset_rule', ['NEVER', 'YEARLY', 'MONTHLY'])->default('YEARLY');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['warehouse_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_document_sequences');
    }
};
