<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wms_cost_recalculation_requests')) {
            return;
        }

        // SQLite does not support MySQL's ALTER TABLE ... MODIFY syntax.
        // The enum is enforced by MySQL; SQLite remains permissive for tests.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE wms_cost_recalculation_requests MODIFY status ENUM('PENDING', 'PROCESSING', 'RESOLVED', 'FAILED', 'STALE') NOT NULL DEFAULT 'PENDING'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('wms_cost_recalculation_requests')) {
            return;
        }

        DB::table('wms_cost_recalculation_requests')->where('status', 'STALE')->update(['status' => 'FAILED']);

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE wms_cost_recalculation_requests MODIFY status ENUM('PENDING', 'PROCESSING', 'RESOLVED', 'FAILED') NOT NULL DEFAULT 'PENDING'");
    }
};
