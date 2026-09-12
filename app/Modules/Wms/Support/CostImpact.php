<?php

namespace App\Modules\Wms\Support;

final readonly class CostImpact
{
    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $warnings
     * @param  list<string>  $blockers
     */
    public function __construct(
        public int $allocationId,
        public int $movementId,
        public ?CostImpactBucket $bucket,
        public ?string $targetEvent,
        public int $warehouseId,
        public ?int $branchId,
        public int $itemId,
        public int $uomId,
        public ?string $businessDate,
        public string $direction,
        public string $quantity,
        public string $deltaValue,
        public bool $reversal,
        public array $evidence,
        public array $warnings,
        public array $blockers,
    ) {}

    public function ready(): bool
    {
        return $this->blockers === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allocation_id' => $this->allocationId,
            'movement_id' => $this->movementId,
            'impact_bucket' => $this->bucket?->value,
            'target_event' => $this->targetEvent,
            'warehouse_id' => $this->warehouseId,
            'branch_id' => $this->branchId,
            'item_id' => $this->itemId,
            'uom_id' => $this->uomId,
            'business_date' => $this->businessDate,
            'direction' => $this->direction,
            'quantity' => $this->quantity,
            'delta_value' => $this->deltaValue,
            'reversal' => $this->reversal,
            'evidence' => $this->evidence,
            'warnings' => $this->warnings,
            'blockers' => $this->blockers,
            'ready' => $this->ready(),
        ];
    }
}
