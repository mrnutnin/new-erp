<?php

namespace App\Modules\Wms\Support;

/**
 * Read-only boundary for the persisted PO/document/receipt allocation shape.
 * It deliberately does not query, mutate, post Stock/Cost/GL, or approve variance.
 */
final class PurchaseThreeWayMatchService
{
    public function evaluate(
        array $purchaseOrder,
        array $receipts,
        array $purchaseDocument,
        ?PurchaseThreeWayMatchPolicy $policy = null,
    ): array {
        $policy ??= new PurchaseThreeWayMatchPolicy;
        $linkageBlockers = $this->validateLineLinkage($purchaseOrder, $receipts, $purchaseDocument);
        $result = PurchaseThreeWayMatchContract::evaluate(
            $purchaseOrder,
            $receipts,
            $purchaseDocument,
            $policy->quantityTolerance,
            $policy->priceTolerance,
        );
        $blockers = array_values(array_unique([...$linkageBlockers, ...$result['blockers']]));
        $varianceState = $policy->varianceState($blockers);

        return [
            ...$result,
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'variance_state' => $varianceState,
            'variance_requires_approval' => $varianceState === 'APPROVAL_REQUIRED',
            'variance_policy' => [
                'quantity_tolerance' => $policy->quantityTolerance,
                'price_tolerance' => $policy->priceTolerance,
                'require_approval_on_variance' => $policy->requireApprovalOnVariance,
                'block_on_variance' => $policy->blockOnVariance,
            ],
        ];
    }

    private function validateLineLinkage(array $purchaseOrder, array $receipts, array $purchaseDocument): array
    {
        $poLines = [];
        foreach ($purchaseOrder['lines'] ?? [] as $line) {
            $poLines[(int) ($line['id'] ?? 0)] = $line;
        }
        $receiptLines = [];
        foreach ($receipts as $receipt) {
            foreach ($receipt['lines'] ?? [] as $line) {
                $receiptLines[(int) ($line['id'] ?? 0)] = $line;
            }
        }
        $blockers = [];
        foreach ($purchaseDocument['lines'] ?? [] as $line) {
            $lineId = (int) ($line['id'] ?? 0);
            $poLineId = (int) ($line['purchase_order_line_id'] ?? 0);
            $isInventory = (int) ($line['item_id'] ?? 0) > 0;
            $allocations = $line['receipt_allocations'] ?? [];

            if (! $isInventory) {
                if ($poLineId > 0 || $allocations !== []) {
                    $blockers[] = 'expense_line_must_not_link_inventory';
                }

                continue;
            }
            if ($lineId < 1 || $poLineId < 1 || ! isset($poLines[$poLineId])) {
                $blockers[] = 'inventory_line_po_linkage_required';

                continue;
            }
            if ($allocations === []) {
                $blockers[] = 'inventory_line_receipt_allocation_required';

                continue;
            }
            $seen = [];
            foreach ($allocations as $allocation) {
                $receiptLineId = (int) ($allocation['goods_receipt_line_id'] ?? 0);
                if ($receiptLineId < 1 || isset($seen[$receiptLineId]) || ! isset($receiptLines[$receiptLineId])) {
                    $blockers[] = 'receipt_allocation_identity_required';

                    continue;
                }
                $seen[$receiptLineId] = true;
                if ((int) ($receiptLines[$receiptLineId]['purchase_order_line_id'] ?? 0) !== $poLineId) {
                    $blockers[] = 'receipt_allocation_po_line_mismatch';
                }
                if (! is_numeric($allocation['allocated_quantity'] ?? null)
                    || (float) $allocation['allocated_quantity'] <= 0) {
                    $blockers[] = 'invalid_receipt_allocation_quantity';
                }
            }
        }

        return array_values(array_unique($blockers));
    }
}
