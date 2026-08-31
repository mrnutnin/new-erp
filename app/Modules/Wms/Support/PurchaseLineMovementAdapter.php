<?php

namespace App\Modules\Wms\Support;

use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\UomConversion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Maps a posted Inventory Purchase line to one deterministic receipt intent.
 * Conversion is deliberately conservative until a resolved conversion factor
 * is supplied by the document contract; silently assuming a factor is unsafe.
 */
final class PurchaseLineMovementAdapter
{
    public static function map(PurchaseDocument $document, int $lineId): array
    {
        $line = $document->lines->firstWhere('id', $lineId);
        if (! $line || ! $line->item_id || ! $line->uom_id || ! $line->quantity) {
            throw ValidationException::withMessages(['line_id' => 'Purchase line ต้องมี Item, UOM และจำนวนก่อนสร้าง Receipt Movement']);
        }
        if (! $line->item?->is_active || ! $line->item?->is_stock_item || ! $line->uom?->is_active) {
            throw ValidationException::withMessages(['line_id' => 'Purchase line ต้องอ้าง Item/UOM active และเป็น stock item']);
        }
        if (! $document->warehouse_id || ! $document->document_date) {
            throw ValidationException::withMessages(['warehouse_id' => 'Purchase document ต้องมี Warehouse และวันที่เอกสาร']);
        }

        $quantity = BigDecimal::of((string) $line->quantity)->toScale(8, RoundingMode::UNNECESSARY)->__toString();
        $receipt = self::receiptAllocationSnapshot($line);
        $baseQuantity = $receipt['base_quantity'] ?? self::baseQuantity($line, $quantity);
        $costValue = $receipt['allocated_amount'] ?? (string) $line->gross_amount;
        $cost = InventoryPurchaseCostPolicy::resolve($costValue, $baseQuantity, (string) $document->tax_treatment);

        if ($receipt !== null) {
            $quantity = $receipt['quantity'];
        }

        return [
            'warehouse_id' => (int) $document->warehouse_id,
            'item_id' => (int) $line->item_id,
            'uom_id' => (int) $line->uom_id,
            'movement_type' => 'RECEIPT',
            'direction' => 'IN',
            'status' => 'DRAFT',
            'quantity' => $quantity,
            'base_quantity' => $baseQuantity,
            'business_date' => ($document->posting_date ?: $document->document_date)->format('Y-m-d'),
            'source_type' => 'PURCHASING',
            'source_id' => (string) $document->id,
            'source_reference' => (string) $document->document_number,
            'idempotency_key' => "purchase:{$document->id}:line:{$line->id}:receipt:0",
            'metadata' => [
                'purchase_line_id' => (int) $line->id,
                'event_code' => 'supplier_invoice.inventory',
                'unit_cost' => $cost['unit_cost'],
                'unit_cost_trusted' => true,
                'cost_value' => $cost['value'],
                'cost_policy_version' => $cost['policy_version'],
                ...($receipt === null ? [] : [
                    'receipt_allocation_ids' => $receipt['allocation_ids'],
                    'goods_receipt_line_ids' => $receipt['receipt_line_ids'],
                    'goods_receipt_ids' => $receipt['receipt_ids'],
                    'conversion_snapshots' => $receipt['conversion_snapshots'],
                    'allocated_amount' => $receipt['allocated_amount'],
                ]),
            ],
        ];
    }

    /**
     * Resolve the immutable Invoice→GR allocation snapshot when the caller
     * loaded it. The production adapter requires this relation before map()
     * is called; standalone mapping tests may still exercise UOM fallback.
     */
    private static function receiptAllocationSnapshot($line): ?array
    {
        if (! $line->relationLoaded('receiptAllocations')) {
            return null;
        }
        $allocations = $line->receiptAllocations;
        if ($allocations->isEmpty()) {
            return null;
        }

        $quantity = BigDecimal::zero();
        $baseQuantity = BigDecimal::zero();
        $allocatedAmount = BigDecimal::zero();
        $allocationIds = [];
        $receiptLineIds = [];
        $receiptIds = [];
        $snapshots = [];
        foreach ($allocations as $allocation) {
            $receiptLine = $allocation->goodsReceiptLine;
            $receipt = $receiptLine?->goodsReceipt;
            if (! $receiptLine || ! $receipt || $receipt->status !== 'APPROVED') {
                throw ValidationException::withMessages(['lines' => 'Receipt allocation ต้องอ้าง Goods Receipt ที่ Approved แล้ว']);
            }
            $allocationQty = BigDecimal::of((string) $allocation->allocated_quantity);
            $allocationAmount = BigDecimal::of((string) $allocation->allocated_amount);
            if ($allocationQty->isLessThanOrEqualTo(0) || $allocationAmount->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['lines' => 'Receipt allocation ต้องมี quantity และ amount มากกว่าศูนย์']);
            }
            $quantity = $quantity->plus($allocationQty);
            $baseQuantity = $baseQuantity->plus((string) $receiptLine->stock_quantity);
            $allocatedAmount = $allocatedAmount->plus($allocationAmount);
            $allocationIds[] = (int) $allocation->id;
            $receiptLineIds[] = (int) $receiptLine->id;
            $receiptIds[] = (int) $receipt->id;
            $snapshots[] = $receiptLine->conversion_snapshot;
        }

        return [
            'quantity' => $quantity->toScale(8, RoundingMode::HALF_UP)->__toString(),
            'base_quantity' => $baseQuantity->toScale(8, RoundingMode::HALF_UP)->__toString(),
            'allocated_amount' => $allocatedAmount->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'allocation_ids' => $allocationIds,
            'receipt_line_ids' => $receiptLineIds,
            'receipt_ids' => $receiptIds,
            'conversion_snapshots' => $snapshots,
        ];
    }

    private static function baseQuantity($line, string $quantity): string
    {
        $baseUomId = (int) $line->item->base_uom_id;
        if ($baseUomId < 1) {
            throw ValidationException::withMessages(['uom_id' => 'Item ต้องมี Base UOM ก่อนรับสินค้า']);
        }
        if ($baseUomId === (int) $line->uom_id) {
            return $quantity;
        }

        $baseUom = $line->item->relationLoaded('baseUom')
            ? $line->item->baseUom
            : $line->item->baseUom()->sharedLock()->first();
        $forward = self::conversions($line->uom, $baseUomId);
        $reverse = self::conversions($baseUom, (int) $line->uom_id);
        if (count($forward) > 1 || count($reverse) > 1 || (count($forward) === 1 && count($reverse) === 1)) {
            throw ValidationException::withMessages(['uom_id' => 'พบ UOM conversion มากกว่าหนึ่งทิศทางหรือซ้ำกัน ไม่สามารถคำนวณ Base UOM ได้']);
        }
        if (count($forward) === 1) {
            $factor = BigDecimal::of((string) $forward[0]->factor);
            if ($factor->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw ValidationException::withMessages(['uom_id' => 'UOM conversion factor ต้องมากกว่า 0']);
            }

            return BigDecimal::of($quantity)->multipliedBy($factor)->toScale(8, RoundingMode::HALF_UP)->__toString();
        }
        if (count($reverse) === 1) {
            $factor = BigDecimal::of((string) $reverse[0]->factor);
            if ($factor->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw ValidationException::withMessages(['uom_id' => 'UOM conversion factor ต้องมากกว่า 0']);
            }

            return BigDecimal::of($quantity)->dividedBy($factor, 8, RoundingMode::HALF_UP)->__toString();
        }

        throw ValidationException::withMessages(['uom_id' => 'ไม่พบ UOM conversion จากรายการไปยัง Base UOM']);
    }

    private static function conversions($uom, int $toUomId): array
    {
        if (! $uom) {
            return [];
        }
        if ($uom->relationLoaded('fromConversions')) {
            return $uom->fromConversions->filter(fn ($conversion): bool => (int) $conversion->to_uom_id === $toUomId)->values()->all();
        }

        return UomConversion::query()->where('from_uom_id', $uom->id)->where('to_uom_id', $toUomId)->sharedLock()->get()->all();
    }
}
