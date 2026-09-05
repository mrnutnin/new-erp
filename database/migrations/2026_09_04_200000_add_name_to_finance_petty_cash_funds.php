<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_petty_cash_funds', function (Blueprint $table): void {
            $table->string('name', 150)->nullable()->after('warehouse_id');
        });

        DB::table('finance_petty_cash_funds')->whereNull('name')->orderBy('id')->eachById(function (object $fund): void {
            DB::table('finance_petty_cash_funds')->where('id', $fund->id)->update(['name' => 'วงเงินสดย่อย #'.$fund->id]);
        });
    }

    public function down(): void
    {
        Schema::table('finance_petty_cash_funds', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }
};
