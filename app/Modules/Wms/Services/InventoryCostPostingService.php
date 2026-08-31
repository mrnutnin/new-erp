<?php

namespace App\Modules\Wms\Services;

use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Support\PostingIdentity;
use App\Modules\Wms\Models\CostAllocation;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Builds the deterministic Inventory -> GL payload without writing a journal.
 *
 * This is deliberately a preview boundary: source-side accounts, reconciliation
 * and the outer JournalPostingService transaction must be complete before this
 * service is allowed to create a JournalEntry.
 */
final class InventoryCostPostingService
{
    public function __construct(private readonly InventoryCostPostingContract $contract = new InventoryCostPostingContract) {}

    /**
     * Resolve mappings for explicit allocations and return a stable dry-run.
     * Allocation IDs are caller supplied; this method never scans a warehouse.
     */
    public function dryRun(Collection|array $allocations, string $eventCode, AccountMappingService $mappings): array
    {
        $allocations = Collection::make($allocations)->values();
        if ($allocations->isEmpty()) {
            throw ValidationException::withMessages(['allocations' => 'ต้องระบุ cost allocation อย่างน้อยหนึ่งรายการ']);
        }

        $resolved = [];
        foreach ($allocations as $allocation) {
            if (! $allocation instanceof CostAllocation) {
                throw ValidationException::withMessages(['allocations' => 'รายการ allocation ไม่ถูกต้อง']);
            }
            $resolved[] = $this->contract->dryRun($allocation, $eventCode, $mappings);
        }

        $accounts = [];
        foreach ($resolved as $result) {
            foreach ($result['accounts'] as $key => $accountId) {
                $accounts[$key] = (int) $accountId;
            }
        }

        $preview = $this->buildPreview($allocations, $eventCode, $accounts);

        return [
            'event_code' => strtolower(trim($eventCode)),
            'creates_journal' => false,
            'allocation_ids' => $allocations->map(fn (CostAllocation $allocation): int => (int) $allocation->id)->all(),
            'accounts' => $accounts,
            'lines' => $preview['lines'],
            'posting_hash' => PostingIdentity::fingerprint([
                'event_code' => strtolower(trim($eventCode)),
                'allocation_ids' => $preview['allocation_ids'],
                'lines' => $preview['lines'],
            ]),
        ];
    }

    /**
     * Pure payload builder used by tests and by the future posting adapter.
     * Account IDs are already resolved and are never guessed here.
     */
    public function buildPreview(Collection|array|CostAllocation $allocations, string $eventCode, array $accounts): array
    {
        $eventCode = strtolower(trim($eventCode));
        if ($allocations instanceof CostAllocation) {
            $allocations = [$allocations];
        }
        $allocations = Collection::make($allocations)->values()->sortBy(fn (CostAllocation $allocation): int => (int) $allocation->id)->values();
        $allocationIds = $allocations->map(fn (CostAllocation $allocation): int => (int) $allocation->id);
        if ($allocationIds->contains(0) || $allocationIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['allocations' => 'allocation ต้องมีรหัสไม่ซ้ำกันและต้องถูกบันทึกแล้ว']);
        }
        $lines = [];

        foreach ($allocations as $allocation) {
            if (! $allocation instanceof CostAllocation) {
                throw ValidationException::withMessages(['allocations' => 'รายการ allocation ไม่ถูกต้อง']);
            }
            $value = BigDecimal::of((string) $allocation->value)->toScale(2)->__toString();
            if (BigDecimal::of($value)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['value' => 'มูลค่า allocation ต้องมากกว่าศูนย์']);
            }
            $this->assertAccounts($eventCode, $allocation, $accounts);

            $add = function (string $mapping, string $side) use (&$lines, $allocation, $value, $accounts): void {
                $lines[] = [
                    'allocation_id' => (int) $allocation->id,
                    'account_mapping' => $mapping,
                    'account_id' => (int) $accounts[$mapping],
                    'side' => $side,
                    'amount' => $value,
                ];
            };

            match ($eventCode) {
                'sales_cogs' => ($add('COGS_DEFAULT', 'DEBIT') || $add('INVENTORY_DEFAULT', 'CREDIT')),
                'inventory.adjustment' => $allocation->direction === 'IN'
                    ? ($add('INVENTORY_DEFAULT', 'DEBIT') || $add('INVENTORY_ADJUSTMENT_GAIN', 'CREDIT'))
                    : ($add('INVENTORY_ADJUSTMENT_LOSS', 'DEBIT') || $add('INVENTORY_DEFAULT', 'CREDIT')),
                'inventory.recost' => throw ValidationException::withMessages(['event_code' => 'ยังไม่เปิดใช้การ Post recost จนกว่าจะมี mapping revaluation และ reversal contract']),
                'inventory.receipt' => throw ValidationException::withMessages(['event_code' => 'Receipt ต้องมี source account จากเอกสารต้นทางก่อนจึงสร้าง Journal ได้']),
                default => throw ValidationException::withMessages(['event_code' => 'ไม่รองรับ Inventory event นี้']),
            };
        }

        return [
            'allocation_ids' => $allocations->map(fn (CostAllocation $allocation): int => (int) $allocation->id)->all(),
            'lines' => $lines,
        ];
    }

    private function assertAccounts(string $eventCode, CostAllocation $allocation, array $accounts): void
    {
        $required = match ($eventCode) {
            'sales_cogs' => ['COGS_DEFAULT', 'INVENTORY_DEFAULT'],
            'inventory.adjustment' => $allocation->direction === 'IN'
                ? ['INVENTORY_DEFAULT', 'INVENTORY_ADJUSTMENT_GAIN']
                : ['INVENTORY_ADJUSTMENT_LOSS', 'INVENTORY_DEFAULT'],
            'inventory.recost' => ['INVENTORY_REVALUATION'],
            'inventory.receipt' => ['INVENTORY_DEFAULT'],
            default => [],
        };

        foreach ($required as $key) {
            if (! array_key_exists($key, $accounts) || (int) $accounts[$key] < 1) {
                throw ValidationException::withMessages(['account_mapping' => "ยังไม่ได้ resolve {$key}"]);
            }
        }
    }
}
