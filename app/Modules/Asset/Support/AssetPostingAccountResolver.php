<?php

namespace App\Modules\Asset\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountMappingService;

/** Resolves an Asset master override, then falls back to the event mapping. */
final class AssetPostingAccountResolver
{
    public function __construct(private readonly AccountMappingService $mappings) {}

    /** @return array{account: Account, provenance: array<string, int|string|null>} */
    public function resolve(string $eventCode, string $role, ?int $accountId = null, ?string $sourceType = null, ?string $sourceId = null, string $source = 'MASTER', bool $lockForUpdate = true): array
    {
        if (! $accountId) {
            return $this->mappings->resolveForEvent($eventCode, $role);
        }

        $this->mappings->assertEventRole($eventCode, $role);
        $account = Account::query()->with('type')->whereKey($accountId)->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $this->mappings->assertCompatible($role, $account);

        return [
            'account' => $account,
            'provenance' => [
                'event_code' => $eventCode,
                'account_role' => $role,
                'account_id' => $account->id,
                'source' => $source,
                'source_type' => $sourceType ?? 'ASSET_CATEGORY',
                'source_id' => $sourceId,
                'mapping_id' => null,
                'mapping_version' => null,
            ],
        ];
    }
}
