<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Disposal removes accumulated balances; older rows were stored with
        // the posting-side debit sign and therefore doubled reconciliation.
        DB::table('asset_value_events')
            ->where('source_type', 'ASSET_DISPOSAL')
            ->whereIn('event_type', ['DISPOSAL', 'WRITE_OFF'])
            ->update([
                'depreciation_delta' => DB::raw('-ABS(depreciation_delta)'),
                'impairment_delta' => DB::raw('-ABS(impairment_delta)'),
            ]);
    }

    public function down(): void
    {
        // Sign normalization is intentionally not reversed; restoring the
        // incorrect values would reintroduce reconciliation errors.
    }
};
