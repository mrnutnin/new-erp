<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_depreciation_policy_changes', function (Blueprint $table): void {
            $table->index('asset_depreciation_book_id', 'asset_dep_policy_book_idx');
            $table->dropUnique('asset_depreciation_policy_effective_unique');
            $table->enum('status', ['DRAFT', 'APPROVED', 'VOID'])->default('DRAFT')->change();
            $table->foreignId('cancelled_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
            $table->unsignedTinyInteger('active_key')->virtualAs("IF(`status` = 'VOID', NULL, 1)")->after('updated_at');
            $table->unique(['asset_depreciation_book_id', 'effective_date', 'active_key'], 'asset_depreciation_policy_active_effective_unique');
        });
    }

    public function down(): void
    {
        Schema::table('asset_depreciation_policy_changes', function (Blueprint $table): void {
            $table->dropUnique('asset_depreciation_policy_active_effective_unique');
            $table->dropColumn(['active_key', 'cancellation_reason', 'cancelled_at']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->enum('status', ['DRAFT', 'APPROVED'])->default('DRAFT')->change();
            $table->unique(['asset_depreciation_book_id', 'effective_date'], 'asset_depreciation_policy_effective_unique');
            $table->dropIndex('asset_dep_policy_book_idx');
        });
    }
};
