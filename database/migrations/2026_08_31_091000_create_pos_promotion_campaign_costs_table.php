<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_promotion_campaign_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained('pos_promotions')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('cost_date');
            $table->decimal('amount', 18, 2);
            $table->string('reference', 100)->nullable();
            $table->string('note', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['promotion_id', 'branch_id', 'cost_date'], 'pos_campaign_cost_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_promotion_campaign_costs');
    }
};
