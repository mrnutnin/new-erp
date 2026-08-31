<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales_commission_payout_batches', function (Blueprint $table): void {
            $table->unsignedBigInteger('payment_batch_id')->nullable()->after('id');
            $table->foreign('payment_batch_id', 'pos_commission_payout_parent_fk')->references('id')->on('pos_sales_commission_payment_batches')->restrictOnDelete();
            $table->index(['payment_batch_id', 'recipient_user_id', 'status'], 'pos_commission_payout_parent_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales_commission_payout_batches', function (Blueprint $table): void {
            $table->dropIndex('pos_commission_payout_parent_scope_idx');
            $table->dropForeign('pos_commission_payout_parent_fk');
            $table->dropColumn('payment_batch_id');
        });
    }
};
