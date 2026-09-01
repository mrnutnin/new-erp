<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_count_lines', function (Blueprint $table): void {
            $table->enum('result', ['FOUND', 'MISSING', 'WRONG_LOCATION', 'DAMAGED', 'EXTRA'])->default('FOUND')->change();
        });

        DB::table('asset_count_lines')->whereNull('counted_at')->where('result', 'MISSING')->whereIn('asset_count_id', DB::table('asset_counts')->where('status', 'DRAFT')->select('id'))->update(['result' => 'FOUND']);
    }

    public function down(): void
    {
        Schema::table('asset_count_lines', function (Blueprint $table): void {
            $table->enum('result', ['FOUND', 'MISSING', 'WRONG_LOCATION', 'DAMAGED', 'EXTRA'])->default('MISSING')->change();
        });
    }
};
