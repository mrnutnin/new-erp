<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_capitalizations', function (Blueprint $table): void {
            $table->boolean('is_manual_exception')->default(false)->after('source_id');
            $table->string('manual_exception_reason', 500)->nullable()->after('is_manual_exception');
        });
    }

    public function down(): void
    {
        Schema::table('asset_capitalizations', function (Blueprint $table): void {
            $table->dropColumn(['is_manual_exception', 'manual_exception_reason']);
        });
    }
};
