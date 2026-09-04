<?php

namespace App\Modules\Wms\Support;

use App\Modules\Purchasing\Models\GoodsReceipt;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Closed boundary between an approved Goods Receipt snapshot and the future
 * Inventory/Costing writer. It only builds deterministic intents; it never
 * creates movements, cost layers, allocations, or journals.
 */
final class GoodsReceiptInventoryPostingContract
{
    /** @return array<int, array<string, mixed>> */
    public static function movementIntents(GoodsReceipt $receipt): array
    {
        if ($receipt->status !== 'APPROVED') {
            self::fail('status', 'Goods Receipt ต้องอนุมัติก่อนจึงเตรียม Inventory intent ได้');
        }
        if ((int) $receipt->warehouse_id < 1 || (int) $receipt->purchase_order_id < 1) {
            self::fail('source', 'Goods Receipt ต้องมี Warehouse และ Purchase Order ต้นทาง');
        }
        $lines = $receipt->relationLoaded('lines') ? $receipt->lines : $receipt->lines()->get();
        if ($lines->isEmpty()) {
            self::fail('lines', 'Goods Receipt ต้องมีรายการก่อนเตรียม Inventory intent');
        }

        return $lines->map(function ($line) use ($receipt): array {
            self::assertLine($line, $receipt);
            $snapshot = is_array($line->conversion_snapshot) ? $line->conversion_snapshot : [];
            $stockQuantity = BigDecimal::of((string) $line->stock_quantity)->toScale(8, RoundingMode::UNNECESSARY);
            $totalCost = BigDecimal::of((string) $line->total_cost)->toScale(8, RoundingMode::UNNECESSARY);

            return [
                'warehouse_id' => (int) $line->goodsReceipt->warehouse_id,
                'item_id' => (int) $line->item_id,
                'uom_id' => (int) $line->stock_uom_id,
                'movement_type' => 'RECEIPT',
                'direction' => 'IN',
                'status' => 'DRAFT',
                'quantity' => $stockQuantity->__toString(),
                'base_quantity' => $stockQuantity->__toString(),
                'business_date' => $line->goodsReceipt->business_date->format('Y-m-d'),
                'source_type' => 'GOODS_RECEIPT',
                'source_id' => (string) $line->goods_receipt_id,
                'source_reference' => (string) $line->goodsReceipt->receipt_number,
                'idempotency_key' => "goods-receipt:{$line->goods_receipt_id}:line:{$line->id}",
                'metadata' => [
                    'goods_receipt_id' => (int) $line->goods_receipt_id,
                    'goods_receipt_line_id' => (int) $line->id,
                    'purchase_order_line_id' => (int) $line->purchase_order_line_id,
                    'purchase_quantity' => (string) $line->purchase_quantity,
                    'purchase_uom_id' => (int) $line->purchase_uom_id,
                    'stock_uom_id' => (int) $line->stock_uom_id,
                    'conversion_snapshot' => $snapshot,
                    'unit_cost' => (string) $line->stock_unit_cost,
                    'cost_value' => $totalCost->__toString(),
                    'rounding_delta' => (string) $line->rounding_delta,
                    'unit_cost_trusted' => true,
                    'event_code' => 'goods_receipt.inventory',
                ],
            ];
        })->values()->all();
    }

    private static function assertLine($line, GoodsReceipt $receipt): void
    {
        if ((int) $line->goods_receipt_id !== (int) $receipt->id || (int) $line->item_id < 1 || (int) $line->purchase_order_line_id < 1 || (int) $line->purchase_uom_id < 1 || (int) $line->stock_uom_id < 1) {
            self::fail('lines', 'Goods Receipt line ต้องมี source line, Item และ UOM snapshot ครบ');
        }
        foreach (['purchase_quantity', 'factor', 'stock_quantity', 'total_cost', 'stock_unit_cost', 'rounding_delta'] as $field) {
            try {
                BigDecimal::of((string) $line->{$field});
            } catch (\Throwable) {
                self::fail('lines', "Goods Receipt {$field} snapshot ไม่ถูกต้อง");
            }
        }
        if (BigDecimal::of((string) $line->purchase_quantity)->isLessThanOrEqualTo(0) || BigDecimal::of((string) $line->factor)->isLessThanOrEqualTo(0) || BigDecimal::of((string) $line->stock_quantity)->isLessThanOrEqualTo(0)) {
            self::fail('lines', 'Goods Receipt quantity/factor snapshot ต้องมากกว่าศูนย์');
        }
        $snapshot = is_array($line->conversion_snapshot) ? $line->conversion_snapshot : [];
        foreach (['purchase_uom_id', 'stock_uom_id', 'factor', 'business_date'] as $field) {
            if (! array_key_exists($field, $snapshot)) {
                self::fail('lines', 'Goods Receipt conversion snapshot ไม่ครบ');
            }
        }
        if ((int) $snapshot['purchase_uom_id'] !== (int) $line->purchase_uom_id
            || (int) $snapshot['stock_uom_id'] !== (int) $line->stock_uom_id
            || (string) $snapshot['business_date'] !== $receipt->business_date->format('Y-m-d')
            || ! self::decimal($snapshot['factor'])->isEqualTo(self::decimal($line->factor))) {
            self::fail('lines', 'Goods Receipt conversion snapshot ไม่ตรงกับ UOM/factor/วันที่ของบรรทัด');
        }
        $expectedStockQuantity = self::decimal($line->purchase_quantity)
            ->multipliedBy(self::decimal($line->factor))
            ->toScale(8, RoundingMode::HALF_UP);
        if (! $expectedStockQuantity->isEqualTo(self::decimal($line->stock_quantity)->toScale(8, RoundingMode::HALF_UP))) {
            self::fail('lines', 'จำนวน Stock หลังแปลงไม่ตรงกับ purchase quantity × factor');
        }
        $reconciledCost = self::decimal($line->stock_unit_cost)
            ->multipliedBy(self::decimal($line->stock_quantity))
            ->plus(self::decimal($line->rounding_delta))
            ->toScale(8, RoundingMode::HALF_UP);
        if (! $reconciledCost->isEqualTo(self::decimal($line->total_cost)->toScale(8, RoundingMode::HALF_UP))) {
            self::fail('lines', 'ต้นทุนรวมไม่ตรงกับ stock unit cost และ rounding delta');
        }
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private static function decimal(mixed $value): BigDecimal
    {
        try {
            return BigDecimal::of((string) $value);
        } catch (\Throwable) {
            self::fail('lines', 'Goods Receipt decimal snapshot ไม่ถูกต้อง');
        }
    }
}
