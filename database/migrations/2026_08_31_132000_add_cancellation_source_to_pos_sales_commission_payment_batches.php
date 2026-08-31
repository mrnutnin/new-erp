<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales_commission_payment_batches', function (Blueprint $table): void {
            $table->string('cancellation_source', 20)->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales_commission_payment_batches', function (Blueprint $table): void {
            $table->dropColumn('cancellation_source');
        });
    }
};
