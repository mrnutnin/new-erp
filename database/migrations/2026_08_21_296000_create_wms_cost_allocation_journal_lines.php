<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wms_cost_allocation_journal_lines')) {
            Schema::create('wms_cost_allocation_journal_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('allocation_id')->constrained('wms_cost_allocations')->restrictOnDelete();
                $table->foreignId('journal_entry_line_id')->constrained('journal_entry_lines')->restrictOnDelete();
                $table->unsignedInteger('revision')->default(0);
                $table->string('identity_key', 180)->unique();
                $table->timestamps();
                $table->unique(['allocation_id', 'journal_entry_line_id', 'revision'], 'wms_allocation_journal_line_revision_unique');
                $table->index(['journal_entry_line_id', 'revision'], 'wms_alloc_journal_line_idx');
            });
        } else {
            $indexes = collect(Schema::getIndexes('wms_cost_allocation_journal_lines'))->pluck('name')->all();
            if (! in_array('wms_alloc_journal_line_idx', $indexes, true)) {
                Schema::table('wms_cost_allocation_journal_lines', function (Blueprint $table): void {
                    $table->index(['journal_entry_line_id', 'revision'], 'wms_alloc_journal_line_idx');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_cost_allocation_journal_lines');
    }
};
