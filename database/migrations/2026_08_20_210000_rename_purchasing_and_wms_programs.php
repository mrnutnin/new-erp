<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('programs')->where('code', 'wms')->update(['name' => 'Purchasing', 'description' => 'บริหารจัดซื้อ']);
        DB::table('programs')->where('code', 'inventory')->update(['name' => 'WMS', 'description' => 'บริหารคลังสินค้าและสต็อก']);
    }

    public function down(): void
    {
        DB::table('programs')->where('code', 'wms')->update(['name' => 'WMS', 'description' => 'บริหารจัดซื้อ']);
        DB::table('programs')->where('code', 'inventory')->update(['name' => 'INV', 'description' => 'บริหารคลังสินค้า']);
    }
};
