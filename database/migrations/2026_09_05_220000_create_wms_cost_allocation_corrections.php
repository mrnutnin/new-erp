<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The first deployment may have created the table before MySQL rejected
        // the generated long index name; keep retrying this migration safe.
        if (Schema::hasTable('wms_cost_allocation_corrections')) {
            return;
        }
        Schema::create('wms_cost_allocation_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allocation_id')->unique()->constrained('wms_cost_allocations')->restrictOnDelete();
            $table->foreignId('canonical_allocation_id')->constrained('wms_cost_allocations')->restrictOnDelete();
            $table->string('correction_type', 40);
            $table->string('reason', 500);
            $table->json('evidence');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at');
            $table->timestamps();
            $table->index(['canonical_allocation_id', 'correction_type'], 'wms_alloc_corrections_canonical_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_cost_allocation_corrections');
    }
};
