<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Models\Transfer;
use App\Modules\Wms\Models\TransferEvent;
use App\Modules\Wms\Models\TransferLine;
use App\Modules\Wms\Support\TransferContract;
use App\Modules\Wms\Support\TransferState;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Coordinates Transfer state and the immutable stock-movement ledger.
 *
 * Transfer is the operational document; StockMovement remains the source of
 * truth for quantities. Every command locks transfer -> lines/events before
 * entering StockMovementService, which then follows the normal stock/cost
 * lock order. Retrying a command uses the same command key and never creates
 * another event or movement.
 */
final class TransferMovementService
{
    public function __construct(private readonly StockMovementService $movements, private readonly GlobalSettings $settings, private readonly FifoTransferCostLineageService $fifoLineage, private readonly AvgTransferCostLineageService $avgLineage) {}

    public function createDraft(array $attributes, array $lines, int $actorId): Transfer
    {
        $header = TransferContract::normalizeHeader($attributes);
        if (trim((string) ($header['document_number'] ?? '')) === '' || strlen((string) $header['document_number']) > 40) {
            throw ValidationException::withMessages(['document_number' => 'ต้องระบุเลขที่ Transfer ไม่เกิน 40 ตัวอักษร']);
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'ต้องมีรายการสินค้าอย่างน้อยหนึ่งรายการ']);
        }

        return DB::transaction(function () use ($header, $lines, $actorId): Transfer {
            $existing = Transfer::query()->where('idempotency_key', $header['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                $this->assertSameDraft($existing, $header, $lines);

                return $existing->load('lines');
            }

            $items = Item::query()->where('is_active', true)->where('is_stock_item', true)
                ->whereIn('id', collect($lines)->pluck('item_id'))->lockForUpdate()->get(['id', 'base_uom_id'])->keyBy('id');
            foreach ($lines as $index => &$line) {
                $item = $items->get((int) ($line['item_id'] ?? 0));
                if (! $item || ! $item->base_uom_id) {
                    throw ValidationException::withMessages(["lines.$index.item_id" => 'สินค้านี้ไม่มีหน่วย Stock หรือไม่ใช่สินค้าคงคลัง']);
                }
                $line['uom_id'] = (int) $item->base_uom_id;
                $line['planned_base_quantity'] = $line['planned_quantity'];
            }
            unset($line);

            $transfer = Transfer::query()->create([
                ...$header,
                'status' => 'DRAFT',
                'created_by' => $actorId,
            ]);
            foreach (array_values($lines) as $index => $line) {
                TransferLine::query()->create([
                    'transfer_id' => $transfer->id,
                    'item_id' => $this->positiveInt($line['item_id'] ?? null, 'item_id'),
                    'uom_id' => $this->positiveInt($line['uom_id'] ?? null, 'uom_id'),
                    'line_number' => $index + 1,
                    'planned_quantity' => TransferContract::normalizeQuantity($line['planned_quantity'] ?? null, 'planned_quantity'),
                    'planned_base_quantity' => TransferContract::normalizeQuantity($line['planned_base_quantity'] ?? null, 'planned_base_quantity'),
                ]);
            }

            return $transfer->load('lines');
        }, 3);
    }

    public function dispatch(Transfer $transfer, int $warehouseId, User $actor, string $reason, ?string $businessDate = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $warehouseId, $actor, $reason, $businessDate): Transfer {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertWarehouse($transfer, $warehouseId, 'source');
            TransferState::assert($transfer->status, 'DISPATCHED');
            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'ต้องระบุเหตุผลการส่งออกจากคลัง']);
            }

            foreach ($transfer->lines()->lockForUpdate()->get() as $line) {
                $key = "transfer:{$transfer->id}:line:{$line->id}:dispatch";
                $existing = TransferEvent::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    $this->assertEvent($existing, 'DISPATCH', (string) $line->planned_base_quantity);

                    continue;
                }

                $movement = $this->movements->recordIntent([
                    'warehouse_id' => $transfer->source_warehouse_id,
                    'item_id' => $line->item_id,
                    'uom_id' => $line->uom_id,
                    'movement_type' => 'TRANSFER',
                    'direction' => 'OUT',
                    'status' => 'DRAFT',
                    'quantity' => (string) $line->planned_quantity,
                    'base_quantity' => (string) $line->planned_base_quantity,
                    'business_date' => $businessDate ?: $transfer->document_date->format('Y-m-d'),
                    'source_type' => 'WMS_TRANSFER',
                    'source_id' => (string) $transfer->id,
                    'source_reference' => $transfer->document_number,
                    'transfer_key' => "transfer:{$transfer->id}:line:{$line->id}",
                    'idempotency_key' => $key.':movement',
                    'metadata' => ['transfer_id' => $transfer->id, 'transfer_line_id' => $line->id, 'transfer_event' => 'DISPATCH'],
                    'created_by' => $actor->id,
                ]);
                $this->movements->postWithinTransaction($movement);
                TransferEvent::query()->create([
                    'transfer_id' => $transfer->id, 'transfer_line_id' => $line->id, 'event_type' => 'DISPATCH',
                    'quantity' => $line->planned_quantity, 'base_quantity' => $line->planned_base_quantity,
                    'business_date' => $movement->business_date, 'stock_movement_id' => $movement->id,
                    'idempotency_key' => $key, 'source_reference' => $transfer->document_number,
                    'reason' => $reason, 'created_by' => $actor->id,
                ]);
            }

            $transfer->forceFill(['status' => 'DISPATCHED', 'dispatch_reason' => $reason, 'dispatched_by' => $actor->id, 'dispatched_at' => now()])->save();

            return $transfer->fresh(['lines', 'events']);
        }, 3);
    }

    /** @param array<int|string, string|int|float> $quantities keyed by transfer line id */
    public function accept(Transfer $transfer, int $warehouseId, User $actor, array $quantities, string $commandKey, string $reason = '', ?string $businessDate = null): Transfer
    {
        return $this->complete($transfer, $warehouseId, $actor, $quantities, $commandKey, 'ACCEPT', trim($reason), $businessDate);
    }

    /** @param array<int|string, string|int|float> $quantities keyed by transfer line id */
    public function reject(Transfer $transfer, int $warehouseId, User $actor, array $quantities, string $commandKey, string $reason, ?string $businessDate = null): Transfer
    {
        return $this->complete($transfer, $warehouseId, $actor, $quantities, $commandKey, 'REJECT', trim($reason), $businessDate);
    }

    public function voidRejected(Transfer $transfer, int $warehouseId, User $actor, string $reason): Transfer
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลการยกเลิก Transfer']);
        }

        return DB::transaction(function () use ($transfer, $warehouseId, $actor, $reason): Transfer {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertWarehouse($transfer, $warehouseId, 'source');
            TransferState::assert($transfer->status, 'VOID');

            $lines = $transfer->lines()->lockForUpdate()->get();
            foreach ($lines as $line) {
                $accepted = BigDecimal::of($this->eventQuantity($line, 'ACCEPT'));
                $dispatched = BigDecimal::of($this->eventQuantity($line, 'DISPATCH'));
                $rejected = BigDecimal::of($this->eventQuantity($line, 'REJECT'));
                if ($accepted->isPositive() || $dispatched->isZero() || ! $dispatched->isEqualTo($rejected)) {
                    throw ValidationException::withMessages(['status' => 'ยกเลิกได้เฉพาะ Transfer ที่ปลายทางปฏิเสธครบทั้งใบเท่านั้น']);
                }
            }

            $transfer->forceFill([
                'status' => 'VOID',
                'void_reason' => $reason,
                'voided_by' => $actor->id,
                'voided_at' => now(),
            ])->save();

            return $transfer->fresh(['lines', 'events']);
        }, 3);
    }

    private function complete(Transfer $transfer, int $warehouseId, User $actor, array $quantities, string $commandKey, string $eventType, string $reason, ?string $businessDate): Transfer
    {
        if (trim($commandKey) === '' || strlen($commandKey) > 100) {
            throw ValidationException::withMessages(['command_key' => 'ต้องระบุ command key ไม่เกิน 100 ตัวอักษร']);
        }
        if ($eventType === 'REJECT' && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'ต้องระบุเหตุผลการปฏิเสธรับสินค้า']);
        }

        return DB::transaction(function () use ($transfer, $warehouseId, $actor, $quantities, $commandKey, $eventType, $reason, $businessDate): Transfer {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertWarehouse($transfer, $warehouseId, 'destination');
            if ($transfer->status === 'DISPATCHED') {
                // Both accepting and rejecting a portion are valid first
                // completion commands; statusAfter() decides whether the
                // final result is ACCEPTED, REJECTED, or PARTIALLY_ACCEPTED.
                TransferState::assert($transfer->status, 'PARTIALLY_ACCEPTED');
            } elseif ($transfer->status !== 'PARTIALLY_ACCEPTED') {
                throw ValidationException::withMessages(['status' => 'Transfer นี้ไม่อยู่ในสถานะที่รับหรือปฏิเสธต่อได้']);
            }
            $lines = $transfer->lines()->lockForUpdate()->get();
            foreach ($lines as $line) {
                $raw = $quantities[$line->id] ?? null;
                if ($raw === null) {
                    continue;
                }
                $baseQuantity = TransferContract::normalizeQuantity($raw, 'base_quantity');
                $quantity = $this->quantityForBase($line, $baseQuantity);
                $dispatched = $this->eventQuantity($line, 'DISPATCH');
                $accepted = $this->eventQuantity($line, 'ACCEPT');
                $rejected = $this->eventQuantity($line, 'REJECT');
                $remaining = BigDecimal::of($dispatched)->minus($accepted)->minus($rejected);

                $key = "transfer:{$transfer->id}:command:{$commandKey}:line:{$line->id}:".strtolower($eventType);
                $existing = TransferEvent::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    $this->assertEvent($existing, $eventType, $baseQuantity);

                    continue;
                }
                if (BigDecimal::of($baseQuantity)->isGreaterThan($remaining)) {
                    throw ValidationException::withMessages(['quantity' => 'จำนวนเกินยอดที่ยังรอรับ/ปฏิเสธของ Transfer']);
                }

                $movement = $this->settings->value('inventory_costing_method') === 'FIFO'
                    ? $this->fifoLineage->inbound($line, $transfer, $quantity, $baseQuantity, $commandKey, $actor, $eventType === 'ACCEPT' ? $transfer->destination_warehouse_id : $transfer->source_warehouse_id, $eventType, $businessDate)
                    : ($this->settings->value('inventory_costing_method') === 'AVG'
                        ? $this->avgLineage->inbound($line, $transfer, $quantity, $baseQuantity, $commandKey, $actor, $eventType === 'ACCEPT' ? $transfer->destination_warehouse_id : $transfer->source_warehouse_id, $eventType, $businessDate)
                        : ($eventType === 'REJECT'
                        ? $this->reverseDispatch($line, $transfer, $quantity, $baseQuantity, $key, $actor, $businessDate)
                        : $this->acceptMovement($line, $transfer, $quantity, $baseQuantity, $key, $actor, $businessDate)));
                TransferEvent::query()->create([
                    'transfer_id' => $transfer->id, 'transfer_line_id' => $line->id, 'event_type' => $eventType,
                    'quantity' => $quantity, 'base_quantity' => $baseQuantity, 'business_date' => $movement->business_date,
                    'stock_movement_id' => $movement->id, 'idempotency_key' => $key, 'source_reference' => $transfer->document_number,
                    'reason' => $reason ?: null, 'created_by' => $actor->id,
                ]);
            }

            $status = $this->statusAfter($lines);
            $transfer->forceFill([
                'status' => $status,
                'reject_reason' => $eventType === 'REJECT' ? $reason : $transfer->reject_reason,
                'completed_by' => in_array($status, ['ACCEPTED', 'REJECTED'], true) ? $actor->id : null,
                'completed_at' => in_array($status, ['ACCEPTED', 'REJECTED'], true) ? now() : null,
            ])->save();

            return $transfer->fresh(['lines', 'events']);
        }, 3);
    }

    private function acceptMovement(TransferLine $line, Transfer $transfer, string $quantity, string $baseQuantity, string $key, User $actor, ?string $businessDate): StockMovement
    {
        $source = $this->dispatchMovement($line);
        $unitCost = $this->trustedDispatchUnitCost($source, $quantity);
        $movement = $this->movements->recordIntent([
            'warehouse_id' => $transfer->destination_warehouse_id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id,
            'movement_type' => 'TRANSFER', 'direction' => 'IN', 'status' => 'DRAFT', 'quantity' => $quantity, 'base_quantity' => $baseQuantity,
            'business_date' => $businessDate ?: $transfer->document_date->format('Y-m-d'), 'source_type' => 'WMS_TRANSFER',
            'source_id' => (string) $transfer->id, 'source_reference' => $transfer->document_number,
            'transfer_key' => "transfer:{$transfer->id}:line:{$line->id}", 'idempotency_key' => $key.':movement',
            'metadata' => ['transfer_id' => $transfer->id, 'transfer_line_id' => $line->id, 'transfer_event' => 'ACCEPT', 'source_movement_id' => $source->id, 'unit_cost' => $unitCost, 'unit_cost_trusted' => true],
            'created_by' => $actor->id,
        ]);

        $movement = $this->movements->postWithinTransaction($movement);
        $sourceAllocation = CostAllocation::query()
            ->where('stock_movement_id', $source->id)
            ->where('status', '!=', 'REVERSED')
            ->where('cost_status', 'FINAL')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        $destinationAllocation = CostAllocation::query()
            ->where('stock_movement_id', $movement->id)
            ->where('allocation_type', 'TRANSFER')
            ->where('direction', 'IN')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        if ($sourceAllocation && $destinationAllocation) {
            $destinationAllocation->forceFill([
                'parent_allocation_id' => $sourceAllocation->id,
                'metadata' => [
                    ...(is_array($destinationAllocation->metadata) ? $destinationAllocation->metadata : []),
                    'source_allocation_id' => $sourceAllocation->id,
                    'source_movement_id' => $source->id,
                ],
            ])->save();
        }

        return $movement;
    }

    private function reverseDispatch(TransferLine $line, Transfer $transfer, string $quantity, string $baseQuantity, string $key, User $actor, ?string $businessDate): StockMovement
    {
        $source = $this->dispatchMovement($line);
        $sourceQuantity = BigDecimal::of((string) $source->base_quantity);
        if (BigDecimal::of($baseQuantity)->isGreaterThan($sourceQuantity)) {
            throw ValidationException::withMessages(['quantity' => 'จำนวน reject มากกว่ายอด dispatch']);
        }
        if (BigDecimal::of($baseQuantity)->isEqualTo($sourceQuantity)) {
            return $this->movements->reverseWithinTransaction($source, ['idempotency_key' => $key.':movement', 'business_date' => $businessDate ?: $source->business_date, 'created_by' => $actor->id]);
        }
        $unitCost = $this->trustedDispatchUnitCost($source, $quantity);
        $movement = $this->movements->recordIntent([
            'warehouse_id' => $transfer->source_warehouse_id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id,
            'movement_type' => 'TRANSFER', 'direction' => 'IN', 'status' => 'DRAFT', 'quantity' => $quantity, 'base_quantity' => $baseQuantity,
            'business_date' => $businessDate ?: $source->business_date, 'source_type' => 'WMS_TRANSFER', 'source_id' => (string) $transfer->id,
            'source_reference' => $transfer->document_number, 'transfer_key' => "transfer:{$transfer->id}:line:{$line->id}",
            'idempotency_key' => $key.':movement', 'metadata' => ['transfer_id' => $transfer->id, 'transfer_line_id' => $line->id, 'transfer_event' => 'REJECT', 'source_movement_id' => $source->id, 'unit_cost' => $unitCost, 'unit_cost_trusted' => true],
            'created_by' => $actor->id,
        ]);

        return $this->movements->postWithinTransaction($movement);
    }

    private function dispatchMovement(TransferLine $line): StockMovement
    {
        return StockMovement::query()->where('source_type', 'WMS_TRANSFER')->where('source_id', (string) $line->transfer_id)->where('transfer_key', "transfer:{$line->transfer_id}:line:{$line->id}")->where('direction', 'OUT')->where('status', 'POSTED')->lockForUpdate()->firstOrFail();
    }

    private function trustedDispatchUnitCost(StockMovement $source, string $quantity): string
    {
        $allocations = CostAllocation::query()->where('stock_movement_id', $source->id)->where('status', '!=', 'REVERSED')->where('cost_status', '!=', 'PENDING')->lockForUpdate()->get();
        $value = $allocations->reduce(fn (BigDecimal $sum, CostAllocation $allocation): BigDecimal => $sum->plus(BigDecimal::of((string) $allocation->value)->abs()), BigDecimal::zero());
        $sourceQuantity = BigDecimal::of((string) $source->base_quantity);
        if ($allocations->isEmpty() || $sourceQuantity->isZero()) {
            throw ValidationException::withMessages(['cost' => 'Transfer ยังไม่มีต้นทุนที่ยืนยันแล้วสำหรับรับเข้าปลายทาง']);
        }

        return $value->dividedBy($sourceQuantity, 8, RoundingMode::HALF_UP)->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    private function statusAfter($lines): string
    {
        $accepted = BigDecimal::zero();
        $rejected = BigDecimal::zero();
        $pending = false;
        foreach ($lines as $line) {
            $dispatched = BigDecimal::of($this->eventQuantity($line, 'DISPATCH'));
            $accepted = $accepted->plus(BigDecimal::of($this->eventQuantity($line, 'ACCEPT')));
            $rejected = $rejected->plus(BigDecimal::of($this->eventQuantity($line, 'REJECT')));
            if ($dispatched->minus(BigDecimal::of($this->eventQuantity($line, 'ACCEPT')))->minus(BigDecimal::of($this->eventQuantity($line, 'REJECT')))->isPositive()) {
                $pending = true;
            }
        }
        if ($pending && ($accepted->isPositive() || $rejected->isPositive())) {
            return 'PARTIALLY_ACCEPTED';
        }
        if (! $pending && $accepted->isZero() && $rejected->isPositive()) {
            return 'REJECTED';
        }

        return 'ACCEPTED';
    }

    private function eventQuantity(TransferLine $line, string $type): string
    {
        return (string) $line->events()->where('event_type', $type)->sum('base_quantity');
    }

    private function assertEvent(TransferEvent $event, string $type, string $quantity): void
    {
        if ($event->event_type !== $type || (string) $event->base_quantity !== $quantity) {
            throw ValidationException::withMessages(['idempotency_key' => 'Transfer command key ถูกใช้กับข้อมูลคนละชุด']);
        }
    }

    private function assertWarehouse(Transfer $transfer, int $warehouseId, string $side): void
    {
        $expected = $side === 'source' ? $transfer->source_warehouse_id : $transfer->destination_warehouse_id;
        if ($expected !== $warehouseId) {
            throw ValidationException::withMessages(['warehouse' => 'Warehouse context ไม่ตรงกับฝั่งของ Transfer']);
        }
    }

    private function assertSameDraft(Transfer $existing, array $header, array $lines): void
    {
        foreach (['source_warehouse_id', 'destination_warehouse_id', 'document_date', 'document_number'] as $field) {
            if ((string) $existing->{$field} !== (string) ($header[$field] ?? '')) {
                throw ValidationException::withMessages(['idempotency_key' => 'Transfer key ถูกใช้กับข้อมูลคนละชุด']);
            }
        }
        if ($existing->lines()->count() !== count($lines)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Transfer key ถูกใช้กับจำนวนบรรทัดคนละชุด']);
        }
    }

    private function quantityForBase(TransferLine $line, string $baseQuantity): string
    {
        $plannedBase = BigDecimal::of((string) $line->planned_base_quantity);
        if ($plannedBase->isZero()) {
            throw ValidationException::withMessages(['quantity' => 'จำนวนฐานของ Transfer ต้องมากกว่าศูนย์']);
        }

        return BigDecimal::of($baseQuantity)
            ->multipliedBy(BigDecimal::of((string) $line->planned_quantity))
            ->dividedBy($plannedBase, 8, RoundingMode::HALF_UP)
            ->toScale(8, RoundingMode::HALF_UP)
            ->__toString();
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (! filter_var($value, FILTER_VALIDATE_INT) || (int) $value < 1) {
            throw ValidationException::withMessages([$field => 'ต้องเป็นรหัสจำนวนเต็มที่มากกว่า 0']);
        }

        return (int) $value;
    }
}
