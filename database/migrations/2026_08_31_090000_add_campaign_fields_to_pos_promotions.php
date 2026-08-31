<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_promotions', function (Blueprint $table): void {
            $table->decimal('campaign_budget_amount', 18, 2)->unsigned()->nullable()->after('priority');
            $table->decimal('campaign_target_sales_amount', 18, 2)->unsigned()->nullable()->after('campaign_budget_amount');
            $table->decimal('campaign_target_gross_profit_amount', 18, 2)->nullable()->after('campaign_target_sales_amount');
            $table->foreignId('campaign_owner_id')->nullable()->after('campaign_target_gross_profit_amount')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_promotions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('campaign_owner_id');
            $table->dropColumn(['campaign_budget_amount', 'campaign_target_sales_amount', 'campaign_target_gross_profit_amount']);
        });
    }
};
