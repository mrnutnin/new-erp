<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Finance\Models\DocumentSequence;
use Illuminate\Database\Seeder;

final class WmsDocumentSequenceSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->value('id');

        Warehouse::query()->whereNull('deleted_at')->each(function (Warehouse $warehouse) use ($userId): void {
            foreach ([
                ['INVENTORY_ISSUE', 'ใบเบิกสินค้า', 'ISSUE'],
                ['INVENTORY_RETURN', 'ใบรับคืนจากการเบิก', 'IRTN'],
            ] as [$type, $name, $prefix]) {
                DocumentSequence::query()->firstOrCreate(
                    ['warehouse_id' => $warehouse->id, 'document_type' => $type],
                    [
                        'name' => $name,
                        'prefix' => $prefix,
                        'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}',
                        'reset_rule' => 'YEARLY',
                        'next_number' => 1,
                        'is_active' => true,
                        'number_reuse_policy' => 'NEVER_REUSE',
                        'created_by' => $userId,
                    ],
                );
            }
        });
    }
}
