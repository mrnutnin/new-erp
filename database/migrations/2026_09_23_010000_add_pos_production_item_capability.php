<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_items', function (Blueprint $table): void {
            $table->boolean('can_manufacture')->default(false)->after('is_stock_item');
        });

        // Snapshot only which orders were already confirmed at cutover, not a product policy.
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->boolean('production_legacy_eligible')->default(false)->after('status');
        });
        DB::table('sales_orders')->where('status', 'CONFIRMED')->update(['production_legacy_eligible' => true]);
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropColumn('production_legacy_eligible'));
        Schema::table('wms_items', fn (Blueprint $table) => $table->dropColumn('can_manufacture'));
    }
};
