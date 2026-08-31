<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->enum('status', ['DRAFT', 'CONFIRMED', 'CANCELLED'])->default('DRAFT')->change();
            $table->foreignId('confirmed_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
            $table->foreignId('cancelled_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->string('cancel_reason', 500)->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['confirmed_by', 'cancelled_by']);
            $table->dropColumn(['confirmed_by', 'confirmed_at', 'cancelled_by', 'cancelled_at', 'cancel_reason']);
            $table->enum('status', ['DRAFT', 'CANCELLED'])->default('DRAFT')->change();
        });
    }
};
