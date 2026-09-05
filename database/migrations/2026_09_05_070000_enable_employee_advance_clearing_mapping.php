<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $accountId = DB::table('accounts')->where('code', '12600')->value('id');
        if (! $accountId) {
            return;
        }

        $now = now();
        $userId = DB::table('users')->where('username', 'admin')->value('id') ?: 1;

        DB::table('accounting_account_mappings')->updateOrInsert(
            ['event_code' => 'employee_advance_clearing', 'key' => 'EMPLOYEE_ADVANCE'],
            ['account_id' => $accountId, 'is_active' => true, 'version' => 1, 'created_by' => $userId, 'updated_by' => $userId, 'updated_at' => $now, 'created_at' => $now],
        );
    }

    public function down(): void
    {
        DB::table('accounting_account_mappings')
            ->where('event_code', 'employee_advance_clearing')
            ->where('key', 'EMPLOYEE_ADVANCE')
            ->delete();
    }
};
