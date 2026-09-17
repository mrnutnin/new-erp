<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_product_interests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('crm_opportunities')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->decimal('target_unit_price', 20, 4)->nullable();
            $table->decimal('budget_amount', 20, 4)->nullable();
            $table->text('requirements')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['opportunity_id', 'id']);
        });
    }

    public function down(): void { Schema::dropIfExists('crm_product_interests'); }
};
