<?php

namespace App\Modules\Purchasing\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Read-only 3-way matching foundation.
 *
 * The caller supplies already-loaded snapshots. This contract deliberately
 * does not query, mutate, post Stock/Cost/GL, or decide approval policy.
 */
final class PurchaseThreeWayMatchContract
{
    /**
     * @param  array<string,mixed>  $purchaseOrder
     * @param  array<int,array<string,mixed>>  $receipts
     * @param  array<string,mixed>  $purchaseDocument
     * @return array<string,mixed>
     */
    public static function evaluate(
        array $purchaseOrder,
        array $receipts,
        array $purchaseDocument,
        string $quantityTolerance = '0.00000001',
        string $priceTolerance = '0.01',
    ): array {
        $blockers = [];
        $poId = self::id($purchaseOrder['id'] ?? null);
        $documentId = self::id($purchaseDocument['id'] ?? null);
        $warehouseId = self::id($purchaseOrder['warehouse_id'] ?? null);
        $supplierId = self::id($purchaseOrder['supplier_id'] ?? null);

        if ($poId < 1 || ($purchaseOrder['status'] ?? null) !== 'APPROVED') {
            $blockers[] = 'purchase_order_not_approved';
        }
        if ($documentId < 1 || ($purchaseDocument['document_type'] ?? null) !== 'INVOICE') {
            $blockers[] = 'purchase_document_not_invoice';
        }
        if (! in_array($purchaseDocument['status'] ?? null, ['APPROVED', 'POSTED'], true)) {
            $blockers[] = 'purchase_document_not_ready';
        }
        if ($warehouseId < 1 || $supplierId < 1 || self::id($purchaseDocument['warehouse_id'] ?? null) !== $warehouseId || self::id($purchaseDocument['supplier_id'] ?? null) !== $supplierId) {
            $blockers[] = 'supplier_or_warehouse_mismatch';
        }

        $poLines = self::index($purchaseOrder['lines'] ?? [], 'id');
        $documentLines = self::index($purchaseDocument['lines'] ?? [], 'id');
        if ($poLines === []) {
            $blockers[] = 'purchase_order_lines_required';
        }
        if ($documentLines === []) {
            $blockers[] = 'purchase_document_lines_required';
        }
        $receiptTotals = [];
        $receiptCostTotals = [];
        $receiptKeys = [];

        foreach ($receipts as $receipt) {
            if (self::id($receipt['purchase_order_id'] ?? null) !== $poId
                || self::id($receipt['warehouse_id'] ?? null) !== $warehouseId
                || self::id($receipt['supplier_id'] ?? null) !== $supplierId) {
                $blockers[] = 'receipt_source_mismatch';
            }
            if (($receipt['status'] ?? null) !== 'APPROVED') {
                $blockers[] = 'receipt_not_approved';
            }
            foreach (($receipt['lines'] ?? []) as $line) {
                $lineId = self::id($line['id'] ?? null);
                $poLineId = self::id($line['purchase_order_line_id'] ?? null);
                if ($lineId < 1 || $poLineId < 1 || isset($receiptKeys[$lineId])) {
                    $blockers[] = 'duplicate_or_missing_receipt_line_identity';

                    continue;
                }
                $receiptKeys[$lineId] = true;
                if (! isset($poLines[$poLineId])) {
                    $blockers[] = 'receipt_po_line_not_found';

                    continue;
                }
                $poLine = $poLines[$poLineId];
                if (self::id($line['item_id'] ?? null) !== self::id($poLine['item_id'] ?? null)
                    || self::id($line['purchase_uom_id'] ?? null) !== self::id($poLine['uom_id'] ?? null)) {
                    $blockers[] = 'receipt_item_or_uom_mismatch';
                }
                $qty = self::decimal($line['purchase_quantity'] ?? null, $blockers);
                $cost = self::decimal($line['total_cost'] ?? null, $blockers);
                if (BigDecimal::of($qty)->isLessThanOrEqualTo(0) || BigDecimal::of($cost)->isLessThan(0)) {
                    $blockers[] = 'invalid_receipt_quantity_or_cost';
                }
                $receiptTotals[$poLineId] = self::add($receiptTotals[$poLineId] ?? '0', $qty);
                $receiptCostTotals[$poLineId] = self::add($receiptCostTotals[$poLineId] ?? '0', $cost);
            }
        }

        $invoiceTotals = [];
        $invoiceValueTotals = [];
        $invoiceKeys = [];
        foreach ($documentLines as $line) {
            $lineId = self::id($line['id'] ?? null);
            $poLineId = self::id($line['purchase_order_line_id'] ?? null);
            if ($lineId < 1 || $poLineId < 1 || isset($invoiceKeys[$lineId])) {
                $blockers[] = 'purchase_document_line_identity_required';

                continue;
            }
            $invoiceKeys[$lineId] = true;
            if (! isset($poLines[$poLineId])) {
                $blockers[] = 'purchase_document_po_line_not_found';

                continue;
            }
            $poLine = $poLines[$poLineId];
            if (self::id($line['item_id'] ?? null) !== self::id($poLine['item_id'] ?? null)
                || self::id($line['uom_id'] ?? null) !== self::id($poLine['uom_id'] ?? null)) {
                $blockers[] = 'purchase_document_item_or_uom_mismatch';
            }
            $qty = self::decimal($line['quantity'] ?? null, $blockers);
            $unitPrice = self::decimal($line['unit_price'] ?? null, $blockers);
            if (BigDecimal::of($qty)->isLessThanOrEqualTo(0) || BigDecimal::of($unitPrice)->isLessThan(0)) {
                $blockers[] = 'invalid_invoice_quantity_or_price';
            }
            $invoiceTotals[$poLineId] = self::add($invoiceTotals[$poLineId] ?? '0', $qty);
            $invoiceValueTotals[$poLineId] = self::add($invoiceValueTotals[$poLineId] ?? '0', self::multiply($qty, $unitPrice));
        }

        $lines = [];
        foreach ($poLines as $poLineId => $poLine) {
            $poQty = self::decimal($poLine['quantity'] ?? null, $blockers);
            $poPrice = self::decimal($poLine['unit_price'] ?? null, $blockers);
            if (BigDecimal::of($poQty)->isLessThanOrEqualTo(0) || BigDecimal::of($poPrice)->isLessThan(0)) {
                $blockers[] = 'invalid_purchase_order_quantity_or_price';
            }
            $received = $receiptTotals[$poLineId] ?? '0';
            $invoiced = $invoiceTotals[$poLineId] ?? '0';
            $receivedValue = $receiptCostTotals[$poLineId] ?? '0';
            $invoicedValue = $invoiceValueTotals[$poLineId] ?? '0';
            $quantityVariance = self::subtract($invoiced, $received);
            $priceVariance = self::subtract($invoicedValue, self::multiply($invoiced, $poPrice));
            $lineBlockers = [];
            if (self::greater($received, $poQty, $quantityTolerance)) {
                $lineBlockers[] = 'receipt_exceeds_po_quantity';
            }
            if (self::greater($invoiced, $received, $quantityTolerance)) {
                $lineBlockers[] = 'invoice_exceeds_received_quantity';
            }
            if (self::greater(self::abs($priceVariance), $priceTolerance, '0')) {
                $lineBlockers[] = 'invoice_price_variance';
            }
            if (isset($receiptCostTotals[$poLineId]) && self::greater(self::abs(self::subtract($receivedValue, self::multiply($received, $poPrice))), $priceTolerance, '0')) {
                $lineBlockers[] = 'receipt_cost_variance';
            }
            $blockers = [...$blockers, ...$lineBlockers];
            $lines[] = [
                'purchase_order_line_id' => (int) $poLineId,
                'ordered_quantity' => $poQty,
                'received_quantity' => $received,
                'invoiced_quantity' => $invoiced,
                'quantity_variance' => $quantityVariance,
                'ordered_value' => self::multiply($poQty, $poPrice),
                'received_value' => $receivedValue,
                'invoiced_value' => $invoicedValue,
                'price_variance' => $priceVariance,
                'blockers' => array_values(array_unique($lineBlockers)),
            ];
        }

        return [
            'ready' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'source_of_truth' => 'PURCHASE_DOCUMENT_WITH_EXPLICIT_PO_LINE_AND_GOODS_RECEIPT_LINE',
            'idempotency_key' => $documentId > 0 && $poId > 0 ? "purchase-3way:po:{$poId}:document:{$documentId}:revision:0" : null,
            'lines' => $lines,
        ];
    }

    private static function index(array $rows, string $key): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $id = self::id($row[$key] ?? null);
            if ($id > 0) {
                $indexed[$id] = $row;
            }
        }

        return $indexed;
    }

    private static function id(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function decimal(mixed $value, array &$blockers): string
    {
        try {
            return BigDecimal::of((string) $value)->toScale(8, RoundingMode::HALF_UP)->__toString();
        } catch (\Throwable) {
            $blockers[] = 'invalid_decimal_snapshot';

            return '0.00000000';
        }
    }

    private static function add(string $left, string $right): string
    {
        return BigDecimal::of($left)->plus(BigDecimal::of($right))->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    private static function subtract(string $left, string $right): string
    {
        return BigDecimal::of($left)->minus(BigDecimal::of($right))->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    private static function multiply(string $left, string $right): string
    {
        return BigDecimal::of($left)->multipliedBy(BigDecimal::of($right))->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    private static function abs(string $value): string
    {
        return BigDecimal::of($value)->abs()->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    private static function greater(string $left, string $right, string $tolerance): bool
    {
        return BigDecimal::of($left)->minus(BigDecimal::of($right))->isGreaterThan(BigDecimal::of($tolerance));
    }
}
