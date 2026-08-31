<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales_commission_plan_assignments', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
        });

        DB::table('pos_sales_commission_plan_assignments as assignments')
            ->join('warehouses', 'warehouses.id', '=', 'assignments.warehouse_id')
            ->whereNull('assignments.branch_id')
            ->update(['assignments.branch_id' => DB::raw('warehouses.branch_id')]);

        Schema::table('pos_sales_commission_plan_assignments', function (Blueprint $table) {
            $table->unique(['commission_plan_id', 'user_id', 'branch_id'], 'pos_commission_plan_user_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales_commission_plan_assignments', function (Blueprint $table) {
            $table->dropUnique('pos_commission_plan_user_branch_unique');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
