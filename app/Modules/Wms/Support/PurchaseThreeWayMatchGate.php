<?php

namespace App\Modules\Wms\Support;

use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\PurchaseVarianceApproval;
use Illuminate\Validation\ValidationException;

/** Read-only approval/posting gate; it never creates inventory or journals. */
final class PurchaseThreeWayMatchGate
{
    public function assertReady(PurchaseDocument $document): void
    {
        $result = $this->preview($document);
        if ($result === null || $result['ready']) {
            return;
        }

        // A variance may proceed only when the current PO/GR/Invoice snapshot
        // has an explicit approval. A changed source produces a new hash and
        // therefore must be reviewed again.
        $approvalPolicy = new PurchaseThreeWayMatchPolicy(requireApprovalOnVariance: true, blockOnVariance: false);
        $approvalResult = $this->previewWithPolicy($document, $approvalPolicy);
        $approved = $approvalResult !== null
            && PurchaseVarianceApproval::query()
                ->where('purchase_document_id', $document->id)
                ->where('status', 'APPROVED')
                ->where('evidence_hash', PurchaseVarianceApproval::evidenceHash($approvalResult, $approvalPolicy))
                ->exists();
        if ($approved) {
            return;
        }

        throw ValidationException::withMessages([
            'lines' => 'Purchase Invoice สินค้าคงคลังยังไม่ผ่าน 3-way matching: '.implode(', ', $result['blockers']),
        ]);
    }

    public function preview(PurchaseDocument $document): ?array
    {
        return $this->previewWithPolicy($document, new PurchaseThreeWayMatchPolicy);
    }

    public function previewWithPolicy(PurchaseDocument $document, PurchaseThreeWayMatchPolicy $policy): ?array
    {
        $document->loadMissing([
            'lines.purchaseOrderLine.purchaseOrder.lines',
            'lines.receiptAllocations.goodsReceiptLine.goodsReceipt.lines',
        ]);
        if (! $document->lines->contains(fn ($line): bool => (int) $line->item_id > 0)) {
            return null;
        }

        $po = $document->lines->first(fn ($line): bool => $line->purchaseOrderLine?->purchaseOrder !== null)?->purchaseOrderLine?->purchaseOrder;
        $receipts = $document->lines->flatMap(fn ($line) => $line->receiptAllocations->map(fn ($allocation) => $allocation->goodsReceiptLine?->goodsReceipt))
            ->filter()->unique('id')->values();
        $result = (new PurchaseThreeWayMatchService)->evaluate(
            $po ? $this->purchaseOrderSnapshot($po) : [],
            $receipts->map(fn ($receipt) => $this->receiptSnapshot($receipt))->all(),
            $this->documentSnapshot($document),
            $policy,
        );

        return $result;
    }

    private function purchaseOrderSnapshot($po): array
    {
        return [
            'id' => $po->id, 'warehouse_id' => $po->warehouse_id, 'supplier_id' => $po->supplier_id, 'status' => $po->status,
            'lines' => $po->lines->map(fn ($line) => $line->only(['id', 'item_id', 'uom_id', 'quantity', 'unit_price']))->all(),
        ];
    }

    private function receiptSnapshot($receipt): array
    {
        return [
            'id' => $receipt->id, 'purchase_order_id' => $receipt->purchase_order_id, 'warehouse_id' => $receipt->warehouse_id,
            'supplier_id' => $receipt->supplier_id, 'status' => $receipt->status,
            'lines' => $receipt->lines->map(fn ($line) => $line->only(['id', 'purchase_order_line_id', 'item_id', 'purchase_uom_id', 'purchase_quantity', 'total_cost']))->all(),
        ];
    }

    private function documentSnapshot(PurchaseDocument $document): array
    {
        return [
            'id' => $document->id, 'warehouse_id' => $document->warehouse_id, 'supplier_id' => $document->supplier_id,
            'document_type' => $document->document_type, 'status' => 'APPROVED',
            'lines' => $document->lines->map(fn ($line) => [
                'id' => $line->id, 'purchase_order_line_id' => $line->purchase_order_line_id, 'item_id' => $line->item_id,
                'uom_id' => $line->uom_id, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price,
                'receipt_allocations' => $line->receiptAllocations->map(fn ($allocation) => $allocation->only(['goods_receipt_line_id', 'allocated_quantity', 'allocated_amount']))->all(),
            ])->all(),
        ];
    }
}
