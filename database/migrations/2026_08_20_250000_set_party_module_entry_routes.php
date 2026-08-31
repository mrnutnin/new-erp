<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('programs')->where('code', 'wms')->update(['entry_route' => 'wms.index']);
        DB::table('programs')->where('code', 'pos')->update(['entry_route' => 'pos.index']);
    }

    public function down(): void
    {
        DB::table('programs')->whereIn('code', ['wms', 'pos'])->update(['entry_route' => 'dashboard']);
    }
};
