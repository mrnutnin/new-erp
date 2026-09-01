<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('asset_disposal_lines', function (Blueprint $table): void {
            $table->string('original_status', 30)->nullable()->after('asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('asset_disposal_lines', fn (Blueprint $table) => $table->dropColumn('original_status'));
    }
};
