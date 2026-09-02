<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_capitalizations', function (Blueprint $table): void {
            $table->enum('transaction_type', ['CAPITALIZATION', 'ADDITION'])->default('CAPITALIZATION')->after('document_number');
            $table->index(['branch_id', 'transaction_type', 'status', 'document_date'], 'asset_cap_transaction_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('asset_capitalizations', function (Blueprint $table): void {
            $table->dropIndex('asset_cap_transaction_status_date_idx');
            $table->dropColumn('transaction_type');
        });
    }
};
