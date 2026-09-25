<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_items', function (Blueprint $table): void {
            $table->boolean('can_receive_production_scrap')->default(false)->after('can_manufacture');
        });
    }

    public function down(): void
    {
        Schema::table('wms_items', function (Blueprint $table): void {
            $table->dropColumn('can_receive_production_scrap');
        });
    }
};
