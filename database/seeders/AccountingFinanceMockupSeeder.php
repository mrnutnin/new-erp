<?php

namespace Database\Seeders;

use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OtherCategory;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Pos\Models\SalesDocument;
use App\Modules\Purchasing\Models\PurchaseDocument;
use Illuminate\Database\Seeder;

class AccountingFinanceMockupSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'HQ-WH')->firstOrFail();
        $types = AccountType::query()->pluck('id', 'code');

        $accounts = collect([
            ['code' => '11000', 'name' => 'เงินสดและเงินฝากธนาคาร', 'type' => 'ASSET', 'normal' => 'DEBIT', 'control' => 'CASH'],
            ['code' => '11111', 'name' => 'บัญชีเงินฝากธนาคาร Mockup', 'type' => 'ASSET', 'normal' => 'DEBIT', 'control' => 'BANK'],
            ['code' => '12000', 'name' => 'ลูกหนี้การค้า', 'type' => 'ASSET', 'normal' => 'DEBIT', 'control' => 'AR'],
            ['code' => '21000', 'name' => 'เจ้าหนี้การค้า', 'type' => 'LIABILITY', 'normal' => 'CREDIT', 'control' => 'AP'],
            ['code' => '21500', 'name' => 'เงินรับล่วงหน้าลูกค้า', 'type' => 'LIABILITY', 'normal' => 'CREDIT', 'control' => null],
            ['code' => '21400', 'name' => 'ภาษีหัก ณ ที่จ่ายค้างจ่าย', 'type' => 'LIABILITY', 'normal' => 'CREDIT', 'control' => 'WITHHOLDING_TAX'],
            ['code' => '41000', 'name' => 'รายได้จากการขาย', 'type' => 'REVENUE', 'normal' => 'CREDIT', 'control' => null],
            ['code' => '51000', 'name' => 'ค่าใช้จ่ายซื้อสินค้า', 'type' => 'EXPENSE', 'normal' => 'DEBIT', 'control' => null],
            ['code' => '53000', 'name' => 'ค่าใช้จ่ายคอมมิชชั่นขาย', 'type' => 'EXPENSE', 'normal' => 'DEBIT', 'control' => null],
            ['code' => '11800', 'name' => 'ภาษีซื้อ', 'type' => 'ASSET', 'normal' => 'DEBIT', 'control' => 'INPUT_VAT'],
            ['code' => '11810', 'name' => 'ภาษีซื้อพักรอรับรู้', 'type' => 'ASSET', 'normal' => 'DEBIT', 'control' => 'INPUT_VAT'],
            ['code' => '21800', 'name' => 'ภาษีขาย', 'type' => 'LIABILITY', 'normal' => 'CREDIT', 'control' => 'OUTPUT_VAT'],
            ['code' => '21810', 'name' => 'ภาษีขายพักรอรับรู้', 'type' => 'LIABILITY', 'normal' => 'CREDIT', 'control' => 'OUTPUT_VAT'],
            ['code' => '11900', 'name' => 'ภาษีหัก ณ ที่จ่ายรอรับ', 'type' => 'ASSET', 'normal' => 'DEBIT', 'control' => 'WITHHOLDING_TAX'],
            ['code' => '12500', 'name' => 'เงินจ่ายล่วงหน้าผู้ขาย', 'type' => 'ASSET', 'normal' => 'DEBIT', 'control' => null],
            ['code' => '13000', 'name' => 'สินค้าคงเหลือ', 'type' => 'ASSET', 'normal' => 'DEBIT', 'control' => 'INVENTORY'],
            ['code' => '52000', 'name' => 'ต้นทุนขาย', 'type' => 'EXPENSE', 'normal' => 'DEBIT', 'control' => null],
            ['code' => '42100', 'name' => 'กำไรจากปรับปรุงสินค้าคงเหลือ', 'type' => 'REVENUE', 'normal' => 'CREDIT', 'control' => null],
            ['code' => '52100', 'name' => 'ขาดทุนจากปรับปรุงสินค้าคงเหลือ', 'type' => 'EXPENSE', 'normal' => 'DEBIT', 'control' => null],
            ['code' => '42200', 'name' => 'กำไรจากปรับต้นทุนสินค้า', 'type' => 'REVENUE', 'normal' => 'CREDIT', 'control' => null],
            ['code' => '52200', 'name' => 'ขาดทุนจากปรับต้นทุนสินค้า', 'type' => 'EXPENSE', 'normal' => 'DEBIT', 'control' => null],
        ])->mapWithKeys(function (array $row) use ($types, $user): array {
            $account = Account::query()->updateOrCreate(['code' => $row['code']], [
                'account_type_id' => $types[$row['type']], 'name' => $row['name'], 'level' => 1,
                'normal_balance' => $row['normal'], 'statement_section' => $row['type'] === 'REVENUE' || $row['type'] === 'EXPENSE' ? 'PROFIT_LOSS' : 'BALANCE_SHEET',
                'reporting_profile' => 'PAE', 'control_account_type' => $row['control'], 'is_postable' => true, 'is_active' => true, 'updated_by' => $user->id,
            ]);

            return [$row['code'] => $account];
        });

        $taxCodes = [
            'NONE' => ['name' => 'ไม่คิด VAT', 'kind' => 'NONE_VAT', 'rate' => 0],
            'VAT7-OUT' => ['name' => 'VAT ขาย 7%', 'kind' => 'VAT_OUT', 'rate' => 7],
            'VAT7-IN' => ['name' => 'VAT ซื้อ 7%', 'kind' => 'VAT_IN', 'rate' => 7],
            'WHT3' => ['name' => 'หัก ณ ที่จ่าย 3%', 'kind' => 'WHT', 'rate' => 3],
        ];
        foreach ($taxCodes as $code => $values) {
            TaxCode::query()->updateOrCreate(['code' => $code], [...$values, 'is_active' => true, 'created_by' => $user->id]);
        }

        foreach ([
            'SALES_AR' => '12000',
            'SALES_REVENUE_DEFAULT' => '41000',
            'PURCHASE_AP' => '21000',
            'CUSTOMER_ADVANCE' => '21500',
            'SUPPLIER_ADVANCE' => '12500',
            'PURCHASE_EXPENSE_DEFAULT' => '51000',
            'SALES_COMMISSION_EXPENSE' => '53000',
            'DEFERRED_INPUT_VAT' => '11810',
            'DEFERRED_OUTPUT_VAT' => '21810',
            'INPUT_VAT' => '11800',
            'OUTPUT_VAT' => '21800',
            'WHT_RECEIVABLE' => '11900',
            'WHT_PAYABLE' => '21400',
            'INVENTORY_DEFAULT' => '13000',
            'COGS_DEFAULT' => '52000',
            'INVENTORY_ADJUSTMENT_GAIN' => '42100',
            'INVENTORY_ADJUSTMENT_LOSS' => '52100',
            'INVENTORY_RECOST_GAIN' => '42200',
            'INVENTORY_RECOST_LOSS' => '52200',
        ] as $key => $accountCode) {
            AccountMapping::query()->updateOrCreate(['event_code' => null, 'key' => $key], [
                'account_id' => $accounts[$accountCode]->id,
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        foreach (['OUTPUT_VAT' => '21800', 'WHT_RECEIVABLE' => '11900', 'CUSTOMER_ADVANCE' => '21500'] as $key => $accountCode) {
            AccountMapping::query()->updateOrCreate(['event_code' => 'customer_payment', 'key' => $key], [
                'account_id' => $accounts[$accountCode]->id,
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        foreach (['INPUT_VAT' => '11800', 'WHT_PAYABLE' => '21400', 'SUPPLIER_ADVANCE' => '12500'] as $key => $accountCode) {
            AccountMapping::query()->updateOrCreate(['event_code' => 'supplier_payment', 'key' => $key], [
                'account_id' => $accounts[$accountCode]->id,
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        foreach (['CUSTOMER_ADVANCE' => '21500', 'WHT_RECEIVABLE' => '11900'] as $key => $accountCode) {
            AccountMapping::query()->updateOrCreate(['event_code' => 'customer_advance', 'key' => $key], [
                'account_id' => $accounts[$accountCode]->id,
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        foreach (['ACCOUNTS_RECEIVABLE' => '12000', 'DEFERRED_OUTPUT_VAT' => '21810', 'WHT_RECEIVABLE' => '11900', 'CUSTOMER_ADVANCE' => '21500'] as $key => $accountCode) {
            AccountMapping::query()->updateOrCreate(['event_code' => 'sales_invoice', 'key' => $key], [
                'account_id' => $accounts[$accountCode]->id,
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        AccountMapping::query()->updateOrCreate(['event_code' => 'sales_commission_payout', 'key' => 'COMMISSION_EXPENSE'], [
            'account_id' => $accounts['53000']->id,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        BankAccount::query()->updateOrCreate(['warehouse_id' => $warehouse->id, 'code' => 'BANK-HQ'], [
            'account_id' => $accounts['11111']->id, 'type' => 'BANK', 'name' => 'ธนาคาร Mockup สำนักงานใหญ่', 'bank_name' => 'ธนาคารตัวอย่าง', 'account_number' => '123-4-56789-0', 'currency_code' => 'THB', 'is_active' => true, 'created_by' => $user->id,
        ]);
        $net30 = PaymentTerm::query()->updateOrCreate(['code' => 'NET30'], ['name' => 'เครดิต 30 วัน', 'credit_days' => 30, 'due_rule' => 'DUE_ON_DATE', 'is_active' => true, 'created_by' => $user->id]);
        $cod = PaymentTerm::query()->updateOrCreate(['code' => 'COD'], ['name' => 'ชำระเงินสด/ทันที', 'credit_days' => 0, 'due_rule' => 'DUE_ON_DATE', 'is_active' => true, 'created_by' => $user->id]);
        $customer = Party::query()->withTrashed()->updateOrCreate(['code' => 'CUST-001'], [
            'name' => 'ลูกค้าตัวอย่าง', 'type' => 'COMPANY', 'branch_code' => '00000', 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $supplier = Party::query()->withTrashed()->updateOrCreate(['code' => 'SUP-001'], [
            'name' => 'Supplier ตัวอย่าง', 'type' => 'COMPANY', 'branch_code' => '00000', 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $customer->restore();
        $supplier->restore();
        PartyRole::query()->updateOrCreate(['party_id' => $customer->id, 'role' => 'CUSTOMER'], ['payment_term_id' => $net30->id, 'credit_limit' => 100000, 'is_active' => true]);
        PartyRole::query()->updateOrCreate(['party_id' => $supplier->id, 'role' => 'SUPPLIER'], ['payment_term_id' => $net30->id, 'credit_limit' => 100000, 'is_active' => true]);
        OtherCategory::query()->updateOrCreate(['kind' => 'INCOME', 'code' => 'OTHER-INCOME'], ['name' => 'รายได้เบ็ดเตล็ด', 'account_id' => $accounts['41000']->id, 'tax_code_id' => null, 'is_active' => true, 'created_by' => $user->id]);
        OtherCategory::query()->updateOrCreate(['kind' => 'EXPENSE', 'code' => 'OTHER-EXPENSE'], ['name' => 'ค่าใช้จ่ายเบ็ดเตล็ด', 'account_id' => $accounts['51000']->id, 'tax_code_id' => null, 'is_active' => true, 'created_by' => $user->id]);
        $upsertSequence = function (string $documentType, string $name, string $prefix, int $initialNextNumber) use ($warehouse, $user): void {
            $sequence = DocumentSequence::query()->firstOrNew([
                'warehouse_id' => $warehouse->id,
                'document_type' => $documentType,
            ]);
            $sequence->fill([
                'name' => $name,
                'prefix' => $prefix,
                'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}',
                'reset_rule' => 'YEARLY',
                'is_active' => true,
                'created_by' => $user->id,
            ]);
            if (! $sequence->exists) {
                $sequence->next_number = $initialNextNumber;
            }
            $sequence->save();
        };
        $upsertSequence('RECEIPT', 'ใบรับเงิน', 'RC', 2);
        $upsertSequence('PAYMENT', 'ใบจ่ายเงิน', 'PV', 2);
        foreach ([
            'SALES_INVOICE' => ['ใบแจ้งหนี้ขาย', 'SI'],
            'SALES_CREDIT_NOTE' => ['ใบลดหนี้ขาย', 'SCN'],
            'PURCHASE_INVOICE' => ['ใบแจ้งหนี้ซื้อ', 'PI'],
            'PURCHASE_CREDIT_NOTE' => ['ใบลดหนี้ซื้อ', 'PCN'],
            'PURCHASE_ORDER' => ['ใบสั่งซื้อ', 'PO'],
        ] as $documentType => [$name, $prefix]) {
            $upsertSequence($documentType, $name, $prefix, 1);
        }

        $salesDraft = SalesDocument::query()->updateOrCreate([
            'warehouse_id' => $warehouse->id,
            'document_type' => 'INVOICE',
            'document_number' => 'SI-MOCK-001',
        ], [
            'party_id' => $customer->id,
            'payment_term_id' => $net30->id,
            'document_date' => '2026-08-20',
            'due_date' => '2026-09-19',
            'price_includes_vat' => false,
            'tax_decimal_places' => 2,
            'party_code' => $customer->code,
            'party_name' => $customer->name,
            'party_tax_id' => $customer->tax_id,
            'party_branch_code' => $customer->branch_code,
            'party_address' => $customer->address,
            'subtotal' => 1500,
            'discount_amount' => 0,
            'tax_base' => 1500,
            'tax_amount' => 0,
            'total_amount' => 1500,
            'status' => 'DRAFT',
            'description' => 'Mock ใบแจ้งหนี้ค่าบริการแบบ NONE VAT',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $salesDraft->lines()->delete();
        $salesDraft->lines()->create([
            'line_number' => 1,
            'description' => 'ค่าบริการตัวอย่าง',
            'quantity' => 1,
            'unit' => 'งาน',
            'unit_price' => 1500,
            'discount_amount' => 0,
            'revenue_account_id' => $accounts['41000']->id,
            'tax_code_id' => TaxCode::query()->where('code', 'NONE')->value('id'),
            'tax_rate' => 0,
            'tax_base' => 1500,
            'tax_amount' => 0,
            'line_total' => 1500,
        ]);

        $purchaseDraft = PurchaseDocument::query()->updateOrCreate([
            'warehouse_id' => $warehouse->id,
            'document_type' => 'INVOICE',
            'document_number' => 'PI-MOCK-001',
        ], [
            'supplier_id' => $supplier->id,
            'supplier_code' => $supplier->code,
            'supplier_name' => $supplier->name,
            'supplier_tax_id' => $supplier->tax_id,
            'supplier_branch_code' => $supplier->branch_code,
            'supplier_address' => $supplier->address,
            'payment_term_id' => $net30->id,
            'document_date' => '2026-08-20',
            'due_date' => '2026-09-19',
            'tax_treatment' => 'NONE_VAT',
            'prices_include_vat' => false,
            'tax_decimal_places' => 2,
            'subtotal' => 900,
            'tax_amount' => 0,
            'gross_amount' => 900,
            'rounding_amount' => 0,
            'status' => 'DRAFT',
            'description' => 'Mock ใบตั้งหนี้ค่าบริการแบบ NONE VAT',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $purchaseDraft->lines()->delete();
        $purchaseDraft->lines()->create([
            'line_number' => 1,
            'description' => 'ค่าบริการ Supplier ตัวอย่าง',
            'account_id' => $accounts['51000']->id,
            'quantity' => 1,
            'unit_price' => 900,
            'discount_amount' => 0,
            'net_amount' => 900,
            'tax_amount' => 0,
            'gross_amount' => 900,
        ]);

        $year = FiscalYear::query()->updateOrCreate(['code' => 'MOCK-2026'], ['name' => 'Mockup Fiscal Year 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'created_by' => $user->id]);
        FiscalPeriod::query()->firstOrCreate(['fiscal_year_id' => $year->id, 'period_number' => 8], ['name' => 'สิงหาคม 2026', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'OPEN']);

        $post = app(JournalPostingService::class);
        $salesInvoice = $post->post([
            'source_type' => 'POS', 'source_id' => 'MOCK-SALES-INVOICE-001', 'event_code' => 'sales_invoice', 'entry_date' => '2026-08-01', 'document_date' => '2026-08-01', 'source_reference' => 'INV-2026-0001', 'description' => 'Mock ใบแจ้งหนี้ลูกค้า CUST-001',
            'lines' => [
                ['account_id' => $accounts['12000']->id, 'debit' => 2500, 'credit' => 0, 'subledger_type' => 'CUSTOMER', 'subledger_id' => 'CUST-001'],
                ['account_id' => $accounts['41000']->id, 'debit' => 0, 'credit' => 2500],
            ],
        ], $warehouse, $user);

        $supplierInvoice = $post->post([
            'source_type' => 'PURCHASING', 'source_id' => 'MOCK-SUPPLIER-INVOICE-001', 'event_code' => 'supplier_invoice.expense', 'entry_date' => '2026-08-02', 'document_date' => '2026-08-02', 'source_reference' => 'BILL-2026-0001', 'description' => 'Mock ใบแจ้งหนี้เจ้าหนี้ SUP-001',
            'lines' => [
                ['account_id' => $accounts['51000']->id, 'debit' => 2000, 'credit' => 0],
                ['account_id' => $accounts['21000']->id, 'debit' => 0, 'credit' => 2000, 'subledger_type' => 'SUPPLIER', 'subledger_id' => 'SUP-001'],
            ],
        ], $warehouse, $user);

        $receipt = $post->post([
            'source_type' => 'FINANCE', 'source_id' => 'MOCK-RECEIPT-001', 'event_code' => 'customer_payment', 'entry_date' => '2026-08-05', 'document_date' => '2026-08-01', 'source_reference' => 'RC-2026-0001', 'description' => 'Mock รับชำระเงินลูกค้า พร้อม VAT ขาย',
            'lines' => [
                ['account_id' => $accounts['11000']->id, 'debit' => 1070, 'credit' => 0, 'subledger_type' => 'BANK', 'subledger_id' => 'BANK-HQ'],
                ['account_id' => $accounts['12000']->id, 'debit' => 0, 'credit' => 1000, 'subledger_type' => 'CUSTOMER', 'subledger_id' => 'CUST-001'],
                ['account_id' => $accounts['21800']->id, 'debit' => 0, 'credit' => 70, 'tax_code_id' => TaxCode::where('code', 'VAT7-OUT')->value('id'), 'tax_base' => 1000, 'tax_amount' => 70, 'tax_point_date' => '2026-08-01', 'tax_settlement_date' => '2026-08-05', 'subledger_type' => 'TAX', 'subledger_id' => 'VAT-OUT'],
            ],
        ], $warehouse, $user);

        $payment = $post->post([
            'source_type' => 'FINANCE', 'source_id' => 'MOCK-PAYMENT-001', 'event_code' => 'supplier_payment', 'entry_date' => '2026-08-12', 'document_date' => '2026-08-10', 'source_reference' => 'PV-2026-0001', 'description' => 'Mock จ่ายเจ้าหนี้ พร้อม VAT ซื้อและ WHT',
            'lines' => [
                ['account_id' => $accounts['21000']->id, 'debit' => 1070, 'credit' => 0, 'subledger_type' => 'SUPPLIER', 'subledger_id' => 'SUP-001'],
                ['account_id' => $accounts['11000']->id, 'debit' => 0, 'credit' => 1040, 'subledger_type' => 'BANK', 'subledger_id' => 'BANK-HQ'],
                ['account_id' => $accounts['21400']->id, 'debit' => 0, 'credit' => 30, 'tax_code_id' => TaxCode::where('code', 'WHT3')->value('id'), 'tax_base' => 1000, 'tax_amount' => 30, 'tax_point_date' => '2026-08-10', 'tax_settlement_date' => '2026-08-12', 'subledger_type' => 'TAX', 'subledger_id' => 'WHT-001'],
            ],
        ], $warehouse, $user);

        $bank = BankAccount::where('code', 'BANK-HQ')->where('warehouse_id', $warehouse->id)->first();
        $receiptSettlement = Settlement::query()->updateOrCreate(['document_number' => 'RC-2026-0001'], ['document_type' => 'RECEIPT', 'document_date' => '2026-08-01', 'settlement_date' => '2026-08-05', 'party_type' => 'CUSTOMER', 'party_id' => $customer->id, 'bank_account_id' => $bank->id, 'payment_term_id' => $cod->id, 'journal_entry_id' => $receipt->id, 'gross_amount' => 1070, 'tax_amount' => 70, 'withholding_amount' => 0, 'net_amount' => 1070, 'status' => 'POSTED', 'description' => 'Mock รับชำระเงินลูกค้า', 'created_by' => $user->id]);
        $paymentSettlement = Settlement::query()->updateOrCreate(['document_number' => 'PV-2026-0001'], ['document_type' => 'PAYMENT', 'document_date' => '2026-08-10', 'settlement_date' => '2026-08-12', 'party_type' => 'SUPPLIER', 'party_id' => $supplier->id, 'bank_account_id' => $bank->id, 'payment_term_id' => $cod->id, 'journal_entry_id' => $payment->id, 'gross_amount' => 1070, 'tax_amount' => 0, 'withholding_amount' => 30, 'net_amount' => 1040, 'status' => 'POSTED', 'description' => 'Mock จ่ายเจ้าหนี้', 'created_by' => $user->id]);

        $salesInvoiceLine = $salesInvoice->lines()->where('account_id', $accounts['12000']->id)->firstOrFail();
        $receiptLine = $receipt->lines()->where('account_id', $accounts['12000']->id)->firstOrFail();
        $supplierInvoiceLine = $supplierInvoice->lines()->where('account_id', $accounts['21000']->id)->firstOrFail();
        $paymentLine = $payment->lines()->where('account_id', $accounts['21000']->id)->firstOrFail();

        $openItems = app(OpenItemService::class);
        $arInvoice = $openItems->recordFromJournalLine($salesInvoiceLine, ['document_type' => 'INVOICE', 'document_number' => 'INV-2026-0001', 'due_date' => '2026-08-03']);
        $arReceipt = $openItems->recordFromJournalLine($receiptLine, ['document_type' => 'RECEIPT', 'document_number' => 'RC-2026-0001', 'due_date' => null]);
        $apInvoice = $openItems->recordFromJournalLine($supplierInvoiceLine, ['document_type' => 'INVOICE', 'document_number' => 'BILL-2026-0001', 'due_date' => '2026-08-07']);
        $apPayment = $openItems->recordFromJournalLine($paymentLine, ['document_type' => 'PAYMENT', 'document_number' => 'PV-2026-0001', 'due_date' => null]);

        $openItems->allocate(['debit_open_item_id' => $arInvoice->id, 'credit_open_item_id' => $arReceipt->id, 'allocation_date' => '2026-08-05', 'amount' => '600.00', 'source_type' => 'MOCKUP', 'source_id' => 'MOCK-AR-ALLOCATION-001'], $user);
        $openItems->allocate(['debit_open_item_id' => $apPayment->id, 'credit_open_item_id' => $apInvoice->id, 'allocation_date' => '2026-08-12', 'amount' => '700.00', 'source_type' => 'MOCKUP', 'source_id' => 'MOCK-AP-ALLOCATION-001'], $user);
        $receiptSettlement->allocationIntents()->updateOrCreate(['open_item_id' => $arInvoice->id], ['line_number' => 1, 'amount' => '600.00']);
        $paymentSettlement->allocationIntents()->updateOrCreate(['open_item_id' => $apInvoice->id], ['line_number' => 1, 'amount' => '700.00']);
    }
}
