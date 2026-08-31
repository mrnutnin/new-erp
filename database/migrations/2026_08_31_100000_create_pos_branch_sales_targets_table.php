<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_branch_sales_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_sales_amount', 18, 2)->unsigned()->nullable();
            $table->decimal('target_gross_profit_amount', 18, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedTinyInteger('active_key')->virtualAs('IF(`deleted_at` IS NULL, 1, NULL)');
            $table->unique(['branch_id', 'period_start', 'period_end', 'active_key'], 'pos_branch_sales_targets_active_period_unique');
            $table->index(['branch_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_branch_sales_targets');
    }
};
