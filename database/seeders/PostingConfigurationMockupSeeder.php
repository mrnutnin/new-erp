<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\AccountType;
use Illuminate\Database\Seeder;

/** Seeds only missing event mappings; it never rewrites existing mappings or journals. */
class PostingConfigurationMockupSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $types = AccountType::query()->pluck('id', 'code');

        $this->ensureAccount('15140', 'บัญชีพักการรับรู้สินทรัพย์', $types['ASSET'], $user->id);

        $accountCodes = [
            'supplier_invoice.inventory' => ['INVENTORY' => '13000', 'ACCOUNTS_PAYABLE' => '21000'],
            'supplier_invoice.expense' => ['PURCHASE_EXPENSE' => '51000', 'ACCOUNTS_PAYABLE' => '21000', 'DEFERRED_INPUT_VAT' => '11810'],
            'sales_invoice' => ['ACCOUNTS_RECEIVABLE' => '12000', 'DEFERRED_OUTPUT_VAT' => '21810', 'WHT_RECEIVABLE' => '11900', 'CUSTOMER_ADVANCE' => '21500'],
            'customer_payment' => ['OUTPUT_VAT' => '21800', 'WHT_RECEIVABLE' => '11900', 'CUSTOMER_ADVANCE' => '21500'],
            'customer_advance' => ['CUSTOMER_ADVANCE' => '21500', 'WHT_RECEIVABLE' => '11900'],
            'supplier_payment' => ['INPUT_VAT' => '11800', 'WHT_PAYABLE' => '21400', 'SUPPLIER_ADVANCE' => '12500'],
            'sales_commission_payout' => ['COMMISSION_EXPENSE' => '53000'],
            'inventory_adjustment' => ['INVENTORY' => '13000', 'ADJUSTMENT_GAIN' => '42100', 'ADJUSTMENT_LOSS' => '52100'],
            'asset.depreciation' => ['DEPRECIATION_EXPENSE' => '54000', 'ACCUMULATED_DEPRECIATION' => '15110'],
            'asset.capitalization' => ['ASSET_COST' => '15100', 'CAPITALIZATION_CLEARING' => '15140'],
            'asset.addition' => ['ASSET_COST' => '15100', 'CAPITALIZATION_CLEARING' => '15140'],
            'asset.impairment' => ['IMPAIRMENT_LOSS' => '54100', 'ACCUMULATED_IMPAIRMENT' => '15120'],
            'asset.disposal' => ['ASSET_COST' => '15100', 'ACCUMULATED_DEPRECIATION' => '15110', 'ACCUMULATED_IMPAIRMENT' => '15120', 'DISPOSAL_CLEARING' => '15130', 'DISPOSAL_GAIN' => '43000', 'DISPOSAL_LOSS' => '54200'],
            'asset.write_off' => ['ASSET_COST' => '15100', 'ACCUMULATED_DEPRECIATION' => '15110', 'ACCUMULATED_IMPAIRMENT' => '15120', 'DISPOSAL_LOSS' => '54200'],
        ];

        foreach ($accountCodes as $eventCode => $roles) {
            foreach ($roles as $role => $accountCode) {
                $account = Account::query()->where('code', $accountCode)->firstOrFail();
                AccountMapping::query()->firstOrCreate(
                    ['event_code' => $eventCode, 'key' => $role],
                    ['account_id' => $account->id, 'is_active' => true, 'version' => 1, 'created_by' => $user->id, 'updated_by' => $user->id],
                );
            }
        }
    }

    private function ensureAccount(string $code, string $name, int $typeId, int $userId): void
    {
        Account::query()->firstOrCreate(['code' => $code], [
            'account_type_id' => $typeId, 'name' => $name, 'level' => 1, 'normal_balance' => 'CREDIT',
            'statement_section' => 'BALANCE_SHEET', 'reporting_profile' => 'PAE', 'is_postable' => true,
            'is_active' => true, 'updated_by' => $userId,
        ]);
    }
}
