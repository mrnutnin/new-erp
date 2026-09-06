<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $userId = DB::table('users')->where('username', 'admin')->value('id') ?: 1;
        $now = now();

        foreach (['INVENTORY' => '13000', 'RECOST_GAIN' => '42200', 'RECOST_LOSS' => '52200'] as $role => $accountCode) {
            $accountId = DB::table('accounts')->where('code', $accountCode)->value('id');
            if ($accountId === null) {
                continue;
            }

            $mapping = DB::table('accounting_account_mappings')
                ->where('event_code', 'inventory.recost')
                ->where('key', $role)
                ->first();

            if ($mapping) {
                DB::table('accounting_account_mappings')->where('id', $mapping->id)->update([
                    'account_id' => $accountId,
                    'is_active' => true,
                    'updated_by' => $userId,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('accounting_account_mappings')->insert([
                    'event_code' => 'inventory.recost',
                    'key' => $role,
                    'account_id' => $accountId,
                    'is_active' => true,
                    'version' => 1,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('accounting_account_mappings')->where('event_code', 'inventory.recost')->whereIn('key', ['INVENTORY', 'RECOST_GAIN', 'RECOST_LOSS'])->delete();
    }
};
