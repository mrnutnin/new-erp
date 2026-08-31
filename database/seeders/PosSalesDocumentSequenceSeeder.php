<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Finance\Models\DocumentSequence;
use Illuminate\Database\Seeder;

class PosSalesDocumentSequenceSeeder extends Seeder
{
    public function run(): void
    {
        $warehouse = Warehouse::query()->where('is_active', true)->firstOrFail();
        $user = User::query()->where('username', 'admin')->first();

        foreach ([
            ['SALES_RFQ', 'ใบขอราคา', 'RFQ'],
            ['SALES_QUOTATION', 'ใบเสนอราคา', 'QT'],
            ['SALES_ORDER', 'ใบสั่งขาย', 'SO'],
            ['PHYSICAL_SALE_HS', 'ใบขายสด/ใบกำกับภาษี', 'HS'],
            ['PHYSICAL_SALE_IV', 'ใบส่งสินค้า/ใบกำกับภาษี', 'IV'],
        ] as [$type, $name, $prefix]) {
            DocumentSequence::query()->updateOrCreate(
                ['warehouse_id' => $warehouse->id, 'document_type' => $type],
                [
                    'name' => $name,
                    'prefix' => $prefix,
                    'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}',
                    'reset_rule' => 'YEARLY',
                    'next_number' => 1,
                    'is_active' => true,
                    'number_reuse_policy' => 'NEVER_REUSE',
                    'created_by' => $user?->id,
                ],
            );
        }
    }
}
