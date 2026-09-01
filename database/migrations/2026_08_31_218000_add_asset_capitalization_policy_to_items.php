<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_items', function (Blueprint $table): void {
            $table->boolean('is_asset_capitalizable')->default(false)->after('is_stock_item');
            $table->foreignId('default_asset_category_id')->nullable()->after('is_asset_capitalizable')->constrained('asset_categories')->restrictOnDelete();
            $table->index(['is_asset_capitalizable', 'is_active'], 'wms_item_asset_capitalizable_idx');
        });
    }

    public function down(): void
    {
        Schema::table('wms_items', function (Blueprint $table): void {
            $table->dropIndex('wms_item_asset_capitalizable_idx');
            $table->dropForeign(['default_asset_category_id']);
            $table->dropColumn(['is_asset_capitalizable', 'default_asset_category_id']);
        });
    }
};
