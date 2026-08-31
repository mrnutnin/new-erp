<?php

namespace App\Modules\Pos\Support;

use App\Modules\Accounting\Support\PostingIdentity;
use App\Modules\Wms\Support\StockMovementContract;
use Illuminate\Validation\ValidationException;

/**
 * Builds deterministic ISSUE intents for an HS/IV document.
 *
 * This class has no database side effects. The caller must persist the
 * returned intents through StockMovementService inside the eventual posting
 * transaction, then post each movement and hand its final allocation to the
 * inventory-cost journal adapter.
 */
final class PhysicalSaleStockPostingIntent
{
    /** @return list<array<string,mixed>> */
    public static function build(array $document): array
    {
        $saleId = filter_var($document['physical_sale_id'] ?? null, FILTER_VALIDATE_INT);
        if (! $saleId || $saleId < 1) {
            throw ValidationException::withMessages(['physical_sale_id' => 'ต้องมีรหัสเอกสารขายจริง']);
        }
        $number = trim((string) ($document['document_number'] ?? ''));
        if ($number === '') {
            throw ValidationException::withMessages(['document_number' => 'ต้องมีเลขที่เอกสารขายจริง']);
        }
        $preview = PhysicalSalePostingContract::preview($document);
        $lines = collect(array_values($document['lines']))->keyBy(fn (array $line, int $index): int => (int) ($line['line_number'] ?? ($index + 1)));
        $intents = [];

        foreach ($preview['lines'] as $index => $line) {
            $source = $lines->get($line['line_number'], []);
            $lineId = filter_var($source['line_id'] ?? null, FILTER_VALIDATE_INT);
            if (! $lineId || $lineId < 1) {
                throw ValidationException::withMessages(["lines.{$index}.line_id" => 'ต้องมีรหัสบรรทัดเอกสารเพื่อป้องกันการ Post ซ้ำ']);
            }

            $movement = StockMovementContract::normalize([
                'warehouse_id' => $document['warehouse_id'],
                'item_id' => $line['item_id'],
                'uom_id' => $line['stock_uom_id'],
                'movement_type' => 'ISSUE',
                'direction' => 'OUT',
                'status' => 'DRAFT',
                'quantity' => $line['stock_quantity'],
                'base_quantity' => $line['stock_quantity'],
                'business_date' => $document['business_date'],
                'source_type' => 'POS',
                'source_id' => (string) $saleId,
                'source_reference' => $number,
                'idempotency_key' => PostingIdentity::key('POS', 'physical_sale.issue', "{$saleId}:{$lineId}"),
                'metadata' => [
                    'physical_sale_id' => $saleId,
                    'physical_sale_line_id' => $lineId,
                    'document_type' => $document['document_type'],
                    'sale_quantity' => $line['sale_quantity'],
                    'sale_uom_id' => $line['sale_uom_id'],
                    'stock_uom_id' => $line['stock_uom_id'],
                    'uom_factor' => $line['factor'],
                    'conversion_snapshot' => $line['conversion_snapshot'],
                ],
            ]);
            $intents[] = [...$movement, 'line_id' => $lineId, 'line_number' => $line['line_number']];
        }

        usort($intents, fn (array $a, array $b): int => $a['line_number'] <=> $b['line_number']);

        return $intents;
    }
}
