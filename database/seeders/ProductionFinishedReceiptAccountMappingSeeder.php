<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use Illuminate\Database\Seeder;

/** Installs the standard shared WMS production receipt accounting contracts. */
final class ProductionFinishedReceiptAccountMappingSeeder extends Seeder
{
    /** @var array<string, array<string, string>> */
    private const MAPPINGS = [
        'production.finished_receipt' => [
            'FINISHED_GOODS' => '14500',
            'WIP' => '13500',
            'PRODUCTION_VARIANCE' => '52600',
        ],
        'production.scrap_receipt' => [
            'SCRAP_INVENTORY' => '14500',
            'WIP' => '13500',
        ],
    ];

    public function run(): void
    {
        foreach (self::MAPPINGS as $event => $mappings) {
            foreach ($mappings as $key => $accountCode) {
                $accountId = Account::query()
                    ->where('code', $accountCode)
                    ->where('is_active', true)
                    ->where('is_postable', true)
                    ->value('id');

                if (! $accountId) {
                    continue;
                }

                AccountMapping::query()->updateOrCreate(
                    ['event_code' => $event, 'key' => $key],
                    ['account_id' => $accountId, 'is_active' => true, 'version' => 1],
                );
            }
        }
    }
}
