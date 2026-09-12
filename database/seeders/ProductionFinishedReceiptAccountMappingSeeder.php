<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use Illuminate\Database\Seeder;

/** Installs the standard WMS production receipt accounting contract. */
final class ProductionFinishedReceiptAccountMappingSeeder extends Seeder
{
    private const EVENT = 'production.finished_receipt';

    /** @var array<string, string> */
    private const MAPPINGS = [
        'FINISHED_GOODS' => '14500',
        'WIP' => '13500',
        'PRODUCTION_VARIANCE' => '52600',
    ];

    public function run(): void
    {
        foreach (self::MAPPINGS as $key => $accountCode) {
            $accountId = Account::query()
                ->where('code', $accountCode)
                ->where('is_active', true)
                ->where('is_postable', true)
                ->value('id');

            if (! $accountId) {
                continue;
            }

            AccountMapping::query()->updateOrCreate(
                ['event_code' => self::EVENT, 'key' => $key],
                ['account_id' => $accountId, 'is_active' => true, 'version' => 1],
            );
        }
    }
}
