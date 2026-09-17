<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_transfers', function (Blueprint $table): void {
            $table->text('note')->nullable()->after('document_date');
        });
    }

    public function down(): void
    {
        Schema::table('wms_transfers', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
