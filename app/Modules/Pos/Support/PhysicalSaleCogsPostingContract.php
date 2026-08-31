<?php

namespace App\Modules\Pos\Support;

use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Services\InventoryCostJournalAdapter;
use Illuminate\Validation\ValidationException;

/**
 * Validates the COGS payload boundary for one physical-sale stock line.
 *
 * The adapter remains side-effect free. This contract only proves that the
 * final allocation belongs to the same POS document before a future outer
 * posting transaction creates the COGS journal.
 */
final class PhysicalSaleCogsPostingContract
{
    /**
     * @return array<string,mixed>
     */
    public static function build(array $sale, CostAllocation $allocation, StockMovement $movement, Item $item): array
    {
        $saleId = (int) ($sale['id'] ?? 0);
        $number = trim((string) ($sale['document_number'] ?? ''));
        if ($saleId < 1 || $number === '') {
            throw ValidationException::withMessages(['sale' => 'ต้องมีรหัสและเลขที่เอกสารขาย']);
        }

        if ((string) $movement->source_type !== 'POS'
            || (string) $movement->source_id !== (string) $saleId
            || (string) $movement->source_reference !== $number) {
            throw ValidationException::withMessages(['movement' => 'Stock Movement ต้องอ้างอิงเอกสาร HS/IV เดียวกัน']);
        }

        $payload = (new InventoryCostJournalAdapter)->buildSalesCogsPayload($allocation, $movement, $item);
        if ((string) ($payload['source_type'] ?? '') !== 'INVENTORY'
            || (string) ($payload['event_code'] ?? '') !== 'sales_cogs') {
            throw ValidationException::withMessages(['journal' => 'COGS payload ต้องเป็น Inventory sales_cogs เท่านั้น']);
        }

        $lines = $payload['lines'] ?? [];
        if (! is_array($lines) || count($lines) !== 2) {
            throw ValidationException::withMessages(['journal' => 'COGS Journal ต้องมี 2 บรรทัด']);
        }

        $debit = collect($lines)->first(fn (array $line): bool => (string) ($line['debit'] ?? '0.00') !== '0.00');
        $credit = collect($lines)->first(fn (array $line): bool => (string) ($line['credit'] ?? '0.00') !== '0.00');
        if (! $debit || ! $credit
            || (int) ($debit['account_id'] ?? 0) !== (int) $item->cogsAccount->id
            || (int) ($credit['account_id'] ?? 0) !== (int) $item->inventoryAccount->id
            || (string) $debit['debit'] !== (string) $credit['credit']) {
            throw ValidationException::withMessages(['journal' => 'COGS Journal ต้องเดบิต COGS และเครดิต Inventory ด้วยยอดเดียวกัน']);
        }

        return [
            'sale_id' => $saleId,
            'document_number' => $number,
            'movement_id' => (int) $movement->id,
            'allocation_id' => (int) $allocation->id,
            'payload' => $payload,
        ];
    }
}
