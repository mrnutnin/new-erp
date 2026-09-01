<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_depreciation_runs', function (Blueprint $table): void {
            $table->foreignId('cancelled_by')->nullable()->after('reversal_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
            $table->dropUnique('asset_depreciation_runs_active_period_book_unique');
        });
        DB::statement("ALTER TABLE asset_depreciation_runs MODIFY status ENUM('CALCULATING','DRAFT','SUBMITTED','APPROVED','POSTED','REVERSED','VOID','FAILED') NOT NULL DEFAULT 'DRAFT'");
        DB::statement("ALTER TABLE asset_depreciation_runs MODIFY active_key TINYINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('REVERSED','VOID','FAILED'), NULL, 1)) VIRTUAL");
        Schema::table('asset_depreciation_runs', fn (Blueprint $table) => $table->unique(['branch_id', 'fiscal_period_id', 'book_type', 'active_key'], 'asset_depreciation_runs_active_period_book_unique'));
    }

    public function down(): void
    {
        Schema::table('asset_depreciation_runs', function (Blueprint $table): void {
            $table->dropUnique('asset_depreciation_runs_active_period_book_unique');
        });
        DB::statement("ALTER TABLE asset_depreciation_runs MODIFY status ENUM('CALCULATING','DRAFT','SUBMITTED','APPROVED','POSTED','REVERSED','FAILED') NOT NULL DEFAULT 'DRAFT'");
        DB::statement("ALTER TABLE asset_depreciation_runs MODIFY active_key TINYINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('REVERSED','FAILED'), NULL, 1)) VIRTUAL");
        Schema::table('asset_depreciation_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
            $table->unique(['branch_id', 'fiscal_period_id', 'book_type', 'active_key'], 'asset_depreciation_runs_active_period_book_unique');
        });
    }
};
