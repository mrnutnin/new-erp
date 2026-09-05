<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $userId = DB::table('users')->where('username', 'admin')->value('id') ?: 1;
        $assetType = DB::table('account_types')->where('code', 'ASSET')->value('id');
        if (! $assetType) {
            return;
        }

        DB::table('accounts')->updateOrInsert(
            ['code' => '12600'],
            ['account_type_id' => $assetType, 'name' => 'เงินทดรองจ่ายพนักงาน', 'level' => 1, 'normal_balance' => 'DEBIT', 'statement_section' => 'BALANCE_SHEET', 'reporting_profile' => 'PAE', 'is_postable' => true, 'is_active' => true, 'updated_by' => $userId, 'updated_at' => $now],
        );
        $accountId = DB::table('accounts')->where('code', '12600')->value('id');
        DB::table('accounting_account_mappings')->updateOrInsert(
            ['event_code' => 'employee_advance', 'key' => 'EMPLOYEE_ADVANCE'],
            ['account_id' => $accountId, 'is_active' => true, 'version' => 1, 'created_by' => $userId, 'updated_by' => $userId, 'updated_at' => $now, 'created_at' => $now],
        );
    }

    public function down(): void
    {
        DB::table('accounting_account_mappings')->where('event_code', 'employee_advance')->delete();
    }
};
