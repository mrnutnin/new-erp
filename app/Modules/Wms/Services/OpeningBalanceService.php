<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\OpeningBalanceBatch;
use App\Modules\Wms\Models\StockCostLayer;
use App\Modules\Wms\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OpeningBalanceService
{
    public function __construct(
        private readonly GlobalSettings $settings,
        private readonly StockBalanceProjectionService $balances,
    ) {}

    public function createDraft(array $data, User $actor): OpeningBalanceBatch
    {
        $method = strtoupper((string) ($data['costing_method'] ?? $this->settings->value('inventory_costing_method')));
        if (! in_array($method, ['AVG', 'FIFO'], true)) {
            throw ValidationException::withMessages(['costing_method' => 'ต้องตั้งค่า AVG หรือ FIFO ก่อนสร้าง Opening Balance']);
        }
        $configuredMethod = strtoupper((string) $this->settings->value('inventory_costing_method'));
        if (in_array($configuredMethod, ['AVG', 'FIFO'], true) && $configuredMethod !== $method) {
            throw ValidationException::withMessages(['costing_method' => 'วิธีต้นทุนต้องตรงกับ Global Settings ('.$configuredMethod.')']);
        }
        $lines = $this->normaliseLines($data['lines'] ?? []);
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'ต้องมีรายการสินค้าอย่างน้อยหนึ่งรายการ']);
        }

        return DB::transaction(function () use ($data, $actor, $method, $lines): OpeningBalanceBatch {
            $duplicate = OpeningBalanceBatch::query()
                ->where('warehouse_id', (int) $data['warehouse_id'])
                ->where('cutover_date', $data['cutover_date'])
                ->where('status', '!=', 'VOIDED')
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['cutover_date' => 'คลังนี้มียอดยกมาสำหรับวันที่ดังกล่าวแล้ว']);
            }
            $batch = OpeningBalanceBatch::query()->create([
                'warehouse_id' => (int) $data['warehouse_id'],
                'cutover_date' => $data['cutover_date'],
                'costing_method' => $method,
                'status' => 'DRAFT',
                'total_value' => collect($lines)->sum('total_value'),
                'source_reference' => $data['source_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => (string) ($data['idempotency_key'] ?? 'opening:'.bin2hex(random_bytes(16))),
                'created_by' => $actor->id,
            ]);
            $batch->lines()->createMany($lines);

            return $batch->load('lines');
        });
    }

    public function post(OpeningBalanceBatch $batch, User $actor): OpeningBalanceBatch
    {
        return DB::transaction(function () use ($batch, $actor): OpeningBalanceBatch {
            $locked = OpeningBalanceBatch::query()->with('lines')->lockForUpdate()->findOrFail($batch->id);
            if ($locked->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'Opening Balance ที่ถูก Post แล้วไม่สามารถ Post ซ้ำได้']);
            }
            if ($locked->lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'ไม่สามารถ Post Opening Balance ที่ไม่มีรายการได้']);
            }
            if ($this->settings->value('inventory_costing_method') !== $locked->costing_method) {
                throw ValidationException::withMessages(['costing_method' => 'นโยบายต้นทุนปัจจุบันไม่ตรงกับ Opening Balance']);
            }

            $conflicts = [];
            foreach ($locked->lines as $line) {
                $hasMovement = StockMovement::query()->where('warehouse_id', $locked->warehouse_id)->where('item_id', $line->item_id)->where('status', 'POSTED')->where('business_date', '<=', $locked->cutover_date)->exists();
                $hasLayer = StockCostLayer::query()->where('warehouse_id', $locked->warehouse_id)->where('item_id', $line->item_id)->where('cost_status', '!=', 'VOIDED')->where('business_date', '<=', $locked->cutover_date)->exists();
                if ($hasMovement || $hasLayer) $conflicts[] = '#'.$line->item_id;
            }
            if ($conflicts !== []) {
                throw ValidationException::withMessages(['stock_ledger' => 'พบรายการ Stock Ledger/Cost Layer เดิมก่อนหรือในวันที่เริ่มต้น: '.implode(', ', array_slice($conflicts, 0, 10))]);
            }

            foreach ($locked->lines as $line) {
                $item = Item::query()->whereKey($line->item_id)->where('is_active', true)->where('is_stock_item', true)->first();
                if (! $item || ! $item->base_uom_id) {
                    throw ValidationException::withMessages(['lines' => "สินค้า #{$line->item_id} ต้องเป็นสินค้า Stock ที่ใช้งานอยู่"]);
                }
                if ((int) $line->uom_id !== (int) $item->base_uom_id) {
                    throw ValidationException::withMessages(['lines' => "Opening Balance ของสินค้า #{$line->item_id} ต้องใช้หน่วยฐาน"]);
                }

                $movement = StockMovement::query()->firstOrCreate(
                    ['idempotency_key' => $locked->idempotency_key.':line:'.$line->id.':movement'],
                    [
                        'warehouse_id' => $locked->warehouse_id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id,
                        'movement_type' => 'RECEIPT', 'direction' => 'IN', 'status' => 'POSTED',
                        'quantity' => $line->quantity, 'base_quantity' => $line->quantity, 'business_date' => $locked->cutover_date,
                        'source_type' => 'OPENING_BALANCE', 'source_id' => (string) $locked->id,
                        'source_reference' => $locked->source_reference ?: 'OPENING-'.$locked->id,
                        'idempotency_key' => $locked->idempotency_key.':line:'.$line->id.':movement',
                        'metadata' => ['opening_balance_batch_id' => $locked->id], 'posted_at' => now(), 'created_by' => $actor->id,
                    ],
                );
                $layer = StockCostLayer::query()->firstOrCreate(
                    ['source_movement_id' => $movement->id],
                    [
                        'warehouse_id' => $locked->warehouse_id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id,
                        'original_quantity' => $line->quantity, 'remaining_quantity' => $line->quantity,
                        'unit_cost' => $line->unit_cost, 'method' => $locked->costing_method, 'cost_status' => 'FINAL',
                        'business_date' => $locked->cutover_date,
                    ],
                );
                $allocation = DB::table('wms_cost_allocations')->where('idempotency_key', $locked->idempotency_key.':line:'.$line->id.':allocation')->first();
                if (! $allocation) {
                    $allocationId = DB::table('wms_cost_allocations')->insertGetId([
                        'stock_movement_id' => $movement->id, 'stock_cost_layer_id' => $layer->id,
                        'warehouse_id' => $locked->warehouse_id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id,
                        'allocation_type' => 'RECEIPT', 'direction' => 'IN', 'cost_status' => 'FINAL', 'status' => 'POSTED',
                        'method' => $locked->costing_method, 'policy_version' => 'costing-v1', 'revision' => 0,
                        'quantity' => $line->quantity, 'unit_cost' => $line->unit_cost, 'value' => $line->total_value,
                        'business_date' => $locked->cutover_date, 'idempotency_key' => $locked->idempotency_key.':line:'.$line->id.':allocation',
                        'metadata' => json_encode(['opening_balance_batch_id' => $locked->id]), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    $allocationId = $allocation->id;
                }
                $line->update(['stock_movement_id' => $movement->id, 'cost_layer_id' => $layer->id]);
                $this->balances->rebuild((int) $locked->warehouse_id, (int) $line->item_id, (int) $line->uom_id);
            }

            $locked->update(['status' => 'POSTED', 'posted_at' => now(), 'posted_by' => $actor->id]);

            return $locked->fresh('lines');
        }, 3);
    }

    private function normaliseLines(array $lines): array
    {
        return collect($lines)->map(function (array $line): array {
            $quantity = BigDecimal::of((string) ($line['quantity'] ?? 0))->toScale(8, RoundingMode::UNNECESSARY);
            $totalValue = BigDecimal::of((string) ($line['total_value'] ?? 0))->toScale(8, RoundingMode::UNNECESSARY);
            if ($quantity->isNegativeOrZero() || $totalValue->isNegative()) {
                throw ValidationException::withMessages(['lines' => 'จำนวนต้องมากกว่า 0 และต้นทุนรวมต้องไม่ติดลบ']);
            }
            $unitCost = $quantity->isZero() ? BigDecimal::zero() : $totalValue->dividedBy($quantity, 8, RoundingMode::HALF_UP);

            return ['item_id' => (int) $line['item_id'], 'uom_id' => (int) $line['uom_id'], 'quantity' => $quantity->__toString(), 'unit_cost' => $unitCost->__toString(), 'total_value' => $totalValue->__toString()];
        })->unique(fn (array $line): string => $line['item_id'].':'.$line['uom_id'])->values()->all();
    }
}
