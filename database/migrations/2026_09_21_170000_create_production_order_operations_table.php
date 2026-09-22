<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('name', 150);
            $table->unsignedInteger('planned_minutes')->nullable();
            $table->enum('status', ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'SKIPPED'])->default('PENDING');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['production_order_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_operations');
    }
};
