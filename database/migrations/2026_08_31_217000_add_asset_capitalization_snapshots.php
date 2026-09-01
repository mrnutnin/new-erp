<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asset_capitalization_lines', 'asset_account_id')) {
            Schema::table('asset_capitalization_lines', function (Blueprint $table): void {
                // Keep the category/account decision immutable after approval.
                $table->foreignId('asset_account_id')->nullable()->after('capitalized_cost')->constrained('accounts')->restrictOnDelete();
                $table->json('book_profile_snapshot')->nullable()->after('asset_account_id');
                $table->json('tax_profile_snapshot')->nullable()->after('book_profile_snapshot');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('asset_capitalization_lines', 'asset_account_id')) {
            Schema::table('asset_capitalization_lines', function (Blueprint $table): void {
                $table->dropForeign(['asset_account_id']);
                $table->dropColumn(['asset_account_id', 'book_profile_snapshot', 'tax_profile_snapshot']);
            });
        }
    }
};
