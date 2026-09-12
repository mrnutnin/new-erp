<?php

namespace App\Modules\Wms\Services;

use App\Models\Warehouse;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\CostImpact;
use App\Modules\Wms\Support\CostImpactBucket;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class CostImpactClassifier
{
    private readonly CostAccountingProofPolicy $proofPolicy;

    public function __construct(?CostAccountingProofPolicy $proofPolicy = null)
    {
        $this->proofPolicy = $proofPolicy ?? new CostAccountingProofPolicy;
    }

    public function classify(
        CostAllocation $allocation,
        string $deltaValue,
        ?int $branchId = null,
        string $relation = 'TIMELINE',
    ): CostImpact {
        $movement = $allocation->relationLoaded('movement')
            ? $allocation->movement
            : $allocation->movement()->first();
        $blockers = [];
        $warnings = [];

        if (! $movement) {
            $blockers[] = 'MOVEMENT_MISSING';
        }

        $metadata = is_array($movement?->metadata) ? $movement->metadata : [];
        $nonReturn = strtoupper((string) ($metadata['credit_note_mode'] ?? '')) === 'NON_RETURN'
            || strtoupper((string) ($metadata['purchase_return_mode'] ?? '')) === 'NON_RETURN';
        if ($nonReturn) {
            $blockers[] = 'NON_RETURN_STOCK_EVENT_FORBIDDEN';
        }
        $reversal = isset($metadata['reversal_of_movement_id']);
        $bucket = $movement && ! $nonReturn ? $this->bucket($movement, $metadata, $reversal, $blockers) : null;
        $branchId ??= $this->branchId($movement, (int) $allocation->warehouse_id);

        if ($bucket === null && ! $nonReturn && ! in_array('REVERSAL_SOURCE_MISSING', $blockers, true)) {
            $blockers[] = 'UNSUPPORTED_IMPACT_EVENT';
        }
        if ($branchId === null) {
            $blockers[] = 'SCOPE_BRANCH_MISSING';
        }
        if ($movement && $movement->status !== 'POSTED') {
            $blockers[] = 'MOVEMENT_NOT_POSTED';
        }
        if ($allocation->cost_status === 'PENDING') {
            $blockers[] = 'PENDING_COST';
        }
        // Warehouse transfers are an explicit NO_GL bridge. Legacy transfer
        // allocations may be PENDING solely because no Journal is expected.
        $noJournalProofRequired = ! $this->proofPolicy->requiresJournalProof($allocation);
        $legacyJournalSource = in_array(strtoupper((string) $movement?->source_type), ['ISSUE_DOCUMENT', 'ISSUE_RETURN', 'POS', 'PURCHASING'], true);
        if ($legacyJournalSource && $bucket !== CostImpactBucket::TransferBridge && ! $noJournalProofRequired
            && ($allocation->status === 'PENDING' || $allocation->journal_entry_id === null)) {
            $warnings[] = 'ACCOUNTING_PROOF_PENDING';
        } elseif ($allocation->status !== 'POSTED' && ! ($allocation->status === 'PENDING' && ($bucket === CostImpactBucket::TransferBridge || $noJournalProofRequired))) {
            $blockers[] = 'ALLOCATION_NOT_POSTED';
        }

        return new CostImpact(
            allocationId: (int) $allocation->id,
            movementId: (int) ($movement?->id ?? 0),
            bucket: $bucket,
            targetEvent: $bucket ? $this->targetEvent($bucket, strtoupper((string) $movement?->source_type), $metadata) : null,
            warehouseId: (int) $allocation->warehouse_id,
            branchId: $branchId,
            itemId: (int) $allocation->item_id,
            uomId: (int) $allocation->uom_id,
            businessDate: $movement?->business_date?->format('Y-m-d') ?: $allocation->business_date?->format('Y-m-d'),
            direction: (string) ($movement?->direction ?: $allocation->direction),
            quantity: $this->decimal((string) $allocation->quantity),
            deltaValue: $this->decimal($deltaValue),
            reversal: $reversal,
            evidence: [
                'relation' => $relation,
                'source_type' => $movement?->source_type,
                'source_id' => $movement?->source_id,
                'source_reference' => $movement?->source_reference,
                'movement_type' => $movement?->movement_type,
                'issue_type' => $metadata['issue_type'] ?? null,
                'transfer_event' => $metadata['transfer_event'] ?? null,
                'document_context' => $metadata['document_context'] ?? null,
                'credit_note_mode' => $metadata['credit_note_mode'] ?? null,
                'purchase_return_mode' => $metadata['purchase_return_mode'] ?? null,
                'reversal_of_movement_id' => $metadata['reversal_of_movement_id'] ?? null,
                'allocation_status' => (string) $allocation->status,
                'accounting_proof_policy' => $this->proofPolicy->reason($allocation),
            ],
            warnings: array_values(array_unique($warnings)),
            blockers: array_values(array_unique($blockers)),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $blockers
     */
    private function bucket(StockMovement $movement, array $metadata, bool $reversal, array &$blockers): ?CostImpactBucket
    {
        $sourceType = strtoupper((string) $movement->source_type);
        $direction = strtoupper((string) $movement->direction);

        if ($reversal) {
            return $this->reversalBucket($movement, $sourceType, $blockers);
        }

        return match ($sourceType) {
            'OPENING_BALANCE' => $direction === 'IN' ? CostImpactBucket::InventoryOnHand : null,
            'GOODS_RECEIPT' => $direction === 'IN' ? CostImpactBucket::InventoryOnHand : CostImpactBucket::PurchaseReturnConsumed,
            'PURCHASING' => $direction === 'IN' ? CostImpactBucket::InventoryOnHand : CostImpactBucket::PurchaseReturnConsumed,
            'INVENTORY' => ($metadata['document_context'] ?? null) === 'PRODUCTION_RECEIPT'
                ? CostImpactBucket::FinishedGoodsBridge
                : ($direction === 'IN' ? CostImpactBucket::InventoryOnHand : CostImpactBucket::IssueExpenseConsumed),
            'ISSUE_DOCUMENT' => strtoupper((string) ($metadata['issue_type'] ?? '')) === 'PRODUCTION'
                ? CostImpactBucket::WipConsumed
                : CostImpactBucket::IssueExpenseConsumed,
            'ISSUE_RETURN' => CostImpactBucket::ReturnBridge,
            'WMS_PRODUCTION_RECEIPT' => CostImpactBucket::FinishedGoodsBridge,
            'WMS_TRANSFER' => CostImpactBucket::TransferBridge,
            'POS' => $direction === 'IN' ? CostImpactBucket::ReturnBridge : CostImpactBucket::CogsConsumed,
            'RECOST' => CostImpactBucket::InventoryOnHand,
            default => null,
        };
    }

    /** @param list<string> $blockers */
    private function reversalBucket(StockMovement $movement, string $sourceType, array &$blockers): ?CostImpactBucket
    {
        if (in_array($sourceType, ['POS', 'ISSUE_DOCUMENT', 'ISSUE_RETURN'], true)) {
            return CostImpactBucket::ReturnBridge;
        }
        if ($sourceType === 'PURCHASING' || $sourceType === 'GOODS_RECEIPT') {
            return CostImpactBucket::PurchaseReturnConsumed;
        }
        if ($sourceType === 'WMS_TRANSFER') {
            return CostImpactBucket::TransferBridge;
        }
        if ($sourceType === 'WMS_PRODUCTION_RECEIPT') {
            return CostImpactBucket::FinishedGoodsBridge;
        }
        if ($sourceType === 'OPENING_BALANCE' || $sourceType === 'RECOST') {
            return CostImpactBucket::InventoryOnHand;
        }
        if ($sourceType !== 'INVENTORY') {
            return null;
        }

        $sourceId = (int) data_get($movement->metadata, 'reversal_of_movement_id');
        $source = $sourceId > 0 ? StockMovement::query()->find($sourceId) : null;
        if (! $source) {
            $blockers[] = 'REVERSAL_SOURCE_MISSING';

            return null;
        }

        return $source->direction === 'IN'
            ? CostImpactBucket::InventoryOnHand
            : CostImpactBucket::IssueExpenseConsumed;
    }

    private function branchId(?StockMovement $movement, int $warehouseId): ?int
    {
        if ($movement?->relationLoaded('warehouse')) {
            return $movement->warehouse?->branch_id ? (int) $movement->warehouse->branch_id : null;
        }

        $value = Warehouse::query()->whereKey($warehouseId)->value('branch_id');

        return $value ? (int) $value : null;
    }

    /** @param array<string, mixed> $metadata */
    private function targetEvent(CostImpactBucket $bucket, string $sourceType, array $metadata): ?string
    {
        if ($bucket !== CostImpactBucket::ReturnBridge) {
            return $bucket->targetEvent();
        }

        return match ($sourceType) {
            'POS' => 'inventory.revaluation.sales_return',
            'ISSUE_RETURN' => strtoupper((string) ($metadata['issue_type'] ?? '')) === 'PRODUCTION'
                ? 'production.revaluation.material_return'
                : 'inventory.revaluation.issue_return',
            default => 'inventory.revaluation.issue_return',
        };
    }

    private function decimal(string $value): string
    {
        return BigDecimal::of($value)->toScale(8, RoundingMode::HALF_UP)->__toString();
    }
}
