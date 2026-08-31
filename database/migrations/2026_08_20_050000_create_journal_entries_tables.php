<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_book_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('entry_number', 40);
            $table->date('entry_date');
            $table->date('document_date')->nullable();
            $table->string('source_type', 30)->default('MANUAL');
            $table->string('source_reference', 100)->nullable();
            $table->string('description', 500);
            $table->char('currency_code', 3)->default('THB');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->enum('status', ['DRAFT', 'VALIDATED', 'POSTED', 'REVERSED'])->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('entry_number');
            $table->unique(['journal_book_id', 'fiscal_period_id', 'sequence_number'], 'journal_entry_sequence_unique');
            $table->index(['entry_date', 'status']);
            $table->index(['branch_id', 'warehouse_id']);
        });

        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('description', 500)->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['journal_entry_id', 'line_number']);
            $table->index(['account_id', 'journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
    }
};
