<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_count_lines', function (Blueprint $table): void {
            $table->dropForeign(['asset_id']);
            $table->foreignId('asset_id')->nullable()->change();
            $table->foreign('asset_id')->references('id')->on('assets')->restrictOnDelete();
            $table->enum('result', ['FOUND', 'MISSING', 'WRONG_LOCATION', 'DAMAGED', 'EXTRA'])->default('FOUND')->change();
        });
    }

    public function down(): void
    {
        Schema::table('asset_count_lines', function (Blueprint $table): void {
            $table->enum('result', ['FOUND', 'MISSING', 'WRONG_LOCATION', 'DAMAGED'])->default('FOUND')->change();
            $table->dropForeign(['asset_id']);
            $table->foreignId('asset_id')->nullable(false)->change();
            $table->foreign('asset_id')->references('id')->on('assets')->restrictOnDelete();
        });
    }
};
