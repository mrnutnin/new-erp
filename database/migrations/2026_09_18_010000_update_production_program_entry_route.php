<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('programs')->where('code', 'production')->where('entry_route', 'dashboard')->update(['entry_route' => 'production.index']);
    }

    public function down(): void
    {
        DB::table('programs')->where('code', 'production')->where('entry_route', 'production.index')->update(['entry_route' => 'dashboard']);
    }
};
