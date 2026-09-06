<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->char('currency', 3)->default('THB');
            // Nullable during fresh install; Installer creates the default branch
            // after migrations, and new Price Lists are always branch-scoped.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            // The group code is intentionally a contract boundary. Customer-group
            // ownership stays with the shared Party/POS foundation and is linked
            // without duplicating the customer master here.
            $table->string('customer_group_code', 50)->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_group_code', 'is_active', 'effective_from', 'effective_to'], 'pos_price_lists_scope_idx');
            $table->index(['branch_id', 'is_active', 'effective_from', 'effective_to'], 'pos_price_lists_branch_scope_idx');
        });

        Schema::create('pos_price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('pos_price_lists')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->nullable()->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('minimum_quantity', 18, 4)->unsigned()->default(0);
            $table->decimal('unit_price', 18, 4)->unsigned();
            $table->decimal('discount_percent', 7, 4)->unsigned()->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_id', 'uom_id', 'is_active'], 'pos_price_list_items_item_scope_idx');
            $table->index(['price_list_id', 'effective_from', 'effective_to'], 'pos_price_list_items_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_price_list_items');
        Schema::dropIfExists('pos_price_lists');
    }
};
