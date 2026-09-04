<?php

namespace App\Modules\Purchasing\Support;

final class PurchaseThreeWayMatchPolicy
{
    public function __construct(
        public readonly string $quantityTolerance = '0.00000001',
        public readonly string $priceTolerance = '0.01',
        public readonly bool $requireApprovalOnVariance = true,
        public readonly bool $blockOnVariance = true,
    ) {}

    public function varianceState(array $blockers): string
    {
        $variance = array_intersect($blockers, [
            'receipt_exceeds_po_quantity', 'invoice_exceeds_received_quantity',
            'invoice_price_variance', 'receipt_cost_variance',
        ]);

        if ($variance === []) {
            return 'CLEAR';
        }
        if ($this->blockOnVariance) {
            return 'BLOCKED';
        }
        if ($this->requireApprovalOnVariance) {
            return 'APPROVAL_REQUIRED';
        }

        return 'REVIEW_REQUIRED';
    }
}
