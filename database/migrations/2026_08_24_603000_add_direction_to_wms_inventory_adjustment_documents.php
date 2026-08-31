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
            $table->enum('direction', ['GAIN', 'LOSS'])->nullable()->after('document_date');
        });

        DB::table('wms_inventory_adjustment_documents')->orderBy('id')->get(['id'])->each(function (object $document): void {
            $directions = DB::table('wms_inventory_adjustments')
                ->where('document_id', $document->id)
                ->pluck('direction')
                ->unique()
                ->values()
                ->all();

            if (count($directions) !== 1 || ! in_array($directions[0], ['GAIN', 'LOSS'], true)) {
                throw new RuntimeException("Adjustment document {$document->id} has mixed or invalid line directions.");
            }

            $direction = $directions[0];

            DB::table('wms_inventory_adjustment_documents')
                ->where('id', $document->id)
                ->update(['direction' => $direction]);
        });

        Schema::table('wms_inventory_adjustment_documents', function (Blueprint $table): void {
            $table->enum('direction', ['GAIN', 'LOSS'])->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('wms_inventory_adjustment_documents', function (Blueprint $table): void {
            $table->dropColumn('direction');
        });
    }
};
