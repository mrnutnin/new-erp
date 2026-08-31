<?php

namespace App\Modules\Wms\Services;

use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\InventoryJournalLineMapper;
use Illuminate\Validation\ValidationException;

/**
 * Small orchestration skeleton for the future Purchase Inventory transaction.
 *
 * Collaborators are callbacks so the boundary can be contract-tested without
 * touching the database. Production adapters must call the existing Journal,
 * Stock Movement and Cost Allocation services inside one transaction runner.
 * The caller must explicitly open the feature gate; this class has no route.
 */
final class InventoryPurchaseAtomicWorkflow
{
    /**
     * Resolve and persist the immutable allocation→Journal line proof while
     * already inside the caller's outer transaction.
     */
    public function linkAllocationJournalLineWithinTransaction(
        InventoryCostAllocationService $allocations,
        mixed $purchaseLine,
        StockMovement $movement,
        CostAllocation $allocation,
        iterable $journalLines,
        array $context = [],
    ): array {
        $proof = InventoryJournalLineMapper::map($purchaseLine, $movement, $allocation, $journalLines, $context);
        $line = collect($journalLines)->firstWhere('id', $proof['journal_entry_line_id']);
        if (! $line instanceof JournalEntryLine) {
            throw ValidationException::withMessages(['journal_line' => 'Journal line linkage ต้องใช้ JournalEntryLine model ที่ lock ได้']);
        }

        return [
            ...$proof,
            'linkage' => $allocations->linkJournalLineWithinTransaction($allocation, $line),
        ];
    }

    public function execute(
        array $preflight,
        bool $featureEnabled,
        callable $transaction,
        callable $journal,
        callable $movement,
        callable $allocation,
        callable $linkage,
    ): array {
        if (! $featureEnabled) {
            throw ValidationException::withMessages(['posting' => 'Inventory Purchase atomic posting ยังไม่เปิดใช้งาน']);
        }
        if (($preflight['ready'] ?? false) !== true || ($preflight['creates_journal'] ?? true) !== true) {
            throw ValidationException::withMessages(['posting' => 'Preflight ยังไม่พร้อมสำหรับ atomic Inventory Purchase']);
        }

        $plan = $preflight['plan'] ?? [];
        $this->assertLockOrder($plan['lock_order'] ?? []);

        return $transaction(function () use ($journal, $movement, $allocation, $linkage, $preflight): array {
            $journalResult = $journal($preflight['payload'] ?? []);
            $movementResult = $movement($preflight['payload'] ?? [], $journalResult);
            $allocationResult = $allocation($movementResult, $journalResult);
            $linkageResult = $linkage($allocationResult, $journalResult, $movementResult);

            foreach ([
                'journal_id' => $this->idOf($journalResult),
                'movement_id' => $this->idOf($movementResult),
                'allocation_id' => $this->idOf($allocationResult),
                'linkage_id' => $this->idOf($linkageResult),
            ] as $field => $id) {
                if ($id === null || $id === '') {
                    throw ValidationException::withMessages([$field => 'Atomic Inventory linkage ต้องมี ID ครบทุกขั้นตอน']);
                }
            }

            return [
                'journal_id' => $this->idOf($journalResult),
                'movement_id' => $this->idOf($movementResult),
                'allocation_id' => $this->idOf($allocationResult),
                'linkage_id' => $this->idOf($linkageResult),
            ];
        });
    }

    private function assertLockOrder(array $lockOrder): void
    {
        $required = ['purchase_document', 'journal_book', 'fiscal_period', 'stock_movement', 'cost_allocations', 'cost_layers', 'stock_balance'];
        if ($lockOrder !== $required) {
            throw ValidationException::withMessages(['lock_order' => 'Atomic Inventory lock order ไม่ตรงกับ contract']);
        }
    }

    private function idOf(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value['id'] ?? $value['linkage_id'] ?? null;
        }

        return is_object($value) ? ($value->id ?? null) : $value;
    }
}
