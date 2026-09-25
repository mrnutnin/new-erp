<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->timestamp('production_requested_at')->nullable();
            $table->foreignId('production_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('requested_delivery_date')->nullable();
            $table->date('requested_start_date')->nullable();
            $table->text('production_specification')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->dropForeign(['production_requested_by']);
            $table->dropColumn(['production_requested_at', 'production_requested_by', 'requested_delivery_date', 'requested_start_date', 'production_specification']);
        });
    }
};
