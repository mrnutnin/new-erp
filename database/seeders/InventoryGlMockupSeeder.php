<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\ItemCategory;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Models\UomConversion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent Inventory→GL smoke data. It intentionally creates no Posted
 * Journal, Movement, Cost Layer or Allocation transaction.
 */
class InventoryGlMockupSeeder extends Seeder
{
    public const ITEM_CODE = 'MOCK-ITEM-001';

    public const UOM_CODE = 'PCS';

    public const PURCHASE_UOM_CODE = 'BOX';

    public const CATEGORY_CODE = 'MOCK-GOODS';

    public const PURCHASE_NUMBER = 'PI-INVENTORY-MOCK-001';

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedFixture();
        }, 3);
    }

    private function seedFixture(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'HQ-WH')->firstOrFail();
        $settings = CompanySetting::query()->firstOrFail();
        $settingDefaults = [];
        if ($settings->inventory_costing_method === null) {
            $settingDefaults['inventory_costing_method'] = 'AVG';
        }
        if ($settings->allow_negative_stock === null) {
            $settingDefaults['allow_negative_stock'] = false;
        }
        if ($settings->negative_stock_cost_method === null) {
            $settingDefaults['negative_stock_cost_method'] = 'CURRENT_AVERAGE';
        }
        if ($settingDefaults !== []) {
            $previousVersion = (int) $settings->settings_version;
            $settings->forceFill($settingDefaults)->save();
            $settings->increment('settings_version');
            app(GlobalSettings::class)->forget($previousVersion);
        }
        app(GlobalSettings::class)->forget((int) $settings->fresh()->settings_version);
        $assetType = AccountType::query()->where('code', 'ASSET')->value('id');
        $expenseType = AccountType::query()->where('code', 'EXPENSE')->value('id');
        $revenueType = AccountType::query()->where('code', 'REVENUE')->value('id');
        $inventory = Account::query()->updateOrCreate(['code' => '13000'], ['account_type_id' => $assetType, 'name' => 'สินค้าคงเหลือ', 'level' => 1, 'normal_balance' => 'DEBIT', 'statement_section' => 'BALANCE_SHEET', 'reporting_profile' => 'PAE', 'control_account_type' => 'INVENTORY', 'is_postable' => true, 'is_active' => true, 'updated_by' => $user->id]);
        $cogs = Account::query()->updateOrCreate(['code' => '52000'], ['account_type_id' => $expenseType, 'name' => 'ต้นทุนสินค้าตัวอย่าง', 'level' => 1, 'normal_balance' => 'DEBIT', 'statement_section' => 'PROFIT_LOSS', 'reporting_profile' => 'PAE', 'control_account_type' => null, 'is_postable' => true, 'is_active' => true, 'updated_by' => $user->id]);
        $sales = Account::query()->updateOrCreate(['code' => '41000'], ['account_type_id' => $revenueType, 'name' => 'รายได้จากการขายสินค้าตัวอย่าง', 'level' => 1, 'normal_balance' => 'CREDIT', 'statement_section' => 'PROFIT_LOSS', 'reporting_profile' => 'PAE', 'control_account_type' => null, 'is_postable' => true, 'is_active' => true, 'updated_by' => $user->id]);
        $supplier = Party::query()->where('code', 'SUP-001')->firstOrFail();
        $paymentTerm = PaymentTerm::query()->where('code', 'NET30')->where('is_active', true)->firstOrFail();

        $category = ItemCategory::query()->updateOrCreate(['code' => self::CATEGORY_CODE], ['name' => 'สินค้าตัวอย่าง', 'is_active' => true, 'created_by' => $user->id]);
        $uom = Uom::query()->updateOrCreate(['code' => self::UOM_CODE], ['name' => 'ชิ้น', 'decimal_places' => 2, 'is_active' => true, 'created_by' => $user->id]);
        $purchaseUom = Uom::query()->updateOrCreate(['code' => self::PURCHASE_UOM_CODE], ['name' => 'กล่อง', 'decimal_places' => 2, 'is_active' => true, 'created_by' => $user->id]);
        UomConversion::query()->updateOrCreate(
            ['from_uom_id' => $purchaseUom->id, 'to_uom_id' => $uom->id, 'effective_from' => '2026-01-01'],
            ['factor' => '12.00000000', 'effective_to' => null, 'created_by' => $user->id],
        );
        $item = Item::query()->withTrashed()->updateOrCreate(['code' => self::ITEM_CODE], [
            'category_id' => $category->id, 'name' => 'สินค้าคงคลังตัวอย่าง', 'item_type' => 'GOODS',
            'base_uom' => self::UOM_CODE, 'base_uom_id' => $uom->id, 'is_stock_item' => true,
            'inventory_account_id' => $inventory->id, 'cogs_account_id' => $cogs->id,
            'sales_account_id' => $sales->id, 'is_active' => true, 'created_by' => $user->id,
        ]);
        $item->restore();

        $document = PurchaseDocument::query()->firstOrNew([
            'warehouse_id' => $warehouse->id, 'document_type' => 'INVOICE', 'document_number' => self::PURCHASE_NUMBER,
        ]);
        if (! $document->exists || $document->status === 'DRAFT') {
            $document->fill([
                'supplier_id' => $supplier->id, 'supplier_code' => $supplier->code, 'supplier_name' => $supplier->name,
                'document_date' => '2026-08-21', 'payment_term_id' => $paymentTerm->id, 'due_date' => '2026-09-20',
                'tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false,
                'tax_decimal_places' => 2, 'subtotal' => 1000, 'tax_amount' => 0, 'gross_amount' => 1000,
                'rounding_amount' => 0, 'status' => 'DRAFT', 'description' => 'Mock Inventory Purchase — ยังไม่ Post',
                'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
            $document->save();
            $document->lines()->updateOrCreate(['line_number' => 1], [
                'description' => 'สินค้าคงคลังตัวอย่าง', 'item_id' => $item->id, 'uom_id' => $uom->id,
                'account_id' => $inventory->id, 'quantity' => 10, 'unit_price' => 100, 'discount_amount' => 0,
                'net_amount' => 1000, 'tax_amount' => 0, 'gross_amount' => 1000,
            ]);
        }
    }
}
