<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\GoodsReceipt;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\GoodsReceiptMovementAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closed writer for the approved Goods Receipt inventory boundary.
 *
 * This service intentionally has no controller/route and never creates a
 * Journal. It only owns the receipt movement and AVG/FIFO costing transaction
 * so a future posting command can add an explicit release gate around it.
 */
final class GoodsReceiptInventoryService
{
    public function __construct(private readonly StockMovementService $movements) {}

    /**
     * @return array<int, StockMovement>
     */
    public function postApprovedWithinTransaction(GoodsReceipt $receipt, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($receipt, $actorId): array {
            $locked = GoodsReceipt::query()->with('lines.goodsReceipt')->lockForUpdate()->findOrFail($receipt->id);
            if ($locked->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'Goods Receipt ต้อง Approved ก่อนลง Stock']);
            }

            $intents = GoodsReceiptMovementAdapter::map($locked);
            $movements = [];
            foreach ($intents as $intent) {
                $existing = StockMovement::query()->where('idempotency_key', $intent['idempotency_key'])->lockForUpdate()->first();
                if ($existing) {
                    $this->assertSameIntent($existing, $intent);
                }

                $movement = $this->movements->recordIntent([
                    ...$intent,
                    'created_by' => $actorId,
                ]);
                $movements[] = $this->movements->postWithinTransaction($movement);
            }

            return $movements;
        }, 3);
    }

    private function assertSameIntent(StockMovement $existing, array $intent): void
    {
        foreach (['warehouse_id', 'item_id', 'uom_id', 'movement_type', 'direction', 'quantity', 'base_quantity', 'business_date', 'source_type', 'source_id', 'source_reference'] as $field) {
            if ((string) $existing->{$field} !== (string) ($intent[$field] ?? null)) {
                throw ValidationException::withMessages(['idempotency_key' => 'Goods Receipt movement identity ไม่ตรงกับรายการเดิม']);
            }
        }

        $existingMetadata = is_array($existing->metadata) ? $existing->metadata : [];
        $intentMetadata = is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [];
        foreach (['goods_receipt_id', 'goods_receipt_line_id', 'purchase_order_line_id', 'purchase_uom_id', 'stock_uom_id', 'unit_cost', 'cost_value', 'rounding_delta', 'event_code'] as $field) {
            if ((string) ($existingMetadata[$field] ?? '') !== (string) ($intentMetadata[$field] ?? '')) {
                throw ValidationException::withMessages(['idempotency_key' => 'Goods Receipt cost/UOM snapshot ไม่ตรงกับรายการเดิม']);
            }
        }
        if (json_encode($existingMetadata['conversion_snapshot'] ?? null) !== json_encode($intentMetadata['conversion_snapshot'] ?? null)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Goods Receipt conversion snapshot ไม่ตรงกับรายการเดิม']);
        }
    }
}
