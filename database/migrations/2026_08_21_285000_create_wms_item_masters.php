<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('wms_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('wms_item_categories')->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->enum('item_type', ['GOODS', 'SERVICE']);
            $table->string('base_uom', 30);
            $table->boolean('is_stock_item')->default(false);
            $table->foreignId('inventory_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('sales_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('cogs_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_items');
        Schema::dropIfExists('wms_item_categories');
    }
};
