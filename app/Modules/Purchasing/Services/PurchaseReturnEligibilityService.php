<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseReturnLine;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

final class PurchaseReturnEligibilityService
{
    private const ACTIVE_STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED'];

    public function remainingForLine(GoodsReceiptLine $receiptLine, ?int $excludeReturnId = null): BigDecimal
    {
        $receipt = $receiptLine->relationLoaded('goodsReceipt')
            ? $receiptLine->goodsReceipt
            : $receiptLine->goodsReceipt()->first();
        if (! $receipt || $receipt->status !== 'APPROVED') {
            throw ValidationException::withMessages(['goods_receipt_line_id' => 'คืนได้เฉพาะรายการจาก Goods Receipt ที่อนุมัติแล้ว']);
        }

        $returned = PurchaseReturnLine::query()
            ->where('goods_receipt_line_id', $receiptLine->id)
            ->whereHas('purchaseReturn', function ($query) use ($excludeReturnId): void {
                $query->whereIn('status', self::ACTIVE_STATUSES)
                    ->when($excludeReturnId, fn ($query) => $query->where('id', '!=', $excludeReturnId));
            })
            ->sum('purchase_quantity');

        return $this->remainingQuantity((string) $receiptLine->purchase_quantity, (string) $returned);
    }

    public function assertLineQuantityAllowed(GoodsReceiptLine $receiptLine, string $requestedQuantity, ?int $excludeReturnId = null): void
    {
        $remaining = $this->remainingForLine($receiptLine, $excludeReturnId);
        $this->assertQuantityAllowed($remaining->__toString(), $requestedQuantity);
    }

    public function assertQuantityAllowed(string $remainingQuantity, string $requestedQuantity): void
    {
        $remaining = BigDecimal::of($remainingQuantity);
        $requested = BigDecimal::of($requestedQuantity);
        if ($requested->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['purchase_quantity' => 'จำนวนคืนต้องมากกว่า 0']);
        }
        if ($requested->isGreaterThan($remaining)) {
            throw ValidationException::withMessages(['purchase_quantity' => 'จำนวนคืนเกินยอดที่รับแล้วหรือถูกคืนไปแล้ว']);
        }
    }

    public function remainingQuantity(string $receivedQuantity, string $returnedQuantity): BigDecimal
    {
        $received = BigDecimal::of($receivedQuantity);
        $returned = BigDecimal::of($returnedQuantity);
        if ($received->isNegative() || $returned->isNegative() || $returned->isGreaterThan($received)) {
            throw ValidationException::withMessages(['purchase_quantity' => 'ยอดรับและยอดคืนไม่ถูกต้อง']);
        }

        return $received->minus($returned)->toScale(8);
    }
}
