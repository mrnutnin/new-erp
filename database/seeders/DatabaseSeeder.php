<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Program;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Settings\Support\SettingRegistry;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::query()->firstOrCreate(['username' => 'admin'], [
            'name' => 'System Administrator',
            'email' => 'admin@example.com',
            'password' => Hash::make('123132123'),
            'is_active' => true,
        ]);

        $this->call(RbacSeeder::class);
        $this->call(JournalBookSeeder::class);

        $branch = Branch::query()->updateOrCreate(['code' => 'HQ'], [
            'name' => 'สำนักงานใหญ่',
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->updateOrCreate(['code' => 'HQ-WH'], [
            'branch_id' => $branch->id,
            'name' => 'คลังสำนักงานใหญ่',
            'is_active' => true,
        ]);

        $programs = collect([
            ['code' => 'settings', 'name' => 'Global Setting', 'description' => 'ตั้งค่าระบบและข้อมูลบริษัท', 'requires_warehouse' => false, 'entry_route' => 'settings.index'],
            ['code' => 'wms', 'name' => 'Purchasing', 'description' => 'บริหารจัดซื้อ', 'requires_warehouse' => true, 'entry_route' => 'purchasing.index'],
            ['code' => 'inventory', 'name' => 'WMS', 'description' => 'บริหารคลังสินค้าและสต็อก', 'requires_warehouse' => true, 'entry_route' => 'wms.index'],
            ['code' => 'pos', 'name' => 'POS', 'description' => 'ขายและคำสั่งซื้อ', 'requires_warehouse' => true, 'entry_route' => 'pos.index'],
            ['code' => 'production', 'name' => 'Production', 'description' => 'บริหารการผลิต', 'requires_warehouse' => true, 'entry_route' => 'dashboard'],
            ['code' => 'finance', 'name' => 'Finance', 'description' => 'บริหารการเงิน', 'requires_warehouse' => true, 'entry_route' => 'finance.index'],
            ['code' => 'accounting', 'name' => 'Accounting', 'description' => 'บัญชีและรายงานการเงิน', 'requires_warehouse' => true, 'entry_route' => 'accounting.index'],
            ['code' => 'logistics', 'name' => 'Logistics', 'description' => 'บริหารการขนส่ง', 'requires_warehouse' => true, 'entry_route' => 'dashboard'],
            ['code' => 'asset', 'name' => 'Asset', 'description' => 'บริหารสินทรัพย์', 'requires_warehouse' => true, 'entry_route' => 'dashboard'],
        ])->map(function (array $attributes, int $index) {
            return Program::query()->updateOrCreate(['code' => $attributes['code']], [
                ...$attributes,
                'is_enabled' => true,
                'sort_order' => $index + 1,
            ]);
        });

        $user->programs()->sync($programs->pluck('id'));
        $user->warehouses()->sync([$warehouse->id]);

        DocumentSequence::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'document_type' => 'PURCHASE_ORDER'],
            [
                'name' => 'ใบสั่งซื้อ',
                'prefix' => 'PO',
                'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}',
                'reset_rule' => 'YEARLY',
                'next_number' => 1,
                'is_active' => true,
                'created_by' => $user->id,
            ],
        );

        DocumentSequence::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'document_type' => 'SALES_INTAKE'],
            [
                'name' => 'ใบรับข้อมูลเบื้องต้น',
                'prefix' => 'SI',
                'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}',
                'reset_rule' => 'YEARLY',
                'next_number' => 1,
                'is_active' => true,
                'number_reuse_policy' => 'NEVER_REUSE',
                'created_by' => $user->id,
            ],
        );

        foreach ([['SALES_RFQ', 'ใบขอราคา', 'RFQ'], ['SALES_QUOTATION', 'ใบเสนอราคา', 'QT'], ['SALES_ORDER', 'ใบสั่งขาย', 'SO']] as [$type, $name, $prefix]) {
            DocumentSequence::query()->firstOrCreate(
                ['warehouse_id' => $warehouse->id, 'document_type' => $type],
                ['name' => $name, 'prefix' => $prefix, 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'created_by' => $user->id],
            );
        }

        foreach ([['PHYSICAL_SALE_HS', 'ใบขายสด/ใบกำกับภาษี', 'HS'], ['PHYSICAL_SALE_IV', 'ใบส่งสินค้า/ใบกำกับภาษี', 'IV']] as [$type, $name, $prefix]) {
            DocumentSequence::query()->firstOrCreate(
                ['warehouse_id' => $warehouse->id, 'document_type' => $type],
                ['name' => $name, 'prefix' => $prefix, 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'created_by' => $user->id],
            );
        }
        DocumentSequence::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'document_type' => 'SALES_RETURN'],
            ['name' => 'ใบรับคืน/ใบลดหนี้ขาย', 'prefix' => 'SR', 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'created_by' => $user->id],
        );
        DocumentSequence::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'document_type' => 'ADVANCE_DEPOSIT_AI'],
            ['name' => 'ใบรับเงินล่วงหน้า', 'prefix' => 'AI', 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'created_by' => $user->id],
        );

        DocumentSequence::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'document_type' => 'INVENTORY_ADJUSTMENT'],
            [
                'name' => 'ใบปรับปรุงสินค้าคงเหลือ',
                'prefix' => 'ADJ',
                'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}',
                'reset_rule' => 'YEARLY',
                'next_number' => 1,
                'is_active' => true,
                'number_reuse_policy' => 'NEVER_REUSE',
                'created_by' => $user->id,
            ],
        );

        foreach ([
            ['type' => 'INVENTORY_ISSUE', 'name' => 'ใบเบิกสินค้า', 'prefix' => 'ISSUE'],
            ['type' => 'INVENTORY_RETURN', 'name' => 'ใบรับคืนจากการเบิก', 'prefix' => 'IRTN'],
        ] as $issueSequence) {
            DocumentSequence::query()->firstOrCreate(
                ['warehouse_id' => $warehouse->id, 'document_type' => $issueSequence['type']],
                [
                    'name' => $issueSequence['name'],
                    'prefix' => $issueSequence['prefix'],
                    'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}',
                    'reset_rule' => 'YEARLY',
                    'next_number' => 1,
                    'is_active' => true,
                    'number_reuse_policy' => 'NEVER_REUSE',
                    'created_by' => $user->id,
                ],
            );
        }

        collect([
            ['code' => 'ASSET', 'name' => 'สินทรัพย์', 'normal_balance' => 'DEBIT', 'statement_section' => 'BALANCE_SHEET', 'sort_order' => 1],
            ['code' => 'LIABILITY', 'name' => 'หนี้สิน', 'normal_balance' => 'CREDIT', 'statement_section' => 'BALANCE_SHEET', 'sort_order' => 2],
            ['code' => 'EQUITY', 'name' => 'ส่วนของเจ้าของ', 'normal_balance' => 'CREDIT', 'statement_section' => 'BALANCE_SHEET', 'sort_order' => 3],
            ['code' => 'REVENUE', 'name' => 'รายได้', 'normal_balance' => 'CREDIT', 'statement_section' => 'PROFIT_LOSS', 'sort_order' => 4],
            ['code' => 'EXPENSE', 'name' => 'ค่าใช้จ่าย', 'normal_balance' => 'DEBIT', 'statement_section' => 'PROFIT_LOSS', 'sort_order' => 5],
        ])->each(fn (array $attributes) => AccountType::query()->updateOrCreate(['code' => $attributes['code']], $attributes));

        $setting = CompanySetting::query()->firstOrCreate(['id' => 1], [
            'company_name' => 'New ERP',
            'effective_from' => now()->toDateString(),
            'updated_by' => $user->id,
        ])->refresh();

        DB::table('company_setting_versions')->insertOrIgnore([
            'company_setting_id' => $setting->id,
            'version' => $setting->settings_version,
            'effective_from' => $setting->effective_from ?? now()->toDateString(),
            'values' => json_encode($setting->only(array_keys(SettingRegistry::DEFINITIONS)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'change_reason' => 'สร้างค่าเริ่มต้นของระบบ',
            'changed_by' => $user->id,
            'created_at' => now(),
        ]);
    }
}
