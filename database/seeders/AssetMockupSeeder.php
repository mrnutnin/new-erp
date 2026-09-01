<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetDepreciationBook;
use App\Modules\Asset\Models\AssetHistory;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\ItemCategory;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Services\PurchaseDocumentPostingService;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;

class AssetMockupSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $branch = Branch::query()->where('code', 'HQ')->firstOrFail();
        $warehouse = Warehouse::query()->where('branch_id', $branch->id)->where('is_active', true)->firstOrFail();
        $types = AccountType::query()->pluck('id', 'code');

        $accounts = collect([
            ['code' => '15100', 'name' => 'สินทรัพย์ถาวร - อุปกรณ์สำนักงาน', 'type' => 'ASSET', 'normal' => 'DEBIT', 'control' => 'FIXED_ASSET'],
            ['code' => '15110', 'name' => 'ค่าเสื่อมราคาสะสม - อุปกรณ์สำนักงาน', 'type' => 'ASSET', 'normal' => 'CREDIT', 'control' => null],
            ['code' => '15120', 'name' => 'ด้อยค่าสะสม - อุปกรณ์สำนักงาน', 'type' => 'ASSET', 'normal' => 'CREDIT', 'control' => null],
            ['code' => '54000', 'name' => 'ค่าเสื่อมราคา - อุปกรณ์สำนักงาน', 'type' => 'EXPENSE', 'normal' => 'DEBIT', 'control' => null],
            ['code' => '54100', 'name' => 'ขาดทุนจากการด้อยค่าสินทรัพย์', 'type' => 'EXPENSE', 'normal' => 'DEBIT', 'control' => null],
            ['code' => '15130', 'name' => 'บัญชีพักเงินรับจากการจำหน่ายสินทรัพย์', 'type' => 'ASSET', 'normal' => 'DEBIT', 'control' => null],
            ['code' => '43000', 'name' => 'กำไรจากการจำหน่ายสินทรัพย์', 'type' => 'REVENUE', 'normal' => 'CREDIT', 'control' => null],
            ['code' => '54200', 'name' => 'ขาดทุนจากการจำหน่ายสินทรัพย์', 'type' => 'EXPENSE', 'normal' => 'DEBIT', 'control' => null],
        ])->mapWithKeys(function (array $row) use ($types, $user): array {
            $account = Account::query()->updateOrCreate(['code' => $row['code']], [
                'account_type_id' => $types[$row['type']],
                'name' => $row['name'],
                'level' => 1,
                'normal_balance' => $row['normal'],
                'statement_section' => $row['type'] === 'EXPENSE' ? 'PROFIT_LOSS' : 'BALANCE_SHEET',
                'reporting_profile' => 'PAE',
                'control_account_type' => $row['control'],
                'is_postable' => true,
                'is_active' => true,
                'updated_by' => $user->id,
            ]);

            return [$row['code'] => $account];
        });

        $categories = collect([
            'IT-EQUIP' => ['อุปกรณ์ไอที', 36],
            'OFFICE-EQUIP' => ['อุปกรณ์สำนักงาน', 60],
        ])->mapWithKeys(function (array $row, string $code) use ($accounts, $user): array {
            $category = AssetCategory::query()->updateOrCreate(['code' => $code], [
                'name' => $row[0],
                'description' => 'ข้อมูลตัวอย่างสำหรับทดสอบโมดูล Asset',
                'is_depreciable' => true,
                'capitalization_threshold' => 5000,
                'book_method' => 'STRAIGHT_LINE',
                'book_useful_life_months' => $row[1],
                'book_residual_value_percent' => 0,
                'tax_method' => 'STRAIGHT_LINE',
                'tax_useful_life_months' => $row[1],
                'asset_account_id' => $accounts['15100']->id,
                'accumulated_depreciation_account_id' => $accounts['15110']->id,
                'depreciation_expense_account_id' => $accounts['54000']->id,
                'accumulated_impairment_account_id' => $accounts['15120']->id,
                'impairment_loss_account_id' => $accounts['54100']->id,
                'disposal_clearing_account_id' => $accounts['15130']->id,
                'disposal_gain_account_id' => $accounts['43000']->id,
                'disposal_loss_account_id' => $accounts['54200']->id,
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            return [$code => $category];
        });

        $office = AssetLocation::query()->updateOrCreate(['branch_id' => $branch->id, 'code' => 'HQ-OFFICE'], [
            'warehouse_id' => null,
            'parent_id' => null,
            'name' => 'สำนักงานสำนักงานใหญ่',
            'location_type' => 'BUILDING',
            'address' => 'สำนักงานใหญ่ (ข้อมูลตัวอย่าง)',
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $itRoom = AssetLocation::query()->updateOrCreate(['branch_id' => $branch->id, 'code' => 'HQ-IT'], [
            'warehouse_id' => $warehouse->id,
            'parent_id' => $office->id,
            'name' => 'ห้องไอที',
            'location_type' => 'ROOM',
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $supplier = Party::query()->where('code', 'SUP-001')->first();
        $assets = [
            ['number' => 'FA-MOCK-HQ-001', 'tag' => 'IT-2026-001', 'name' => 'MacBook Pro 14 นิ้ว', 'category' => 'IT-EQUIP', 'location' => $itRoom, 'cost' => 62000, 'brand' => 'Apple', 'model' => 'MacBook Pro 14', 'serial' => 'MOCK-MBP-001'],
            ['number' => 'FA-MOCK-HQ-002', 'tag' => 'IT-2026-002', 'name' => 'เครื่องพิมพ์เลเซอร์', 'category' => 'IT-EQUIP', 'location' => $itRoom, 'cost' => 18500, 'brand' => 'HP', 'model' => 'LaserJet Pro', 'serial' => 'MOCK-PRN-002'],
            ['number' => 'FA-MOCK-HQ-003', 'tag' => 'OF-2026-001', 'name' => 'โต๊ะทำงานผู้จัดการ', 'category' => 'OFFICE-EQUIP', 'location' => $office, 'cost' => 12900, 'brand' => 'Modernform', 'model' => 'Executive Desk', 'serial' => null],
        ];

        foreach ($assets as $row) {
            $asset = Asset::query()->updateOrCreate(['asset_number' => $row['number']], [
                'tag_number' => $row['tag'],
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $row['location']->id,
                'custodian_user_id' => $user->id,
                'asset_category_id' => $categories[$row['category']]->id,
                'name' => $row['name'],
                'description' => 'สินทรัพย์ตัวอย่างสำหรับทดสอบหน้าจอทะเบียนสินทรัพย์',
                'brand' => $row['brand'],
                'model' => $row['model'],
                'serial_number' => $row['serial'],
                'manufacturer' => $row['brand'],
                'registration_date' => '2026-08-31',
                'acquisition_date' => '2026-08-15',
                'placed_in_service_date' => null,
                'supplier_id' => $supplier?->id,
                'warranty_end_date' => '2027-08-14',
                'original_cost' => $row['cost'],
                'currency_code' => 'THB',
                'exchange_rate' => 1,
                'book_cost' => 0,
                'book_accumulated_depreciation' => 0,
                'book_accumulated_impairment' => 0,
                'book_value' => 0,
                'status' => 'DRAFT',
                'is_depreciation_suspended' => false,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            foreach (['BOOK', 'TAX'] as $bookType) {
                AssetDepreciationBook::query()->updateOrCreate(['asset_id' => $asset->id, 'book_type' => $bookType], [
                    'method' => 'STRAIGHT_LINE',
                    'depreciable_cost' => $row['cost'],
                    'residual_value' => 0,
                    'useful_life_months' => $categories[$row['category']]->book_useful_life_months,
                    'start_date' => '2026-08-15',
                    'accumulated_depreciation' => 0,
                    'is_active' => true,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);
            }

            AssetHistory::query()->firstOrCreate([
                'asset_id' => $asset->id,
                'event_type' => 'REGISTERED_DRAFT',
                'source_document_number' => $asset->asset_number,
            ], [
                'occurred_at' => now(),
                'source_type' => 'ASSET_REGISTER',
                'actor_id' => $user->id,
                'new_branch_id' => $branch->id,
                'new_location_id' => $row['location']->id,
                'new_custodian_user_id' => $user->id,
                'new_status' => 'DRAFT',
                'new_values' => ['mockup' => true, 'original_cost' => $row['cost']],
            ]);
        }

        $paymentTerm = PaymentTerm::query()->where('code', 'NET30')->where('is_active', true)->firstOrFail();
        $expenseAccount = Account::query()->where('code', '51000')->where('is_active', true)->where('is_postable', true)->firstOrFail();
        $itemCategory = ItemCategory::query()->updateOrCreate(['code' => 'MOCK-ASSET'], ['name' => 'รายการสินทรัพย์ตัวอย่าง', 'is_active' => true, 'created_by' => $user->id]);
        $uom = Uom::query()->updateOrCreate(['code' => 'PCS'], ['name' => 'ชิ้น', 'decimal_places' => 0, 'is_active' => true, 'created_by' => $user->id]);
        $assetItems = collect([
            'MOCK-ASSET-IT-BUNDLE' => ['ชุดอุปกรณ์ไอที 2 รายการ', 'IT-EQUIP'],
            'MOCK-ASSET-DESK' => ['โต๊ะทำงานผู้จัดการ', 'OFFICE-EQUIP'],
        ])->mapWithKeys(function (array $row, string $code) use ($itemCategory, $uom, $categories, $user): array {
            $item = Item::query()->withTrashed()->updateOrCreate(['code' => $code], [
                'category_id' => $itemCategory->id,
                'name' => $row[0],
                'item_type' => 'SERVICE',
                'base_uom' => $uom->code,
                'base_uom_id' => $uom->id,
                'is_stock_item' => false,
                'is_asset_capitalizable' => true,
                'default_asset_category_id' => $categories[$row[1]]->id,
                'is_active' => true,
                'created_by' => $user->id,
            ]);
            $item->restore();

            return [$code => $item];
        });
        $invoice = PurchaseDocument::query()->firstOrNew([
            'warehouse_id' => $warehouse->id,
            'document_type' => 'INVOICE',
            'document_number' => 'PI-ASSET-MOCK-002',
        ]);

        if ($invoice->status !== 'POSTED') {
            $invoice->fill([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'supplier_code' => $supplier->code,
                'supplier_name' => $supplier->name,
                'supplier_tax_id' => $supplier->tax_id,
                'supplier_branch_code' => $supplier->branch_code ?? '00000',
                'supplier_address' => $supplier->address,
                'payment_term_id' => $paymentTerm->id,
                'document_date' => '2026-08-31',
                'due_date' => '2026-09-30',
                'tax_treatment' => 'NONE_VAT',
                'prices_include_vat' => false,
                'tax_decimal_places' => 2,
                'subtotal' => 93400,
                'tax_amount' => 0,
                'gross_amount' => 93400,
                'rounding_amount' => 0,
                'status' => 'APPROVED',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'description' => 'Mock ใบตั้งหนี้ซื้อสินทรัพย์: บรรทัดแรกแบ่งรับรู้เป็นสินทรัพย์ 2 รายการได้',
                'created_by' => $invoice->created_by ?? $user->id,
                'updated_by' => $user->id,
            ])->save();

            $invoice->lines()->delete();
            $invoice->lines()->createMany([
                ['line_number' => 1, 'description' => 'ชุดอุปกรณ์ไอที 2 รายการ (แบ่งรับรู้ได้)', 'item_id' => $assetItems['MOCK-ASSET-IT-BUNDLE']->id, 'uom_id' => $uom->id, 'account_id' => $expenseAccount->id, 'quantity' => 2, 'unit_price' => 40250, 'discount_amount' => 0, 'net_amount' => 80500, 'tax_amount' => 0, 'gross_amount' => 80500],
                ['line_number' => 2, 'description' => 'โต๊ะทำงานผู้จัดการ', 'item_id' => $assetItems['MOCK-ASSET-DESK']->id, 'uom_id' => $uom->id, 'account_id' => $expenseAccount->id, 'quantity' => 1, 'unit_price' => 12900, 'discount_amount' => 0, 'net_amount' => 12900, 'tax_amount' => 0, 'gross_amount' => 12900],
            ]);

            app(PurchaseDocumentPostingService::class)->post($invoice, '2026-08-31', $user, Request::create('/seed/asset-mockup', 'POST'));
        }

        $draftInvoice = PurchaseDocument::query()->firstOrNew([
            'warehouse_id' => $warehouse->id,
            'document_type' => 'INVOICE',
            'document_number' => 'PI-ASSET-MOCK-003',
        ]);
        if (! $draftInvoice->exists) {
            $draftInvoice->fill([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'supplier_code' => $supplier->code,
                'supplier_name' => $supplier->name,
                'supplier_tax_id' => $supplier->tax_id,
                'supplier_branch_code' => $supplier->branch_code ?? '00000',
                'supplier_address' => $supplier->address,
                'payment_term_id' => $paymentTerm->id,
                'document_date' => '2026-08-31',
                'due_date' => '2026-09-30',
                'tax_treatment' => 'NONE_VAT',
                'prices_include_vat' => false,
                'tax_decimal_places' => 2,
                'subtotal' => 93400,
                'tax_amount' => 0,
                'gross_amount' => 93400,
                'rounding_amount' => 0,
                'status' => 'DRAFT',
                'description' => 'Mock ร่างใบตั้งหนี้ซื้อสินทรัพย์ สำหรับทดสอบอนุมัติด้วยผู้ใช้',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ])->save();
            $draftInvoice->lines()->createMany([
                ['line_number' => 1, 'description' => 'ชุดอุปกรณ์ไอที 2 รายการ (แบ่งรับรู้ได้)', 'item_id' => $assetItems['MOCK-ASSET-IT-BUNDLE']->id, 'uom_id' => $uom->id, 'account_id' => $expenseAccount->id, 'quantity' => 2, 'unit_price' => 40250, 'discount_amount' => 0, 'net_amount' => 80500, 'tax_amount' => 0, 'gross_amount' => 80500],
                ['line_number' => 2, 'description' => 'โต๊ะทำงานผู้จัดการ', 'item_id' => $assetItems['MOCK-ASSET-DESK']->id, 'uom_id' => $uom->id, 'account_id' => $expenseAccount->id, 'quantity' => 1, 'unit_price' => 12900, 'discount_amount' => 0, 'net_amount' => 12900, 'tax_amount' => 0, 'gross_amount' => 12900],
            ]);
        }
    }
}
