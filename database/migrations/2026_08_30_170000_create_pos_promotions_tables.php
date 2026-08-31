<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->string('application_scope', 10)->default('LINE')->index();
            $table->boolean('stackable')->default(false);
            $table->char('currency', 3)->default('THB');
            $table->string('customer_group_code', 50)->nullable();
            $table->decimal('bill_discount_amount', 18, 4)->unsigned()->nullable();
            $table->decimal('bill_discount_percent', 7, 4)->unsigned()->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_group_code', 'is_active', 'effective_from', 'effective_to'], 'pos_promotions_scope_idx');
        });

        Schema::create('pos_promotion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('pos_promotions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->nullable()->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('minimum_quantity', 18, 4)->unsigned()->default(0);
            $table->decimal('unit_price', 18, 4)->unsigned()->nullable();
            $table->decimal('base_unit_price', 18, 4)->unsigned()->nullable();
            $table->decimal('discount_percent', 7, 4)->unsigned()->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_id', 'uom_id', 'is_active'], 'pos_promotion_items_scope_idx');
        });

        // A percentage promotion has its own base price, so it never depends on
        // a Price List. A fixed-price promotion has neither base nor percentage.
        DB::statement('ALTER TABLE pos_promotion_items ADD CONSTRAINT pos_promotion_items_price_rule_check CHECK ((unit_price IS NOT NULL AND base_unit_price IS NULL AND discount_percent IS NULL) OR (unit_price IS NULL AND base_unit_price IS NOT NULL AND discount_percent IS NOT NULL AND discount_percent <= 100))');
        DB::statement("ALTER TABLE pos_promotions ADD CONSTRAINT pos_promotions_application_scope_check CHECK ((application_scope = 'LINE' AND bill_discount_amount IS NULL AND bill_discount_percent IS NULL) OR (application_scope = 'DOCUMENT' AND ((bill_discount_amount IS NOT NULL AND bill_discount_percent IS NULL) OR (bill_discount_amount IS NULL AND bill_discount_percent IS NOT NULL AND bill_discount_percent <= 100))))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_promotion_items');
        Schema::dropIfExists('pos_promotions');
    }
};
