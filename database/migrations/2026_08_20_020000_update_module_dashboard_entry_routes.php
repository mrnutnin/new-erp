<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('programs')
            ->where('code', 'settings')
            ->update(['entry_route' => 'settings.index']);
    }

    public function down(): void
    {
        DB::table('programs')
            ->where('code', 'settings')
            ->update(['entry_route' => 'settings.company.edit']);
    }
};
