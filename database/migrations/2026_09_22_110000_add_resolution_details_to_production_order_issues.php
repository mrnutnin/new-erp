<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_issues', function (Blueprint $table): void {
            $table->text('resolution_method')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_issues', function (Blueprint $table): void {
            $table->dropColumn('resolution_method');
        });
    }
};
