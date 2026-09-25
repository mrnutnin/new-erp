<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Program;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Support\FiscalPeriodSchedule;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Pos\Models\PriceList;
use App\Modules\Pos\Models\PriceListItem;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomLine;
use App\Modules\Production\Models\BomOperation;
use App\Modules\Production\Models\BomRevision;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\ItemCategory;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Models\UomConversion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class UatDemoDataSeeder extends Seeder
{
    public function run(): array
    {
        $actor = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->first();
        if (! $actor) {
            throw new RuntimeException('สร้าง Administrator ก่อนสร้าง UAT Demo Data');
        }

        return DB::transaction(function () use ($actor): array {
            $accounts = Account::query()->whereIn('code', ['11110', '11120', '13000', '14000', '14100', '14200', '41000', '42500', '52000', '52500', '54000', '54100', '15150'])
                ->where('is_active', true)->where('is_postable', true)->get()->keyBy('code');
            foreach (['11110', '11120', '13000', '41000', '52000'] as $code) {
                if (! $accounts->has($code)) {
                    throw new RuntimeException('กรุณา Initialize System Defaults ให้เสร็จก่อน (ไม่พบบัญชี '.$code.')');
                }
            }

            $branch = $this->ensure(Branch::class, ['code' => 'UAT'], [
                'name' => 'สาขาทดสอบ UAT', 'tax_branch_code' => '00000', 'is_active' => true,
            ]);
            $warehouse = $this->ensure(Warehouse::class, ['code' => 'UAT-WH'], [
                'branch_id' => $branch->id, 'name' => 'คลังทดสอบ UAT', 'is_active' => true,
            ]);
            $actor->branches()->syncWithoutDetaching([$branch->id]);
            $actor->warehouses()->syncWithoutDetaching([$warehouse->id]);
            $actor->programs()->syncWithoutDetaching(Program::query()->where('is_enabled', true)->pluck('programs.id'));
            if (Program::query()->where('code', 'accounting')->where('is_enabled', true)->exists()) {
                $this->ensureOpenFiscalYear($actor);
            }

            $uoms = collect([
                ['code' => 'UAT-EA', 'name' => 'ชิ้น (UAT)', 'decimal_places' => 0],
                ['code' => 'UAT-BOX', 'name' => 'กล่อง (UAT)', 'decimal_places' => 0],
                ['code' => 'UAT-KG', 'name' => 'กิโลกรัม (UAT)', 'decimal_places' => 3],
                ['code' => 'UAT-G', 'name' => 'กรัม (UAT)', 'decimal_places' => 0],
            ])->mapWithKeys(fn (array $row): array => [$row['code'] => $this->ensure(Uom::class, ['code' => $row['code']], [...$row, 'is_active' => true, 'created_by' => $actor->id])]);

            foreach ([[$uoms['UAT-BOX'], $uoms['UAT-EA'], '12'], [$uoms['UAT-KG'], $uoms['UAT-G'], '1000']] as [$from, $to, $factor]) {
                $this->ensure(UomConversion::class, ['from_uom_id' => $from->id, 'to_uom_id' => $to->id, 'effective_from' => '2026-01-01'], [
                    'factor' => $factor, 'effective_to' => null, 'created_by' => $actor->id,
                ]);
            }

            $categories = collect([
                ['code' => 'UAT-MATERIAL', 'name' => 'วัตถุดิบตัวอย่าง UAT'],
                ['code' => 'UAT-FINISHED', 'name' => 'สินค้าสำเร็จรูปตัวอย่าง UAT'],
                ['code' => 'UAT-SERVICE', 'name' => 'บริการตัวอย่าง UAT'],
            ])->mapWithKeys(fn (array $row): array => [$row['code'] => $this->ensure(ItemCategory::class, ['code' => $row['code']], [...$row, 'is_active' => true, 'created_by' => $actor->id])]);

            $items = collect([
                ['code' => 'UAT-RM-001', 'name' => 'วัตถุดิบตัวอย่าง', 'category' => 'UAT-MATERIAL', 'uom' => 'UAT-KG', 'item_type' => 'GOODS', 'stock' => true, 'manufacture' => false, 'scrap' => false, 'price' => '100'],
                ['code' => 'UAT-FG-001', 'name' => 'สินค้าสำเร็จรูปตัวอย่าง', 'category' => 'UAT-FINISHED', 'uom' => 'UAT-EA', 'item_type' => 'GOODS', 'stock' => true, 'manufacture' => true, 'scrap' => false, 'price' => '250'],
                ['code' => 'UAT-SVC-001', 'name' => 'บริการตัวอย่าง', 'category' => 'UAT-SERVICE', 'uom' => 'UAT-EA', 'item_type' => 'SERVICE', 'stock' => false, 'manufacture' => false, 'scrap' => false, 'price' => '500'],
            ])->mapWithKeys(function (array $row) use ($categories, $uoms, $accounts, $actor): array {
                $item = $this->ensure(Item::class, ['code' => $row['code']], [
                    'category_id' => $categories[$row['category']]->id, 'name' => $row['name'], 'item_type' => $row['item_type'],
                    'base_uom' => $uoms[$row['uom']]->code, 'base_uom_id' => $uoms[$row['uom']]->id,
                    'is_stock_item' => $row['stock'], 'can_manufacture' => $row['manufacture'],
                    'inventory_account_id' => $row['stock'] ? $accounts['13000']->id : null,
                    'sales_account_id' => $accounts['41000']->id, 'cogs_account_id' => $row['stock'] ? $accounts['52000']->id : null,
                    'is_active' => true, 'created_by' => $actor->id,
                ]);

                return [$row['code'] => ['item' => $item, ...$row]];
            });

            $cod = $this->ensure(PaymentTerm::class, ['code' => 'UAT-COD'], [
                'name' => 'ชำระทันที (UAT)', 'credit_days' => 0, 'due_rule' => 'DUE_ON_DATE', 'is_active' => true, 'created_by' => $actor->id,
            ]);
            $net30 = $this->ensure(PaymentTerm::class, ['code' => 'UAT-NET30'], [
                'name' => 'เครดิต 30 วัน (UAT)', 'credit_days' => 30, 'due_rule' => 'DUE_ON_DATE', 'is_active' => true, 'created_by' => $actor->id,
            ]);
            foreach ([['UAT-CUST-001', 'ลูกค้าตัวอย่าง UAT', 'CUSTOMER', $cod], ['UAT-SUP-001', 'ผู้ขายตัวอย่าง UAT', 'SUPPLIER', $net30]] as [$code, $name, $role, $term]) {
                $party = $this->ensure(Party::class, ['code' => $code], [
                    'name' => $name, 'type' => 'COMPANY', 'branch_code' => '00000', 'is_active' => true,
                    'created_by' => $actor->id, 'updated_by' => $actor->id,
                ]);
                PartyRole::query()->updateOrCreate(['party_id' => $party->id, 'role' => $role], [
                    'payment_term_id' => $term->id, 'credit_limit' => 0, 'is_active' => true,
                ]);
            }

            foreach ([['UAT-CASH', 'เงินสดทดสอบ UAT', 'CASH', '11110'], ['UAT-BANK', 'ธนาคารทดสอบ UAT', 'BANK', '11120']] as [$code, $name, $type, $accountCode]) {
                $this->ensure(BankAccount::class, ['warehouse_id' => $warehouse->id, 'code' => $code], [
                    'account_id' => $accounts[$accountCode]->id, 'type' => $type, 'name' => $name,
                    'bank_name' => $type === 'BANK' ? 'ธนาคารตัวอย่าง' : null,
                    'account_number' => $type === 'BANK' ? 'UAT-0001' : null,
                    'currency_code' => 'THB', 'is_active' => true, 'created_by' => $actor->id,
                ]);
            }

            $priceList = $this->ensure(PriceList::class, ['branch_id' => $branch->id, 'code' => 'UAT-BASE'], [
                'name' => 'ราคาขายมาตรฐาน UAT', 'currency' => 'THB', 'priority' => 1,
                'effective_from' => '2026-01-01', 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            foreach ($items as $row) {
                $this->ensure(PriceListItem::class, [
                    'price_list_id' => $priceList->id, 'item_id' => $row['item']->id,
                    'uom_id' => $row['item']->base_uom_id, 'effective_from' => '2026-01-01',
                ], [
                    'minimum_quantity' => 1, 'unit_price' => $row['price'], 'discount_percent' => 0,
                    'effective_to' => null, 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
                ]);
            }

            $assetCategory = null;
            $assetLocation = null;
            if (Program::query()->where('code', 'asset')->where('is_enabled', true)->exists()) {
                $requiredAssetAccounts = ['14000', '14100', '14200', '54000', '54100', '15150', '42500', '52500'];
                if ($accounts->keys()->intersect($requiredAssetAccounts)->count() !== count($requiredAssetAccounts)) {
                    throw new RuntimeException('บัญชีมาตรฐานสำหรับ Asset ยังไม่พร้อม กรุณา Apply System Defaults');
                }
                $assetCategory = $this->ensure(AssetCategory::class, ['code' => 'UAT-OFFICE'], [
                    'name' => 'อุปกรณ์สำนักงาน UAT', 'description' => 'หมวดสินทรัพย์ตัวอย่างสำหรับ UAT',
                    'is_depreciable' => true, 'capitalization_threshold' => 5000, 'book_method' => 'STRAIGHT_LINE',
                    'book_useful_life_months' => 36, 'book_residual_value_percent' => 0,
                    'tax_method' => 'STRAIGHT_LINE', 'tax_useful_life_months' => 36,
                    'asset_account_id' => $accounts['14000']->id, 'accumulated_depreciation_account_id' => $accounts['14100']->id,
                    'depreciation_expense_account_id' => $accounts['54000']->id, 'accumulated_impairment_account_id' => $accounts['14200']->id,
                    'impairment_loss_account_id' => $accounts['54100']->id, 'disposal_clearing_account_id' => $accounts['15150']->id,
                    'disposal_gain_account_id' => $accounts['42500']->id, 'disposal_loss_account_id' => $accounts['52500']->id,
                    'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
                ]);
                $assetLocation = $this->ensure(AssetLocation::class, ['branch_id' => $branch->id, 'code' => 'UAT-OFFICE'], [
                    'warehouse_id' => $warehouse->id, 'name' => 'สำนักงานทดสอบ UAT', 'location_type' => 'BUILDING',
                    'address' => 'สถานที่ตัวอย่างสำหรับ UAT', 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
                ]);
            }

            $bom = null;
            if (Program::query()->where('code', 'production')->where('is_enabled', true)->exists()) {
                $bom = $this->ensure(Bom::class, ['branch_id' => $branch->id, 'code' => 'UAT-BOM-001'], [
                    'name' => 'BOM สินค้าตัวอย่าง UAT', 'finished_item_id' => $items['UAT-FG-001']['item']->id,
                    'base_uom_id' => $uoms['UAT-EA']->id, 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
                ]);
                $revision = BomRevision::query()->firstOrCreate(['bom_id' => $bom->id, 'revision_number' => 1], [
                    'status' => 'ACTIVE', 'effective_from' => '2026-01-01', 'activated_at' => now(),
                    'activated_by' => $actor->id, 'created_by' => $actor->id,
                ]);
                BomLine::query()->firstOrCreate(['bom_revision_id' => $revision->id, 'line_number' => 1], [
                    'component_item_id' => $items['UAT-RM-001']['item']->id, 'uom_id' => $uoms['UAT-KG']->id,
                    'quantity' => 0.25, 'notes' => 'วัตถุดิบตัวอย่างสำหรับ UAT',
                ]);
                BomOperation::query()->firstOrCreate(['bom_revision_id' => $revision->id, 'sequence' => 1], [
                    'name' => 'ประกอบสินค้า', 'planned_minutes' => 30, 'notes' => 'ขั้นตอนตัวอย่าง UAT',
                ]);
            }

            return [
                'branch' => $branch, 'warehouse' => $warehouse,
                'counts' => [
                    'uoms' => $uoms->count(), 'conversions' => 2, 'items' => $items->count(),
                    'parties' => 2, 'bank_accounts' => 2, 'price_lists' => 1,
                    'bom' => $bom ? 1 : 0, 'asset_category' => $assetCategory ? 1 : 0, 'asset_location' => $assetLocation ? 1 : 0,
                ],
            ];
        });
    }

    private function ensureOpenFiscalYear(User $actor): void
    {
        if (FiscalPeriod::query()->where('status', 'OPEN')->exists()) {
            return;
        }

        $start = now()->startOfYear()->toDateString();
        $end = FiscalPeriodSchedule::endDate($start);
        if (FiscalYear::query()->where('start_date', '<=', $end)->where('end_date', '>=', $start)->exists()) {
            return;
        }

        $year = FiscalYear::query()->firstOrCreate(['code' => 'UAT-'.now()->format('Y')], [
            'name' => 'UAT '.now()->year, 'start_date' => $start, 'end_date' => $end, 'created_by' => $actor->id,
        ]);
        if ($year->periods()->doesntExist()) {
            $year->periods()->createMany(FiscalPeriodSchedule::periods($start));
        }
    }

    private function ensure(string $modelClass, array $keys, array $attributes): Model
    {
        $model = $modelClass::withTrashed()->firstOrCreate($keys, $attributes);
        if ($model->trashed()) {
            $model->restore();
        }

        return $model;
    }
}
