<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_boms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 255);
            $table->foreignId('finished_item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('base_uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'code'], 'production_boms_branch_code_uq');
            $table->index(['branch_id', 'finished_item_id', 'is_active'], 'production_boms_finished_idx');
        });

        Schema::create('production_bom_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bom_id')->constrained('production_boms')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->enum('status', ['DRAFT', 'ACTIVE', 'INACTIVE'])->default('DRAFT');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bom_id', 'revision_number'], 'production_bom_revisions_number_uq');
            $table->index(['bom_id', 'status', 'effective_from'], 'production_bom_revisions_status_idx');
        });

        Schema::create('production_bom_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bom_revision_id')->constrained('production_bom_revisions')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('component_item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['bom_revision_id', 'line_number'], 'production_bom_lines_number_uq');
            $table->unique(['bom_revision_id', 'component_item_id'], 'production_bom_lines_component_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_bom_lines');
        Schema::dropIfExists('production_bom_revisions');
        Schema::dropIfExists('production_boms');
    }
};
