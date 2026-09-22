<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_bom_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bom_revision_id')->constrained('production_bom_revisions')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('name', 150);
            $table->unsignedInteger('planned_minutes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['bom_revision_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_bom_operations');
    }
};
