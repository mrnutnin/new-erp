<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\StockMovement;

/** Resolves whether a pending allocation is expected to have GL proof. */
final class CostAccountingProofPolicy
{
    /** @var array<int, bool> */
    private array $issueReturnProofCache = [];

    public function requiresJournalProof(CostAllocation $allocation): bool
    {
        if ($allocation->journal_entry_id !== null) {
            return true;
        }

        $movement = $allocation->relationLoaded('movement') ? $allocation->movement : $allocation->movement()->first();
        if (! $movement || strtoupper((string) $movement->source_type) !== 'ISSUE_RETURN') {
            return true;
        }

        return ! $this->isReversedIssueReturn($movement);
    }

    public function reason(CostAllocation $allocation): ?string
    {
        return $this->requiresJournalProof($allocation) ? null : 'NO_GL_REVERSAL_PAIR';
    }

    private function isReversedIssueReturn(StockMovement $movement): bool
    {
        $movementId = (int) ($movement->id ?? 0);
        if (isset($this->issueReturnProofCache[$movementId])) {
            return $this->issueReturnProofCache[$movementId];
        }

        try {
            $sourceMovementId = (int) data_get($movement->metadata, 'reversal_of_movement_id');
            if ($sourceMovementId > 0) {
                $sourceMovement = StockMovement::query()->find($sourceMovementId, ['id', 'source_type', 'source_id']);
                if ($sourceMovement && strtoupper((string) $sourceMovement->source_type) === 'ISSUE_RETURN') {
                    $movement = $sourceMovement;
                }
            }

            $returnId = (int) $movement->source_id;
            $status = $returnId > 0 ? IssueReturn::query()->whereKey($returnId)->value('status') : null;
        } catch (\Throwable) {
            // A missing DB context or source record must remain conservative.
            return $this->issueReturnProofCache[$movementId] = false;
        }

        return $this->issueReturnProofCache[$movementId] = $status === 'REVERSED';
    }
}
