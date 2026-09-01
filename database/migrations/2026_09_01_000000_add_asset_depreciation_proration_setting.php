<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->enum('asset_depreciation_proration', ['DAILY', 'FULL_MONTH'])->default('DAILY')->after('asset_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn('asset_depreciation_proration');
        });
    }
};
