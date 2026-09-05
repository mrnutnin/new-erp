<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\OtherCategory;
use App\Modules\Finance\Models\PettyCashFund;
use App\Modules\Finance\Models\PettyCashTopUp;
use App\Modules\Finance\Models\PettyCashVoucher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class FinancePettyCashMockSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $actor = User::query()->orderBy('id')->firstOrFail();
            $warehouse = Warehouse::query()->where('code', 'HQ-WH')->firstOrFail();
            $cash = BankAccount::query()->where('warehouse_id', $warehouse->id)->where('type', 'CASH')->where('is_active', true)->firstOrFail();
            $bank = BankAccount::query()->where('warehouse_id', $warehouse->id)->where('type', 'BANK')->where('is_active', true)->firstOrFail();
            $gain = $this->account('43100', 'เงินเกินจากเงินสดย่อย', 'REVENUE', $actor->id);
            $loss = $this->account('54300', 'เงินขาดจากเงินสดย่อย', 'EXPENSE', $actor->id);
            foreach (['PETTY_CASH_VARIANCE_GAIN' => $gain, 'PETTY_CASH_VARIANCE_LOSS' => $loss] as $role => $account) AccountMapping::query()->updateOrCreate(['event_code' => 'petty_cash_clearing', 'key' => $role], ['account_id' => $account->id, 'is_active' => true, 'version' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $fund = PettyCashFund::query()->firstOrCreate(['warehouse_id' => $warehouse->id, 'bank_account_id' => $cash->id], ['custodian_user_id' => $actor->id, 'fund_limit' => '5000.00', 'is_active' => true, 'created_by' => $actor->id]);
            PettyCashTopUp::query()->firstOrCreate(['document_number' => 'MOCK-PC-TOPUP-0001'], ['petty_cash_fund_id' => $fund->id, 'warehouse_id' => $warehouse->id, 'document_date' => today(), 'source_bank_account_id' => $bank->id, 'source_bank_account_code' => $bank->code, 'source_bank_account_name' => $bank->name, 'source_account_id' => $bank->account_id, 'source_account_code' => $bank->account->code, 'source_account_name' => $bank->account->name, 'cash_bank_account_id' => $cash->id, 'cash_bank_account_code' => $cash->code, 'cash_bank_account_name' => $cash->name, 'cash_account_id' => $cash->account_id, 'cash_account_code' => $cash->account->code, 'cash_account_name' => $cash->account->name, 'amount' => '3000.00', 'description' => 'ข้อมูลจำลองเติมเงินสดย่อย', 'status' => 'DRAFT', 'created_by' => $actor->id]);
            $voucher = PettyCashVoucher::query()->firstOrCreate(['document_number' => 'MOCK-PC-VOUCHER-0001'], ['petty_cash_fund_id' => $fund->id, 'warehouse_id' => $warehouse->id, 'document_date' => today(), 'payee_name' => 'ผู้รับเงินทดสอบ', 'description' => 'ข้อมูลจำลองใบสำคัญเงินสดย่อย', 'total_amount' => '850.00', 'status' => 'DRAFT', 'created_by' => $actor->id]);
            $category = OtherCategory::query()->where('kind', 'EXPENSE')->where('is_active', true)->firstOrFail();
            $voucher->lines()->firstOrCreate(['line_number' => 1], ['expense_category_id' => $category->id, 'expense_category_code' => $category->code, 'expense_category_name' => $category->name, 'expense_account_id' => $category->account_id, 'expense_account_code' => $category->account->code, 'expense_account_name' => $category->account->name, 'description' => 'ค่าใช้จ่ายทดสอบ', 'receipt_reference' => 'MOCK-RC-0001', 'amount' => '850.00']);
        });
    }

    private function account(string $code, string $name, string $type, int $userId): Account
    {
        $accountType = AccountType::query()->where('code', $type)->firstOrFail();
        return Account::query()->firstOrCreate(['code' => $code], ['account_type_id' => $accountType->id, 'name' => $name, 'level' => 1, 'normal_balance' => $accountType->normal_balance, 'statement_section' => $accountType->statement_section, 'is_postable' => true, 'is_active' => true, 'updated_by' => $userId]);
    }
}
