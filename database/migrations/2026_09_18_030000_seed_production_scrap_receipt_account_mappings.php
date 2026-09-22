<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('accounting_account_mappings') || ! DB::getSchemaBuilder()->hasTable('accounts')) {
            return;
        }

        $now = now();
        foreach (['SCRAP_INVENTORY' => '14500', 'WIP' => '13500'] as $key => $code) {
            $accountId = DB::table('accounts')->where('code', $code)->whereNull('deleted_at')->value('id');
            if (! $accountId) {
                continue;
            }
            DB::table('accounting_account_mappings')->updateOrInsert(
                ['event_code' => 'production.scrap_receipt', 'key' => $key],
                ['account_id' => $accountId, 'is_active' => true, 'version' => 1, 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        if (DB::getSchemaBuilder()->hasTable('accounting_account_mappings')) {
            DB::table('accounting_account_mappings')->where('event_code', 'production.scrap_receipt')->whereIn('key', ['SCRAP_INVENTORY', 'WIP'])->delete();
        }
    }
};
