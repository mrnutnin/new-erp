<?php

namespace App\Modules\Purchasing\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class PurchaseReturnPartialPostingContract
{
    public static function plan(array $source): array
    {
        foreach (['received_purchase_quantity', 'returned_purchase_quantity', 'factor', 'stock_unit_cost'] as $field) {
            if (BigDecimal::of((string) ($source[$field] ?? '0'))->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages([$field => 'Partial Return source quantity/cost ต้องมากกว่า 0']);
            }
        }
        $received = BigDecimal::of((string) $source['received_purchase_quantity']);
        $returned = BigDecimal::of((string) $source['returned_purchase_quantity']);
        if ($returned->isGreaterThan($received)) {
            throw ValidationException::withMessages(['returned_purchase_quantity' => 'จำนวนคืนห้ามเกินจำนวนรับจริง']);
        }
        $stockQuantity = $returned->multipliedBy((string) $source['factor'])->toScale(8, RoundingMode::HALF_UP);
        $totalCost = $stockQuantity->multipliedBy((string) $source['stock_unit_cost'])->toScale(8, RoundingMode::HALF_UP);
        $ratio = $returned->dividedBy($received, 12, RoundingMode::HALF_UP)->toScale(12, RoundingMode::HALF_UP);

        return [
            'stock_quantity' => $stockQuantity->__toString(),
            'total_cost' => $totalCost->__toString(),
            'return_ratio' => $ratio->__toString(),
            'idempotency_key' => sprintf('purchase-return-partial:%d:line:%d', (int) $source['purchase_return_id'], (int) $source['goods_receipt_line_id']),
            'steps' => ['lock_receipt_line', 'assert_remaining_quantity', 'create_partial_stock_out', 'allocate_partial_cost', 'link_partial_credit_note'],
            'atomic' => true,
        ];
    }
}
