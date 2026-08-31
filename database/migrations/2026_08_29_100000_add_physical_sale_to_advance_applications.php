<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_advance_deposit_applications', function (Blueprint $table): void {
            $table->foreignId('open_item_id')->nullable()->change();
            $table->foreignId('physical_sale_id')->nullable()->after('open_item_id')->constrained('pos_physical_sales')->restrictOnDelete();
            $table->index(['physical_sale_id', 'application_date'], 'finance_adv_applications_sale_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('finance_advance_deposit_applications', function (Blueprint $table): void {
            $table->dropIndex('finance_adv_applications_sale_date_idx');
            $table->dropConstrainedForeignId('physical_sale_id');
            $table->foreignId('open_item_id')->nullable(false)->change();
        });
    }
};
