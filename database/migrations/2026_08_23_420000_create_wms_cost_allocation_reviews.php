<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_cost_allocation_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allocation_id')->constrained('wms_cost_allocations')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('status', 30)->default('OPEN');
            $table->string('proposed_state', 30)->default('REVIEW_REQUIRED');
            $table->string('evidence_hash', 64);
            $table->text('reason');
            $table->unsignedBigInteger('actor_id');
            $table->json('evidence');
            $table->timestamps();
            $table->unique(['allocation_id', 'revision'], 'wms_allocation_review_identity');
            $table->index(['status', 'allocation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_cost_allocation_reviews');
    }
};
