<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dateTime('planned_start_at')->nullable()->after('planned_start_date');
            $table->dateTime('planned_finish_at')->nullable()->after('planned_finish_date');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropColumn(['planned_start_at', 'planned_finish_at']);
        });
    }
};
