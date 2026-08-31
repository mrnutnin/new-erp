<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_uom_conversions', function (Blueprint $table): void {
            $table->date('effective_from')->nullable()->after('factor');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->index(['from_uom_id', 'to_uom_id', 'effective_from', 'effective_to'], 'wms_uom_conversion_effective_ix');
        });

        DB::table('wms_uom_conversions')->update(['effective_from' => '1900-01-01']);
        Schema::table('wms_uom_conversions', function (Blueprint $table): void {
            $table->dropUnique(['from_uom_id', 'to_uom_id']);
        });
    }

    public function down(): void
    {
        Schema::table('wms_uom_conversions', function (Blueprint $table): void {
            $table->unique(['from_uom_id', 'to_uom_id']);
            $table->dropIndex('wms_uom_conversion_effective_ix');
            $table->dropColumn(['effective_from', 'effective_to']);
        });
    }
};
