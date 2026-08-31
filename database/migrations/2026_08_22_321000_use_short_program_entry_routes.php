<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('programs')->where('code', 'wms')->update(['entry_route' => 'wms.purchasing.index']);
        DB::table('programs')->where('code', 'inventory')->update(['entry_route' => 'wms.index']);
    }

    public function down(): void
    {
        DB::table('programs')->where('code', 'wms')->update(['entry_route' => 'wms.purchasing.index']);
        DB::table('programs')->where('code', 'inventory')->update(['entry_route' => 'wms.inventory.index']);
    }
};
