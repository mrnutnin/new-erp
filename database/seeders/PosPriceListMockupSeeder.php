<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Modules\Pos\Models\PriceList;
use App\Modules\Wms\Models\Item;
use Illuminate\Database\Seeder;

/** Optional local fixture: php artisan db:seed --class=PosPriceListMockupSeeder */
final class PosPriceListMockupSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $branch = Branch::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        $items = Item::query()->where('is_active', true)->whereNotNull('base_uom_id')->orderBy('id')->limit(2)->get();
        if ($items->isEmpty()) {
            $this->command?->warn('ข้าม PosPriceListMockupSeeder: ยังไม่มีสินค้าที่กำหนด Base UOM');

            return;
        }

        $companyId = (int) (CompanySetting::query()->value('id') ?: 1);
        CustomerGroup::query()->updateOrCreate(['company_setting_id' => $companyId, 'code' => 'WHOLESALE'], [
            'name' => 'ลูกค้าขายส่ง (Mockup)', 'is_active' => true, 'created_by' => $user->id, 'updated_by' => $user->id,
        ]);

        $this->syncList($branch->id, $user->id, 'RETAIL-MOCK', 'ราคาขายปลีก (Mockup)', null, $items, ['1000.0000', '950.0000', '900.0000']);
        $this->syncList($branch->id, $user->id, 'WHOLESALE-MOCK', 'ราคาขายส่ง (Mockup)', 'WHOLESALE', $items, ['900.0000', '850.0000', '800.0000']);
    }

    private function syncList(int $branchId, int $userId, string $code, string $name, ?string $group, $items, array $prices): void
    {
        $list = PriceList::query()->withTrashed()->updateOrCreate(['branch_id' => $branchId, 'code' => $code], [
            'name' => $name, 'currency' => 'THB', 'customer_group_code' => $group, 'priority' => $group ? 20 : 10,
            'is_active' => true, 'created_by' => $userId, 'updated_by' => $userId,
        ]);
        $list->restore();

        foreach ($items as $itemIndex => $item) {
            foreach ([1, 10, 50] as $tierIndex => $minimumQuantity) {
                $line = $list->items()->withTrashed()->updateOrCreate([
                    'item_id' => $item->id, 'uom_id' => $item->base_uom_id, 'minimum_quantity' => $minimumQuantity,
                ], [
                    'unit_price' => $prices[$tierIndex], 'discount_percent' => '0.0000', 'is_active' => true,
                    'created_by' => $userId, 'updated_by' => $userId,
                ]);
                $line->restore();
            }
        }
    }
}
