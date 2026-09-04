<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Swap codes through a temporary value to avoid the unique index.
        DB::table('programs')->where('code', 'wms')->update(['code' => '__purchasing_legacy__']);
        DB::table('programs')->where('code', 'inventory')->update([
            'code' => 'wms',
            'name' => 'WMS',
            'description' => 'บริหารคลังสินค้าและสต็อก',
            'entry_route' => 'wms.index',
        ]);
        DB::table('programs')->where('code', '__purchasing_legacy__')->update([
            'code' => 'purchasing',
            'name' => 'Purchasing',
            'description' => 'บริหารจัดซื้อ',
            'entry_route' => 'purchasing.index',
        ]);
    }

    public function down(): void
    {
        DB::table('programs')->where('code', 'purchasing')->update(['code' => '__purchasing_legacy__']);
        DB::table('programs')->where('code', 'wms')->update(['code' => 'inventory']);
        DB::table('programs')->where('code', '__purchasing_legacy__')->update(['code' => 'wms']);
    }
};
