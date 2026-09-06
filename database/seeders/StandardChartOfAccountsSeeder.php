<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Accounting\Support\PostingEvent;
use Illuminate\Database\Seeder;

/**
 * Installs the Thai recommended chart of accounts without customer/demo data.
 *
 * Codes are the stable contract. Names and mappings remain editable from UI.
 */
class StandardChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $types = $this->seedAccountTypes();
        $accounts = [];

        foreach ($this->accounts() as $row) {
            $account = Account::withTrashed()->firstOrNew(['code' => $row['code']]);
            $account->fill([
                'account_type_id' => $types[$row['type']],
                'parent_id' => $row['parent'] ? ($accounts[$row['parent']]->id ?? Account::query()->where('code', $row['parent'])->value('id')) : null,
                'name' => $row['name'],
                'level' => $row['level'],
                'normal_balance' => $row['normal'],
                'statement_section' => in_array($row['type'], ['REVENUE', 'EXPENSE'], true) ? 'PROFIT_LOSS' : 'BALANCE_SHEET',
                'reporting_profile' => 'PAE',
                'control_account_type' => $row['control'],
                'is_postable' => $row['postable'],
                'is_active' => true,
            ]);
            $account->deleted_at = null;
            $account->save();
            $accounts[$row['code']] = $account;
        }

        $this->seedMappings($accounts);
    }

    /** @return array<string, int> */
    private function seedAccountTypes(): array
    {
        $definitions = [
            ['code' => 'ASSET', 'name' => 'สินทรัพย์', 'normal_balance' => 'DEBIT', 'statement_section' => 'BALANCE_SHEET', 'sort_order' => 1],
            ['code' => 'LIABILITY', 'name' => 'หนี้สิน', 'normal_balance' => 'CREDIT', 'statement_section' => 'BALANCE_SHEET', 'sort_order' => 2],
            ['code' => 'EQUITY', 'name' => 'ส่วนของเจ้าของ', 'normal_balance' => 'CREDIT', 'statement_section' => 'BALANCE_SHEET', 'sort_order' => 3],
            ['code' => 'REVENUE', 'name' => 'รายได้', 'normal_balance' => 'CREDIT', 'statement_section' => 'PROFIT_LOSS', 'sort_order' => 4],
            ['code' => 'EXPENSE', 'name' => 'ค่าใช้จ่าย', 'normal_balance' => 'DEBIT', 'statement_section' => 'PROFIT_LOSS', 'sort_order' => 5],
        ];

        foreach ($definitions as $definition) {
            AccountType::query()->updateOrCreate(['code' => $definition['code']], $definition);
        }

        return AccountType::query()->pluck('id', 'code')->all();
    }

    /** @return array<int, array{code:string,name:string,type:string,parent:?string,level:int,normal:string,control:?string,postable:bool}> */
    private function accounts(): array
    {
        return [
            ['code' => '10000', 'name' => 'สินทรัพย์', 'type' => 'ASSET', 'parent' => null, 'level' => 1, 'normal' => 'DEBIT', 'control' => null, 'postable' => false],
            ['code' => '11000', 'name' => 'สินทรัพย์หมุนเวียน', 'type' => 'ASSET', 'parent' => '10000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => false],
            ['code' => '11100', 'name' => 'เงินสดและเงินฝากธนาคาร', 'type' => 'ASSET', 'parent' => '11000', 'level' => 3, 'normal' => 'DEBIT', 'control' => null, 'postable' => false],
            ['code' => '11110', 'name' => 'เงินสด', 'type' => 'ASSET', 'parent' => '11100', 'level' => 4, 'normal' => 'DEBIT', 'control' => 'CASH', 'postable' => true],
            ['code' => '11120', 'name' => 'เงินฝากธนาคาร', 'type' => 'ASSET', 'parent' => '11100', 'level' => 4, 'normal' => 'DEBIT', 'control' => 'BANK', 'postable' => true],
            ['code' => '11800', 'name' => 'ภาษีซื้อ', 'type' => 'ASSET', 'parent' => '11000', 'level' => 3, 'normal' => 'DEBIT', 'control' => 'INPUT_VAT', 'postable' => true],
            ['code' => '11810', 'name' => 'ภาษีซื้อพักรอรับรู้', 'type' => 'ASSET', 'parent' => '11000', 'level' => 3, 'normal' => 'DEBIT', 'control' => 'INPUT_VAT', 'postable' => true],
            ['code' => '11900', 'name' => 'ภาษีหัก ณ ที่จ่ายรอรับ', 'type' => 'ASSET', 'parent' => '11000', 'level' => 3, 'normal' => 'DEBIT', 'control' => 'WITHHOLDING_TAX', 'postable' => true],
            ['code' => '12000', 'name' => 'ลูกหนี้การค้า', 'type' => 'ASSET', 'parent' => '11000', 'level' => 3, 'normal' => 'DEBIT', 'control' => 'AR', 'postable' => true],
            ['code' => '12500', 'name' => 'เงินจ่ายล่วงหน้าผู้ขาย', 'type' => 'ASSET', 'parent' => '11000', 'level' => 3, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '12600', 'name' => 'เงินทดรองจ่ายพนักงาน', 'type' => 'ASSET', 'parent' => '11000', 'level' => 3, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '13000', 'name' => 'สินค้าคงเหลือ', 'type' => 'ASSET', 'parent' => '11000', 'level' => 3, 'normal' => 'DEBIT', 'control' => 'INVENTORY', 'postable' => true],
            ['code' => '13500', 'name' => 'งานระหว่างทำ', 'type' => 'ASSET', 'parent' => '11000', 'level' => 3, 'normal' => 'DEBIT', 'control' => 'WIP', 'postable' => true],
            ['code' => '14000', 'name' => 'สินทรัพย์ถาวร', 'type' => 'ASSET', 'parent' => '10000', 'level' => 2, 'normal' => 'DEBIT', 'control' => 'FIXED_ASSET', 'postable' => true],
            ['code' => '14100', 'name' => 'ค่าเสื่อมราคาสะสม', 'type' => 'ASSET', 'parent' => '10000', 'level' => 2, 'normal' => 'CREDIT', 'control' => null, 'postable' => true],
            ['code' => '14200', 'name' => 'ด้อยค่าสะสม', 'type' => 'ASSET', 'parent' => '10000', 'level' => 2, 'normal' => 'CREDIT', 'control' => null, 'postable' => true],
            ['code' => '14500', 'name' => 'สินค้าสำเร็จรูป', 'type' => 'ASSET', 'parent' => '10000', 'level' => 2, 'normal' => 'DEBIT', 'control' => 'INVENTORY', 'postable' => true],
            ['code' => '15140', 'name' => 'บัญชีพักการรับรู้สินทรัพย์', 'type' => 'ASSET', 'parent' => '10000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '15150', 'name' => 'บัญชีพักเงินรับจากการจำหน่ายสินทรัพย์', 'type' => 'ASSET', 'parent' => '10000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '20000', 'name' => 'หนี้สิน', 'type' => 'LIABILITY', 'parent' => null, 'level' => 1, 'normal' => 'CREDIT', 'control' => null, 'postable' => false],
            ['code' => '21000', 'name' => 'เจ้าหนี้การค้า', 'type' => 'LIABILITY', 'parent' => '20000', 'level' => 2, 'normal' => 'CREDIT', 'control' => 'AP', 'postable' => true],
            ['code' => '21400', 'name' => 'ภาษีหัก ณ ที่จ่ายค้างจ่าย', 'type' => 'LIABILITY', 'parent' => '20000', 'level' => 2, 'normal' => 'CREDIT', 'control' => 'WITHHOLDING_TAX', 'postable' => true],
            ['code' => '21500', 'name' => 'เงินรับล่วงหน้าลูกค้า', 'type' => 'LIABILITY', 'parent' => '20000', 'level' => 2, 'normal' => 'CREDIT', 'control' => null, 'postable' => true],
            ['code' => '21800', 'name' => 'ภาษีขาย', 'type' => 'LIABILITY', 'parent' => '20000', 'level' => 2, 'normal' => 'CREDIT', 'control' => 'OUTPUT_VAT', 'postable' => true],
            ['code' => '21810', 'name' => 'ภาษีขายพักรอรับรู้', 'type' => 'LIABILITY', 'parent' => '20000', 'level' => 2, 'normal' => 'CREDIT', 'control' => 'OUTPUT_VAT', 'postable' => true],
            ['code' => '30000', 'name' => 'ส่วนของเจ้าของ', 'type' => 'EQUITY', 'parent' => null, 'level' => 1, 'normal' => 'CREDIT', 'control' => null, 'postable' => false],
            ['code' => '31000', 'name' => 'กำไรสะสม', 'type' => 'EQUITY', 'parent' => '30000', 'level' => 2, 'normal' => 'CREDIT', 'control' => null, 'postable' => true],
            ['code' => '40000', 'name' => 'รายได้', 'type' => 'REVENUE', 'parent' => null, 'level' => 1, 'normal' => 'CREDIT', 'control' => null, 'postable' => false],
            ['code' => '41000', 'name' => 'รายได้จากการขาย', 'type' => 'REVENUE', 'parent' => '40000', 'level' => 2, 'normal' => 'CREDIT', 'control' => null, 'postable' => true],
            ['code' => '42100', 'name' => 'กำไรจากปรับปรุงสินค้าคงเหลือ', 'type' => 'REVENUE', 'parent' => '40000', 'level' => 2, 'normal' => 'CREDIT', 'control' => null, 'postable' => true],
            ['code' => '42200', 'name' => 'กำไรจากปรับต้นทุนสินค้า', 'type' => 'REVENUE', 'parent' => '40000', 'level' => 2, 'normal' => 'CREDIT', 'control' => null, 'postable' => true],
            ['code' => '42300', 'name' => 'เงินเกินจากเงินสดย่อย', 'type' => 'REVENUE', 'parent' => '40000', 'level' => 2, 'normal' => 'CREDIT', 'control' => null, 'postable' => true],
            ['code' => '42400', 'name' => 'กำไรจากการปัดเศษต้นทุน', 'type' => 'REVENUE', 'parent' => '40000', 'level' => 2, 'normal' => 'CREDIT', 'control' => null, 'postable' => true],
            ['code' => '42500', 'name' => 'กำไรจากการจำหน่ายสินทรัพย์', 'type' => 'REVENUE', 'parent' => '40000', 'level' => 2, 'normal' => 'CREDIT', 'control' => null, 'postable' => true],
            ['code' => '50000', 'name' => 'ค่าใช้จ่าย', 'type' => 'EXPENSE', 'parent' => null, 'level' => 1, 'normal' => 'DEBIT', 'control' => null, 'postable' => false],
            ['code' => '51000', 'name' => 'ค่าใช้จ่ายซื้อสินค้า', 'type' => 'EXPENSE', 'parent' => '50000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '52000', 'name' => 'ต้นทุนขาย', 'type' => 'EXPENSE', 'parent' => '50000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '52100', 'name' => 'ขาดทุนจากปรับปรุงสินค้าคงเหลือ', 'type' => 'EXPENSE', 'parent' => '50000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '52200', 'name' => 'ขาดทุนจากปรับต้นทุนสินค้า', 'type' => 'EXPENSE', 'parent' => '50000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '53000', 'name' => 'ค่าใช้จ่ายคอมมิชชั่นขาย', 'type' => 'EXPENSE', 'parent' => '50000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '52300', 'name' => 'เงินขาดจากเงินสดย่อย', 'type' => 'EXPENSE', 'parent' => '50000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '52400', 'name' => 'ขาดทุนจากการปัดเศษต้นทุน', 'type' => 'EXPENSE', 'parent' => '50000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '52500', 'name' => 'ขาดทุนจากการจำหน่ายสินทรัพย์', 'type' => 'EXPENSE', 'parent' => '50000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '52600', 'name' => 'ผลต่างการผลิต', 'type' => 'EXPENSE', 'parent' => '50000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '54000', 'name' => 'ค่าเสื่อมราคา', 'type' => 'EXPENSE', 'parent' => '50000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
            ['code' => '54100', 'name' => 'ขาดทุนจากการด้อยค่าสินทรัพย์', 'type' => 'EXPENSE', 'parent' => '50000', 'level' => 2, 'normal' => 'DEBIT', 'control' => null, 'postable' => true],
        ];
    }

    /** @param array<string, Account> $accounts */
    private function seedMappings(array $accounts): void
    {
        $roles = [
            'ACCOUNTS_RECEIVABLE' => '12000', 'SALES_REVENUE' => '41000', 'ACCOUNTS_PAYABLE' => '21000',
            'CUSTOMER_ADVANCE' => '21500', 'SUPPLIER_ADVANCE' => '12500', 'EMPLOYEE_ADVANCE' => '12600',
            'PURCHASE_EXPENSE' => '51000', 'DEFERRED_INPUT_VAT' => '11810', 'DEFERRED_OUTPUT_VAT' => '21810',
            'INPUT_VAT' => '11800', 'OUTPUT_VAT' => '21800', 'WHT_RECEIVABLE' => '11900', 'WHT_PAYABLE' => '21400',
            'INVENTORY' => '13000', 'COGS' => '52000', 'ADJUSTMENT_GAIN' => '42100', 'ADJUSTMENT_LOSS' => '52100',
            'RECOST_GAIN' => '42200', 'RECOST_LOSS' => '52200', 'COMMISSION_EXPENSE' => '53000',
            'ROUNDING_GAIN' => '42400', 'ROUNDING_LOSS' => '52400',
            'PETTY_CASH_VARIANCE_GAIN' => '42300', 'PETTY_CASH_VARIANCE_LOSS' => '52300',
            'ASSET_COST' => '14000', 'CAPITALIZATION_CLEARING' => '15140', 'DEPRECIATION_EXPENSE' => '54000',
            'ACCUMULATED_DEPRECIATION' => '14100', 'IMPAIRMENT_LOSS' => '54100', 'ACCUMULATED_IMPAIRMENT' => '14200',
            'DISPOSAL_CLEARING' => '15150', 'DISPOSAL_GAIN' => '42500', 'DISPOSAL_LOSS' => '52500',
            'WIP' => '13500', 'FINISHED_GOODS' => '14500', 'PRODUCTION_VARIANCE' => '52600',
        ];

        foreach ($roles as $role => $code) {
            $this->upsertMapping(null, $role, $accounts[$code]->id);
        }

        // Existing posting services still resolve these stable legacy keys.
        // Keep them as aliases while new contracts use event_code + role.
        foreach ([
            'SALES_AR' => '12000', 'SALES_REVENUE_DEFAULT' => '41000', 'PURCHASE_AP' => '21000',
            'CUSTOMER_ADVANCE' => '21500', 'SUPPLIER_ADVANCE' => '12500', 'EMPLOYEE_ADVANCE' => '12600',
            'PURCHASE_EXPENSE_DEFAULT' => '51000', 'DEFERRED_INPUT_VAT' => '11810', 'DEFERRED_OUTPUT_VAT' => '21810',
            'INPUT_VAT' => '11800', 'OUTPUT_VAT' => '21800', 'WHT_RECEIVABLE' => '11900', 'WHT_PAYABLE' => '21400',
            'INVENTORY_DEFAULT' => '13000', 'COGS_DEFAULT' => '52000', 'INVENTORY_ADJUSTMENT_GAIN' => '42100',
            'INVENTORY_ADJUSTMENT_LOSS' => '52100', 'INVENTORY_RECOST_GAIN' => '42200', 'INVENTORY_RECOST_LOSS' => '52200',
        ] as $key => $code) {
            $this->upsertMapping(null, $key, $accounts[$code]->id);
        }

        foreach (PostingEvent::codes() as $eventCode) {
            foreach (PostingEvent::roles($eventCode) as $role) {
                if (isset($roles[$role])) {
                    $this->upsertMapping($eventCode, $role, $accounts[$roles[$role]]->id);
                }
            }
        }
    }

    private function upsertMapping(?string $eventCode, string $key, int $accountId): void
    {
        AccountMapping::query()->updateOrCreate(
            ['event_code' => $eventCode, 'key' => $key],
            ['account_id' => $accountId, 'is_active' => true, 'version' => 1],
        );
    }
}
