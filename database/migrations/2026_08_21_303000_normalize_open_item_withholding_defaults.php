<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('finance_open_items')->whereNull('withholding_rate')->update(['withholding_rate' => 0]);
        DB::table('finance_open_items')->whereNull('withholding_base')->update(['withholding_base' => 0]);
        DB::table('finance_open_items')->whereNull('withholding_amount')->update(['withholding_amount' => 0]);
    }

    public function down(): void
    {
        // Deliberately irreversible: NULL-vs-zero legacy state is not safely inferable.
    }
};
