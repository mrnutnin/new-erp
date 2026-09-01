<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_impairments', function (Blueprint $table): void {
            $table->foreignId('journal_entry_id')->nullable()->after('impairment_amount')->constrained('journal_entries');
        });
    }

    public function down(): void
    {
        Schema::table('asset_impairments', function (Blueprint $table): void {
            $table->dropForeign(['journal_entry_id']);
            $table->dropColumn('journal_entry_id');
        });
    }
};
