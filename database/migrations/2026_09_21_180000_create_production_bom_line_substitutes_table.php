<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_bom_line_substitutes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bom_line_id')->constrained('production_bom_lines')->cascadeOnDelete();
            $table->foreignId('substitute_item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('quantity_factor', 20, 8)->default(1);
            $table->unsignedInteger('priority')->default(1);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['bom_line_id', 'substitute_item_id'], 'production_bom_substitute_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_bom_line_substitutes');
    }
};
