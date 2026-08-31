<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_inventory_adjustment_documents', function (Blueprint $table): void {
            $table->enum('status', ['DRAFT', 'APPROVED', 'POSTED', 'VOID', 'REVERSED'])->default('DRAFT')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('wms_inventory_adjustment_documents')->whereIn('status', ['VOID', 'REVERSED'])->exists()) {
            throw new RuntimeException('Cannot rollback Adjustment status while VOID or REVERSED headers exist.');
        }

        Schema::table('wms_inventory_adjustment_documents', function (Blueprint $table): void {
            $table->enum('status', ['DRAFT', 'APPROVED', 'POSTED'])->default('DRAFT')->change();
        });
    }
};
