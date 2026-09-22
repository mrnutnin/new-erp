<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->timestamp('held_at')->nullable()->after('started_by');
            $table->foreignId('held_by')->nullable()->after('held_at')->constrained('users')->nullOnDelete();
            $table->text('hold_reason')->nullable()->after('held_by');
            $table->timestamp('resumed_at')->nullable()->after('hold_reason');
            $table->foreignId('resumed_by')->nullable()->after('resumed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropForeign(['held_by']);
            $table->dropForeign(['resumed_by']);
            $table->dropColumn(['held_at', 'held_by', 'hold_reason', 'resumed_at', 'resumed_by']);
        });
    }
};
