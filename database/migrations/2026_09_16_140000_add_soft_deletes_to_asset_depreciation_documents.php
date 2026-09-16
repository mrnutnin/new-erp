<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asset_depreciation_runs', 'deleted_at')) {
            Schema::table('asset_depreciation_runs', fn (Blueprint $table) => $table->softDeletes());
        }
        if ($this->hasIndex('asset_depreciation_runs', 'asset_depreciation_runs_active_period_book_unique')) {
            Schema::table('asset_depreciation_runs', fn (Blueprint $table) => $table->dropUnique('asset_depreciation_runs_active_period_book_unique'));
        }

        DB::statement("ALTER TABLE asset_depreciation_runs MODIFY active_key TINYINT UNSIGNED GENERATED ALWAYS AS (IF(deleted_at IS NOT NULL OR status IN ('REVERSED','VOID','FAILED'), NULL, 1)) VIRTUAL");

        if (! $this->hasIndex('asset_depreciation_runs', 'asset_depreciation_runs_active_period_book_unique')) {
            Schema::table('asset_depreciation_runs', fn (Blueprint $table) => $table->unique(['branch_id', 'fiscal_period_id', 'book_type', 'active_key'], 'asset_depreciation_runs_active_period_book_unique'));
        }

        if (! Schema::hasColumn('asset_depreciation_policy_changes', 'deleted_at')) {
            Schema::table('asset_depreciation_policy_changes', fn (Blueprint $table) => $table->softDeletes());
        }
        if ($this->hasIndex('asset_depreciation_policy_changes', 'asset_depreciation_policy_active_effective_unique')) {
            Schema::table('asset_depreciation_policy_changes', fn (Blueprint $table) => $table->dropUnique('asset_depreciation_policy_active_effective_unique'));
        }

        DB::statement("ALTER TABLE asset_depreciation_policy_changes MODIFY active_key TINYINT UNSIGNED GENERATED ALWAYS AS (IF(deleted_at IS NOT NULL OR status = 'VOID', NULL, 1)) VIRTUAL");

        if (! $this->hasIndex('asset_depreciation_policy_changes', 'asset_depreciation_policy_active_effective_unique')) {
            Schema::table('asset_depreciation_policy_changes', fn (Blueprint $table) => $table->unique(['asset_depreciation_book_id', 'effective_date', 'active_key'], 'asset_depreciation_policy_active_effective_unique'));
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('asset_depreciation_policy_changes', 'asset_depreciation_policy_active_effective_unique')) {
            Schema::table('asset_depreciation_policy_changes', fn (Blueprint $table) => $table->dropUnique('asset_depreciation_policy_active_effective_unique'));
        }

        DB::statement("ALTER TABLE asset_depreciation_policy_changes MODIFY active_key TINYINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'VOID', NULL, 1)) VIRTUAL");

        Schema::table('asset_depreciation_policy_changes', function (Blueprint $table): void {
            $table->unique(['asset_depreciation_book_id', 'effective_date', 'active_key'], 'asset_depreciation_policy_active_effective_unique');
            $table->dropSoftDeletes();
        });

        Schema::table('asset_depreciation_runs', function (Blueprint $table): void {
            $table->dropUnique('asset_depreciation_runs_active_period_book_unique');
        });

        DB::statement("ALTER TABLE asset_depreciation_runs MODIFY active_key TINYINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('REVERSED','VOID','FAILED'), NULL, 1)) VIRTUAL");

        Schema::table('asset_depreciation_runs', function (Blueprint $table): void {
            $table->unique(['branch_id', 'fiscal_period_id', 'book_type', 'active_key'], 'asset_depreciation_runs_active_period_book_unique');
            $table->dropSoftDeletes();
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains('name', $name);
    }
};
