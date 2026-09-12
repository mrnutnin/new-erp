<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_issue_types', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->change();
        });

        DB::table('wms_issue_types')
            ->whereNotNull('warehouse_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['code', 'name', 'description', 'is_active', 'created_by'])
            ->each(function (object $legacy): void {
                $exists = DB::table('wms_issue_types')
                    ->whereNull('warehouse_id')
                    ->where('code', $legacy->code)
                    ->exists();

                if (! $exists) {
                    DB::table('wms_issue_types')->insert([
                        'warehouse_id' => null,
                        'code' => $legacy->code,
                        'name' => $legacy->name,
                        'description' => $legacy->description,
                        'is_active' => $legacy->is_active,
                        'created_by' => $legacy->created_by,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (DB::table('wms_issue_types')->whereNull('warehouse_id')->exists()) {
            throw new \RuntimeException('Cannot rollback organization-scoped issue types while global records exist.');
        }

        Schema::table('wms_issue_types', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable(false)->change();
        });
    }
};
