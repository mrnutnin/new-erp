<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE asset_value_events MODIFY event_type ENUM('OPENING', 'CAPITALIZATION', 'ADDITION', 'IMPROVEMENT', 'DEPRECIATION', 'IMPAIRMENT', 'IMPAIRMENT_REVERSAL', 'DISPOSAL', 'WRITE_OFF', 'REVERSAL') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE asset_value_events MODIFY event_type ENUM('OPENING', 'CAPITALIZATION', 'ADDITION', 'IMPROVEMENT', 'IMPAIRMENT', 'IMPAIRMENT_REVERSAL', 'DISPOSAL', 'WRITE_OFF', 'REVERSAL') NOT NULL");
    }
};
