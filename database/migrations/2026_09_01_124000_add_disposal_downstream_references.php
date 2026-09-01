<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('asset_disposals', function (Blueprint $table): void {
            $table->string('proceeds_reference', 100)->nullable()->after('proceeds');
            $table->string('count_reference', 100)->nullable()->after('proceeds_reference');
            $table->string('investigation_reference', 100)->nullable()->after('count_reference');
            $table->string('override_reason', 500)->nullable()->after('investigation_reference');
            $table->index('proceeds_reference', 'asset_disposals_proceeds_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::table('asset_disposals', function (Blueprint $table): void {
            $table->dropIndex('asset_disposals_proceeds_reference_idx');
            $table->dropColumn(['proceeds_reference', 'count_reference', 'investigation_reference', 'override_reason']);
        });
    }
};
