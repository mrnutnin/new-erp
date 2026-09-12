<?php

namespace App\Modules\Wms\Support;

use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Wms\Models\StockMovement;
use Illuminate\Validation\ValidationException;

/** Single source of truth for purchased-stock ownership. */
final class PurchaseInventoryOwnership
{
    public const OWNER = 'PURCHASING';

    public static function assertGoodsReceiptWriterDisabled(): never
    {
        throw ValidationException::withMessages([
            'stock_owner' => 'Goods Receipt เป็นหลักฐานการรับสินค้าเท่านั้น กรุณา Post Stock และต้นทุนจาก Purchase Invoice',
        ]);
    }

    /** @return array<int, int> */
    public static function legacyGoodsReceiptMovementIds(PurchaseDocument $document): array
    {
        $document->loadMissing('lines.receiptAllocations.goodsReceiptLine:id,goods_receipt_id');
        $receiptLineIds = $document->lines->flatMap->receiptAllocations
            ->pluck('goods_receipt_line_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        if ($receiptLineIds->isEmpty()) {
            return [];
        }

        $receiptIds = $document->lines->flatMap->receiptAllocations
            ->map(fn ($allocation): int => (int) $allocation->goodsReceiptLine?->goods_receipt_id)
            ->filter()->unique()->values();
        if ($receiptIds->isEmpty()) {
            return [];
        }

        return StockMovement::query()
            ->where('source_type', 'GOODS_RECEIPT')
            ->whereIn('source_id', $receiptIds->all())
            ->where('status', 'POSTED')
            ->get(['id', 'metadata'])
            ->filter(fn (StockMovement $movement): bool => $receiptLineIds->contains((int) (($movement->metadata ?? [])['goods_receipt_line_id'] ?? 0)))
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }
}
