<?php

namespace App\Modules\Production\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\SalesOrder;
use App\Modules\Pos\Models\SalesOrderLine;
use App\Modules\Production\Models\BomRevision;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderScrap;
use App\Modules\Wms\Models\InventoryAdjustment;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\StockReservation;
use App\Modules\Wms\Services\IssueReturnService;
use App\Modules\Wms\Services\StockBalanceService;
use App\Modules\Wms\Services\StockReservationService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductionOrderService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly StockBalanceService $balances,
    ) {}

    public function createMakeToStock(array $values, Warehouse $warehouse, User $actor, Request $request): ProductionOrder
    {
        $warehouse->loadMissing('branch');

        return DB::transaction(function () use ($values, $warehouse, $actor, $request): ProductionOrder {
            $revision = BomRevision::query()->with(['bom', 'lines.substitutes'])->lockForUpdate()->findOrFail((int) $values['bom_revision_id']);
            if ($revision->status !== 'ACTIVE' || ! $revision->bom?->is_active || (int) $revision->bom->branch_id !== (int) $warehouse->branch_id) {
                throw ValidationException::withMessages(['bom_revision_id' => 'ต้องเลือก Active BOM ในสาขาปัจจุบัน']);
            }
            if ($revision->lines->isEmpty()) {
                throw ValidationException::withMessages(['bom_revision_id' => 'Active BOM ต้องมีวัตถุดิบอย่างน้อยหนึ่งรายการ']);
            }
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PRODUCTION_ORDER')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบสั่งผลิต']);
            $quantity = BigDecimal::of((string) $values['planned_quantity'])->toScale(8, RoundingMode::HALF_UP);
            if (! $quantity->isPositive()) throw ValidationException::withMessages(['planned_quantity' => 'จำนวนผลิตต้องมากกว่า 0']);
            $date = Carbon::today();
            $this->assertPlanDates($values, $values['planned_start_date'] ?? $date->format('Y-m-d'));
            $productionOrder = ProductionOrder::query()->create([
                'branch_id' => $warehouse->branch_id,
                'issue_warehouse_id' => $warehouse->id,
                'receipt_warehouse_id' => $warehouse->id,
                'document_number' => $this->sequences->issueForBranch($sequence, $warehouse->branch, $date),
                'order_type' => 'MAKE_TO_STOCK',
                'status' => 'DRAFT',
                'finished_item_id' => $revision->bom->finished_item_id,
                'uom_id' => $revision->bom->base_uom_id,
                'planned_quantity' => $this->decimal($quantity),
                'bom_revision_id' => $revision->id,
                'planned_start_date' => $values['planned_start_date'] ?? $date->format('Y-m-d'),
                'planned_finish_date' => $values['planned_finish_date'] ?? null,
                'planned_start_at' => $values['planned_start_at'] ?? null,
                'planned_finish_at' => $values['planned_finish_at'] ?? null,
                'required_delivery_at' => $values['required_delivery_at'] ?? null,
                'notes' => $values['notes'] ?? null,
                'responsible_user_id' => $actor->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->sequences->recordIssued($sequence->fresh(), $productionOrder->document_number, 'production_orders', $productionOrder->id, $date, $actor->id);
            foreach ($revision->lines as $index => $bomLine) {
                $component = $this->selectedComponent($bomLine, $values);
                $productionOrder->materials()->create([
                    'line_number' => $index + 1,
                    'source_bom_line_id' => $bomLine->id,
                    'item_id' => $component['item_id'],
                    'uom_id' => $component['uom_id'],
                    'required_quantity' => $this->decimal(BigDecimal::of((string) $bomLine->quantity)->multipliedBy($quantity)->multipliedBy($component['factor'])),
                ]);
            }
            $this->ensureDefaultOperation($productionOrder);
            $productionOrder->events()->create(['event_type' => 'created', 'payload' => ['source' => 'MAKE_TO_STOCK'], 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.order.created', $productionOrder, [], $productionOrder->fresh(['materials'])->toArray(), $actor, $request);

            return $productionOrder->fresh(['materials', 'finishedItem', 'uom']);
        }, 3);
    }

    public function updateDraft(ProductionOrder $order, array $values, Warehouse $warehouse, User $actor, Request $request): ProductionOrder
    {
        return DB::transaction(function () use ($order, $values, $warehouse, $actor, $request): ProductionOrder {
            $locked = ProductionOrder::query()->with(['materials', 'salesOrderLine'])->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->branch_id !== (int) $warehouse->branch_id || (int) $locked->issue_warehouse_id !== (int) $warehouse->id || $locked->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'แก้ไขได้เฉพาะใบสั่งผลิตสถานะร่างในคลัง/สาขาปัจจุบัน']);
            }
            if ($locked->order_type === 'MAKE_TO_ORDER') {
                $this->assertPlanDates($values, $values['planned_start_date'], $locked->salesOrderLine?->requested_start_date?->format('Y-m-d'));
                $before = $locked->toArray();
                $locked->forceFill([
                    'planned_start_date' => $values['planned_start_date'],
                    'planned_finish_date' => $values['planned_finish_date'] ?? null,
                    'planned_start_at' => $values['planned_start_at'] ?? null,
                    'planned_finish_at' => $values['planned_finish_at'] ?? null,
                    'required_delivery_at' => $values['required_delivery_at'] ?? null,
                    'notes' => $values['notes'] ?? null,
                    'updated_by' => $actor->id,
                ])->save();
                $locked->events()->create(['event_type' => 'updated', 'payload' => ['source' => 'MAKE_TO_ORDER'], 'occurred_at' => now(), 'created_by' => $actor->id]);
                $this->audit->record('production.order.updated', $locked, $before, $locked->fresh()->toArray(), $actor, $request);

                return $locked->fresh(['materials', 'salesOrder']);
            }
            if ($locked->order_type !== 'MAKE_TO_STOCK') throw ValidationException::withMessages(['order_type' => 'ประเภทใบสั่งผลิตไม่ถูกต้อง']);
            $revision = BomRevision::query()->with(['bom', 'lines.substitutes'])->lockForUpdate()->findOrFail((int) $values['bom_revision_id']);
            if ($revision->status !== 'ACTIVE' || ! $revision->bom?->is_active || (int) $revision->bom->branch_id !== (int) $warehouse->branch_id) {
                throw ValidationException::withMessages(['bom_revision_id' => 'ต้องเลือก Active BOM ในสาขาปัจจุบัน']);
            }
            $quantity = BigDecimal::of((string) $values['planned_quantity'])->toScale(8, RoundingMode::HALF_UP);
            if (! $quantity->isPositive()) throw ValidationException::withMessages(['planned_quantity' => 'จำนวนผลิตต้องมากกว่า 0']);
            $this->assertPlanDates($values, $values['planned_start_date'] ?? $locked->planned_start_date?->format('Y-m-d') ?? today()->toDateString());
            $before = $locked->load('materials')->toArray();
            $preserveMaterials = ! $actor->hasPermission('production.orders.substitute.use')
                && (int) $locked->bom_revision_id === (int) $revision->id
                && BigDecimal::of((string) $locked->planned_quantity)->isEqualTo($quantity);
            $newMaterials = $preserveMaterials
                ? $locked->materials->map(fn ($material) => $material->only(['line_number', 'source_bom_line_id', 'item_id', 'uom_id', 'required_quantity']))
                : $revision->lines->values()->map(function ($bomLine, int $index) use ($values, $quantity): array {
                $component = $this->selectedComponent($bomLine, $values);
                return [
                    'line_number' => $index + 1,
                    'source_bom_line_id' => $bomLine->id,
                    'item_id' => $component['item_id'],
                    'uom_id' => $component['uom_id'],
                    'required_quantity' => $this->decimal(BigDecimal::of((string) $bomLine->quantity)->multipliedBy($quantity)->multipliedBy($component['factor'])),
                ];
            });
            $oldMaterials = $locked->materials->map(fn ($material) => $material->only(['line_number', 'source_bom_line_id', 'item_id', 'uom_id', 'required_quantity']));
            $materialsChanged = (int) $locked->bom_revision_id !== (int) $revision->id || $newMaterials->all() != $oldMaterials->all();
            $locked->forceFill([
                'finished_item_id' => $revision->bom->finished_item_id,
                'uom_id' => $revision->bom->base_uom_id,
                'planned_quantity' => $this->decimal($quantity),
                'bom_revision_id' => $revision->id,
                'planned_start_date' => $values['planned_start_date'] ?? $locked->planned_start_date,
                'planned_finish_date' => $values['planned_finish_date'] ?? null,
                'planned_start_at' => $values['planned_start_at'] ?? null,
                'planned_finish_at' => $values['planned_finish_at'] ?? null,
                'required_delivery_at' => $values['required_delivery_at'] ?? null,
                'notes' => $values['notes'] ?? null,
                'updated_by' => $actor->id,
            ])->save();
            if ($materialsChanged) {
                $locked->materials()->delete();
                foreach ($newMaterials as $material) $locked->materials()->create($material);
                $locked->operations()->delete();
                $this->ensureDefaultOperation($locked);
            }
            $locked->events()->create(['event_type' => 'updated', 'payload' => ['source' => 'MAKE_TO_STOCK'], 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.order.updated', $locked, $before, $locked->fresh(['materials'])->toArray(), $actor, $request);

            return $locked->fresh(['materials', 'finishedItem', 'uom']);
        }, 3);
    }

    public function deleteDraft(ProductionOrder $order, Warehouse $warehouse, User $actor, Request $request): void
    {
        DB::transaction(function () use ($order, $warehouse, $actor, $request): void {
            $locked = ProductionOrder::query()->with('materials')->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->branch_id !== (int) $warehouse->branch_id || $locked->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'ลบร่างได้เฉพาะ WO สถานะร่างในสาขาปัจจุบัน']);
            }
            $before = $locked->toArray();
            $locked->events()->create(['event_type' => 'deleted', 'payload' => ['document_number' => $locked->document_number], 'occurred_at' => now(), 'created_by' => $actor->id]);
            $locked->delete();
            $this->audit->record('production.order.deleted', $locked, $before, [], $actor, $request);
        }, 3);
    }

    public function createFromSalesOrderLine(int $lineId, Warehouse $warehouse, User $actor, Request $request, array $plan = []): ProductionOrder
    {
        $warehouse->loadMissing('branch');

        return DB::transaction(function () use ($lineId, $warehouse, $actor, $request, $plan): ProductionOrder {
            $orderId = SalesOrderLine::query()->whereKey($lineId)->value('sales_order_id');
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            $line = SalesOrderLine::query()
                ->with(['item:id,base_uom_id,item_type,is_active,is_stock_item,can_manufacture'])
                ->lockForUpdate()
                ->findOrFail($lineId);
            if ((int) $order->branch_id !== (int) $warehouse->branch_id || $order->status !== 'CONFIRMED') {
                throw ValidationException::withMessages(['sales_order_line_id' => 'สร้าง WO ได้เฉพาะรายการจากใบสั่งขายที่ยืนยันแล้วในสาขาปัจจุบัน']);
            }
            if (! $line->item || ! $line->item->is_active || $line->item->item_type !== 'GOODS' || ! $line->item->is_stock_item || ! $line->item->base_uom_id || (int) $line->uom_id !== (int) $line->item->base_uom_id) {
                throw ValidationException::withMessages(['sales_order_line_id' => 'MVP รองรับเฉพาะสินค้าคงคลังที่ใช้ Stock UOM ตรงกับ Sales Order']);
            }
            $existing = ProductionOrder::query()
                ->where('sales_order_line_id', $line->id)
                ->whereNot('status', 'CANCELLED')
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing->fresh(['materials', 'salesOrder']);
            }
            if (! $line->production_requested_at) {
                throw ValidationException::withMessages(['sales_order_line_id' => 'ฝ่ายขายยังไม่ได้ส่งคำขอสั่งผลิตรายการนี้']);
            }
            if (! $order->production_legacy_eligible && ! $line->item->can_manufacture) {
                throw ValidationException::withMessages(['sales_order_line_id' => 'สินค้านี้ไม่ได้เปิดให้สั่งผลิตจากใบสั่งขาย']);
            }
            if ($order->physicalSales()->where('status', '!=', 'VOID')->exists()) {
                throw ValidationException::withMessages(['sales_order_line_id' => 'ใบสั่งขายมี HS/IV ที่ยังไม่ยกเลิกแล้ว ไม่สามารถสร้าง WO ใหม่ได้']);
            }

            $activeQuantity = ProductionOrder::query()
                ->where('sales_order_line_id', $line->id)
                ->whereNot('status', 'CANCELLED')
                ->lockForUpdate()
                ->sum('planned_quantity');
            if (BigDecimal::of((string) $activeQuantity)->plus((string) $line->quantity)->isGreaterThan(BigDecimal::of((string) $line->quantity))) {
                throw ValidationException::withMessages(['sales_order_line_id' => 'ยอด WO ที่ยังไม่ยกเลิกเกินจำนวนใน Sales Order line']);
            }

            $revision = BomRevision::query()
                ->with(['bom', 'lines'])
                ->where('status', 'ACTIVE')
                ->whereHas('bom', fn ($q) => $q->where('branch_id', $warehouse->branch_id)->where('finished_item_id', $line->item_id)->where('base_uom_id', $line->uom_id)->where('is_active', true))
                ->when(isset($plan['bom_revision_id']), fn ($q) => $q->whereKey($plan['bom_revision_id']))
                ->orderBy('id')->lockForUpdate()
                ->first();
            if (! $revision) {
                throw ValidationException::withMessages(['bom_revision_id' => 'ไม่พบ Active BOM สำหรับสินค้านี้ในสาขาปัจจุบัน']);
            }
            if ($revision->lines->isEmpty()) {
                throw ValidationException::withMessages(['bom_revision_id' => 'Active BOM ต้องมีวัตถุดิบอย่างน้อยหนึ่งรายการ']);
            }
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PRODUCTION_ORDER')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบสั่งผลิต']);
            }
            $date = Carbon::today();
            $plannedStart = $plan['planned_start_date'] ?? max($date->format('Y-m-d'), $line->requested_start_date?->format('Y-m-d') ?? '');
            $this->assertPlanDates($plan, $plannedStart, $line->requested_start_date?->format('Y-m-d'));
            $productionOrder = ProductionOrder::query()->create([
                'branch_id' => $warehouse->branch_id,
                'issue_warehouse_id' => $warehouse->id,
                'receipt_warehouse_id' => $warehouse->id,
                'document_number' => $this->sequences->issueForBranch($sequence, $warehouse->branch, $date),
                'order_type' => 'MAKE_TO_ORDER',
                'status' => 'DRAFT',
                'sales_order_id' => $order->id,
                'sales_order_line_id' => $line->id,
                'source_revision' => ((int) ProductionOrder::withTrashed()->where('sales_order_line_id', $line->id)->max('source_revision')) + 1,
                'required_delivery_date' => $line->requested_delivery_date ?? $order->required_delivery_date,
                'required_delivery_at' => $plan['required_delivery_at'] ?? null,
                'customer_specification' => $line->production_specification ?: $line->description,
                'finished_item_id' => $line->item_id,
                'uom_id' => $line->uom_id,
                'planned_quantity' => $this->decimal($line->quantity),
                'bom_revision_id' => $revision->id,
                'planned_start_date' => $plannedStart,
                'planned_finish_date' => $plan['planned_finish_date'] ?? null,
                'planned_start_at' => $plan['planned_start_at'] ?? null,
                'planned_finish_at' => $plan['planned_finish_at'] ?? null,
                'notes' => $plan['notes'] ?? null,
                'responsible_user_id' => $actor->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->sequences->recordIssued($sequence->fresh(), $productionOrder->document_number, 'production_orders', $productionOrder->id, $date, $actor->id);
            foreach ($revision->lines as $index => $bomLine) {
                $productionOrder->materials()->create([
                    'line_number' => $index + 1,
                    'source_bom_line_id' => $bomLine->id,
                    'item_id' => $bomLine->component_item_id,
                    'uom_id' => $bomLine->uom_id,
                    'required_quantity' => $this->decimal(BigDecimal::of((string) $bomLine->quantity)->multipliedBy((string) $line->quantity)),
                ]);
            }
            $this->ensureDefaultOperation($productionOrder);
            $productionOrder->events()->create(['event_type' => 'created', 'source_type' => 'SALES_ORDER_LINE', 'source_id' => (string) $line->id, 'payload' => ['sales_order_id' => $order->id], 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.order.created', $productionOrder, [], $productionOrder->fresh(['materials'])->toArray(), $actor, $request);

            return $productionOrder->fresh(['materials', 'salesOrder']);
        }, 3);
    }

    public function materialReadiness(ProductionOrder $order): array
    {
        $order->loadMissing(['materials.item', 'materials.uom', 'events']);
        $warehouseId = (int) $order->issue_warehouse_id;
        $issueIds = $order->events->where('event_type', 'material_issue_created')->pluck('source_id')->filter()->map(fn ($id) => (int) $id)->values();
        $rows = $order->materials->map(function ($line) use ($warehouseId, $issueIds): array {
            $balance = $this->balances->forItem($warehouseId, (int) $line->item_id, (int) $line->uom_id);
            $available = BigDecimal::of((string) $balance['available']);
            $required = BigDecimal::of((string) $line->required_quantity);
            $reserved = $this->materialReservationQuantity((int) $line->production_order_id, (int) $line->id);
            $issued = $this->postedIssueQuantity($issueIds, (int) $line->item_id, (int) $line->uom_id);
            $returned = $this->postedReturnQuantity($issueIds, (int) $line->item_id, (int) $line->uom_id);
            $netIssued = $issued->minus($returned);
            $covered = $available->plus($reserved);
            $ready = ! $covered->isLessThan($required);
            $status = $this->materialReadinessStatus($required, $reserved, $available, $netIssued, $ready);

            return [
                'line_number' => (int) $line->line_number,
                'item_label' => trim(($line->item?->code ?? '').' · '.($line->item?->name ?? ''), ' ·'),
                'required_quantity' => $this->decimal($required),
                'reserved_quantity' => $this->decimal($reserved),
                'issued_quantity' => $this->decimal($issued),
                'returned_quantity' => $this->decimal($returned),
                'net_issued_quantity' => $this->decimal($netIssued),
                'available_quantity' => $this->decimal($available),
                'shortage_quantity' => $this->decimal($covered->isLessThan($required) ? $required->minus($covered) : BigDecimal::zero()),
                'uom_label' => $line->uom?->code ?? '',
                'ready' => $ready,
                'status' => $status,
            ];
        })->values()->all();

        return ['ready' => collect($rows)->every(fn (array $row): bool => in_array($row['status'], ['READY', 'ISSUED'], true)), 'rows' => $rows];
    }

    public function release(ProductionOrder $order, Warehouse $warehouse, User $actor, Request $request): ProductionOrder
    {
        return DB::transaction(function () use ($order, $warehouse, $actor, $request): ProductionOrder {
            $locked = ProductionOrder::query()->with('materials')->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->branch_id !== (int) $warehouse->branch_id) {
                throw ValidationException::withMessages(['branch_id' => 'WO ไม่อยู่ในสาขาปัจจุบัน']);
            }
            if ($locked->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'Release ได้เฉพาะ WO ร่าง']);
            }
            if ($locked->materials->isEmpty()) {
                throw ValidationException::withMessages(['materials' => 'WO ต้องมี Material snapshot ก่อน Release']);
            }
            $readiness = $this->materialReadiness($locked);
            $before = $locked->toArray();
            $locked->forceFill(['status' => 'RELEASED', 'released_at' => now(), 'released_by' => $actor->id, 'updated_by' => $actor->id])->save();
            $locked->events()->create(['event_type' => 'released', 'payload' => $readiness, 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.order.released', $locked, $before, $locked->fresh()->toArray(), $actor, $request);

            return $locked->fresh(['materials', 'salesOrder']);
        }, 3);
    }

    public function startFromShopFloor(ProductionOrder $order, Warehouse $warehouse, User $actor, Request $request): ProductionOrder
    {
        return DB::transaction(function () use ($order, $warehouse, $actor, $request): ProductionOrder {
            $locked = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->branch_id !== (int) $warehouse->branch_id || (int) $locked->issue_warehouse_id !== (int) $warehouse->id) {
                throw ValidationException::withMessages(['warehouse_id' => 'WO ไม่อยู่ในคลัง/สาขาปัจจุบัน']);
            }
            if ($locked->status !== 'IN_PROGRESS') throw ValidationException::withMessages(['status' => 'ยืนยันเริ่มงานได้เมื่อ WO กำลังผลิต']);
            if ($locked->held_at) throw ValidationException::withMessages(['status' => 'ต้องเปิดงานผลิตต่อก่อนยืนยันเริ่มงาน']);
            if ($locked->started_at) return $locked;
            $this->assertCustomerStartDate($locked);
            $issueIds = $locked->events()->where('event_type', 'material_issue_created')->pluck('source_id');
            if (! IssueDocument::query()->whereIn('id', $issueIds)->where('issue_type', 'PRODUCTION')->where('warehouse_id', $warehouse->id)->where('status', 'POSTED')->exists()) {
                throw ValidationException::withMessages(['material_issue' => 'ต้องลง Stock ใบเบิกวัตถุดิบก่อนยืนยันเริ่มงานผลิต']);
            }
            $locked->forceFill(['started_at' => now(), 'started_by' => $actor->id, 'updated_by' => $actor->id])->save();
            $locked->events()->create(['event_type' => 'started', 'payload' => ['source' => 'SHOP_FLOOR'], 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.order.started', $locked, [], $locked->fresh()->toArray(), $actor, $request);

            return $locked->fresh();
        }, 3);
    }

    public function reserveMaterials(ProductionOrder $order, Warehouse $warehouse, User $actor, Request $request, StockReservationService $reservations): ProductionOrder
    {
        return DB::transaction(function () use ($order, $warehouse, $actor, $request, $reservations): ProductionOrder {
            $locked = ProductionOrder::query()->with('materials')->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->branch_id !== (int) $warehouse->branch_id || (int) $locked->issue_warehouse_id !== (int) $warehouse->id) {
                throw ValidationException::withMessages(['warehouse_id' => 'WO ไม่อยู่ในคลัง/สาขาปัจจุบัน']);
            }
            if ($locked->status !== 'RELEASED') {
                throw ValidationException::withMessages(['status' => 'จองวัตถุดิบได้เฉพาะ WO ที่ Release แล้ว']);
            }
            $created = [];
            foreach ($locked->materials as $line) {
                $key = $this->materialReservationKey((int) $locked->id, (int) $line->id);
                $reservation = StockReservation::query()->where('idempotency_key', $key)->first();
                if ($reservation && $reservation->status === 'OPEN') {
                    continue;
                }
                $created[] = $reservations->reserve([
                    'warehouse_id' => $warehouse->id,
                    'item_id' => (int) $line->item_id,
                    'uom_id' => (int) $line->uom_id,
                    'quantity' => $this->decimal($line->required_quantity),
                    'source_type' => 'PRODUCTION_ORDER',
                    'source_id' => (string) $locked->id,
                    'idempotency_key' => $key,
                    'created_by' => $actor->id,
                ])->id;
            }
            $readiness = $this->materialReadiness($locked);
            $locked->events()->create(['event_type' => 'materials_reserved', 'payload' => ['reservation_ids' => $created, 'readiness' => $readiness], 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.order.materials_reserved', $locked, [], $readiness, $actor, $request);

            return $locked->fresh(['materials', 'salesOrder']);
        }, 3);
    }

    private function assertPlanDates(array $values, string $start, ?string $earliest = null): void
    {
        if ($earliest && $start < $earliest) throw ValidationException::withMessages(['planned_start_date' => 'วันเริ่มแผนต้องไม่ก่อนวันที่ลูกค้าระบุ']);
        if (isset($values['planned_finish_date']) && $values['planned_finish_date'] < $start) throw ValidationException::withMessages(['planned_finish_date' => 'วันจบแผนต้องไม่ก่อนวันเริ่มแผน']);
        if (isset($values['planned_start_at']) !== isset($values['planned_finish_at'])) throw ValidationException::withMessages(['planned_start_at' => 'ระบุเวลาเริ่มและจบแผนให้ครบทั้งคู่']);
        if (isset($values['planned_start_at'], $values['planned_finish_at']) && $values['planned_finish_at'] <= $values['planned_start_at']) throw ValidationException::withMessages(['planned_finish_at' => 'เวลาจบแผนต้องหลังเวลาเริ่มแผน']);
        if (isset($values['planned_start_at']) && substr($values['planned_start_at'], 0, 10) !== $start) throw ValidationException::withMessages(['planned_start_at' => 'วันในเวลาเริ่มต้องตรงกับวันเริ่มแผน']);
        if (isset($values['planned_finish_at']) && substr($values['planned_finish_at'], 0, 10) !== ($values['planned_finish_date'] ?? null)) throw ValidationException::withMessages(['planned_finish_at' => 'วันในเวลาจบต้องตรงกับวันจบแผน']);
    }

    private function assertCustomerStartDate(ProductionOrder $order): void
    {
        if ($order->order_type !== 'MAKE_TO_ORDER' || ! $order->sales_order_line_id) return;
        $earliest = SalesOrderLine::query()->whereKey($order->sales_order_line_id)->value('requested_start_date');
        if ($earliest && today()->toDateString() < (string) $earliest) {
            throw ValidationException::withMessages(['requested_start_date' => 'ลูกค้ากำหนดให้เริ่มผลิตได้ตั้งแต่วันที่ '.date('d/m/Y', strtotime((string) $earliest))]);
        }
    }

    private function selectedComponent($bomLine, array $values): array
    {
        $selectedId = $values['substitutes'][$bomLine->id] ?? null;
        $substitute = $selectedId ? $bomLine->substitutes->firstWhere('id', (int) $selectedId) : null;
        if ($selectedId && ! $substitute) throw ValidationException::withMessages(['substitutes' => 'วัตถุดิบทดแทนไม่ตรงกับ BOM Line']);
        return ['item_id' => $substitute?->substitute_item_id ?? $bomLine->component_item_id, 'uom_id' => $substitute?->uom_id ?? $bomLine->uom_id, 'factor' => (string) ($substitute?->quantity_factor ?? 1)];
    }

    private function ensureDefaultOperation(ProductionOrder $order): void
    {
        $revision = BomRevision::query()->with('operations')->find($order->bom_revision_id);
        if ($revision?->operations->isNotEmpty()) {
            foreach ($revision->operations as $operation) $order->operations()->create(['sequence' => $operation->sequence, 'name' => $operation->name, 'planned_minutes' => $operation->planned_minutes, 'status' => 'PENDING', 'notes' => $operation->notes]);
            return;
        }
    }

    public function addOperation(ProductionOrder $order, Warehouse $warehouse, User $actor, Request $request, string $name, ?int $plannedMinutes): ProductionOrderOperation
    {
        return DB::transaction(function () use ($order, $warehouse, $actor, $request, $name, $plannedMinutes): ProductionOrderOperation {
            $locked = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->branch_id !== (int) $warehouse->branch_id || (int) $locked->issue_warehouse_id !== (int) $warehouse->id || ! in_array($locked->status, ['DRAFT', 'RELEASED'], true)) throw ValidationException::withMessages(['status' => 'เพิ่มขั้นตอนได้เฉพาะ WO ที่ยังไม่เริ่มผลิต']);
            $sequence = ((int) $locked->operations()->max('sequence')) + 1;
            $operation = $locked->operations()->create(['sequence' => $sequence, 'name' => $name, 'planned_minutes' => $plannedMinutes, 'status' => 'PENDING']);
            $locked->events()->create(['event_type' => 'operation_created', 'source_type' => ProductionOrderOperation::class, 'source_id' => (string) $operation->id, 'payload' => ['name' => $name, 'sequence' => $sequence], 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.operation.created', $operation, [], $operation->toArray(), $actor, $request);
            return $operation;
        }, 3);
    }

    public function updateOperation(ProductionOrder $order, ProductionOrderOperation $operation, Warehouse $warehouse, User $actor, Request $request, string $name, ?int $plannedMinutes): ProductionOrderOperation
    {
        return DB::transaction(function () use ($order, $operation, $warehouse, $actor, $request, $name, $plannedMinutes): ProductionOrderOperation {
            $locked = ProductionOrderOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ((int) $locked->production_order_id !== (int) $order->id || (int) $order->branch_id !== (int) $warehouse->branch_id || (int) $order->issue_warehouse_id !== (int) $warehouse->id || $locked->status !== 'PENDING') throw ValidationException::withMessages(['operation' => 'แก้ไขได้เฉพาะ Operation ที่ยังไม่เริ่ม']);
            $locked->update(['name' => $name, 'planned_minutes' => $plannedMinutes]);
            $this->audit->record('production.operation.updated', $locked, [], $locked->fresh()->toArray(), $actor, $request);
            return $locked->fresh();
        }, 3);
    }

    public function deleteOperation(ProductionOrder $order, ProductionOrderOperation $operation, Warehouse $warehouse, User $actor, Request $request): void
    {
        DB::transaction(function () use ($order, $operation, $warehouse, $actor, $request): void {
            $locked = ProductionOrderOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ((int) $locked->production_order_id !== (int) $order->id || (int) $order->branch_id !== (int) $warehouse->branch_id || (int) $order->issue_warehouse_id !== (int) $warehouse->id || $locked->status !== 'PENDING') throw ValidationException::withMessages(['operation' => 'ลบได้เฉพาะ Operation ที่ยังไม่เริ่ม']);
            $before = $locked->toArray(); $locked->delete();
            $this->audit->record('production.operation.deleted', $order, $before, [], $actor, $request);
        }, 3);
    }

    public function startOperation(ProductionOrder $order, ProductionOrderOperation $operation, Warehouse $warehouse, User $actor, Request $request): ProductionOrderOperation
    {
        return DB::transaction(function () use ($order, $operation, $warehouse, $actor, $request): ProductionOrderOperation {
            $locked = ProductionOrderOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ((int) $locked->production_order_id !== (int) $order->id || (int) $order->branch_id !== (int) $warehouse->branch_id || (int) $order->issue_warehouse_id !== (int) $warehouse->id || $order->held_at || $order->status !== 'IN_PROGRESS') throw ValidationException::withMessages(['operation' => 'ไม่สามารถเริ่ม Operation นี้ได้']);
            if ($locked->status !== 'PENDING') throw ValidationException::withMessages(['operation' => 'เริ่มได้เฉพาะ Operation ที่รอดำเนินการ']);
            $this->assertCustomerStartDate($order);
            $locked->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'started_by' => $actor->id]);
            $order->events()->create(['event_type' => 'operation_started', 'source_type' => ProductionOrderOperation::class, 'source_id' => (string) $locked->id, 'payload' => ['name' => $locked->name, 'sequence' => $locked->sequence], 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.operation.started', $locked, [], $locked->fresh()->toArray(), $actor, $request);
            return $locked->fresh();
        }, 3);
    }

    public function completeOperation(ProductionOrder $order, ProductionOrderOperation $operation, Warehouse $warehouse, User $actor, Request $request): ProductionOrderOperation
    {
        return DB::transaction(function () use ($order, $operation, $warehouse, $actor, $request): ProductionOrderOperation {
            $locked = ProductionOrderOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ((int) $locked->production_order_id !== (int) $order->id || (int) $order->branch_id !== (int) $warehouse->branch_id || (int) $order->issue_warehouse_id !== (int) $warehouse->id || $order->held_at || $locked->status !== 'IN_PROGRESS') throw ValidationException::withMessages(['operation' => 'จบ Operation นี้ไม่ได้']);
            $locked->update(['status' => 'COMPLETED', 'completed_at' => now(), 'completed_by' => $actor->id]);
            $order->events()->create(['event_type' => 'operation_completed', 'source_type' => ProductionOrderOperation::class, 'source_id' => (string) $locked->id, 'payload' => ['name' => $locked->name, 'sequence' => $locked->sequence], 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.operation.completed', $locked, [], $locked->fresh()->toArray(), $actor, $request);
            return $locked->fresh();
        }, 3);
    }

    public function hold(ProductionOrder $order, Warehouse $warehouse, User $actor, Request $request, string $reason): ProductionOrder
    {
        return DB::transaction(function () use ($order, $warehouse, $actor, $request, $reason): ProductionOrder {
            $locked = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->branch_id !== (int) $warehouse->branch_id || (int) $locked->issue_warehouse_id !== (int) $warehouse->id) throw ValidationException::withMessages(['warehouse_id' => 'WO ไม่อยู่ในคลัง/สาขาปัจจุบัน']);
            if ($locked->status !== 'IN_PROGRESS' || $locked->held_at) throw ValidationException::withMessages(['status' => 'พักงานได้เฉพาะ WO ที่กำลังผลิตและยังไม่ถูกพัก']);
            $locked->forceFill(['held_at' => now(), 'held_by' => $actor->id, 'hold_reason' => $reason, 'updated_by' => $actor->id])->save();
            $locked->events()->create(['event_type' => 'held', 'payload' => ['reason' => $reason], 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.order.held', $locked, [], $locked->fresh()->toArray(), $actor, $request);
            return $locked->fresh();
        }, 3);
    }

    public function resume(ProductionOrder $order, Warehouse $warehouse, User $actor, Request $request): ProductionOrder
    {
        return DB::transaction(function () use ($order, $warehouse, $actor, $request): ProductionOrder {
            $locked = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->branch_id !== (int) $warehouse->branch_id || (int) $locked->issue_warehouse_id !== (int) $warehouse->id) throw ValidationException::withMessages(['warehouse_id' => 'WO ไม่อยู่ในคลัง/สาขาปัจจุบัน']);
            if ($locked->status !== 'IN_PROGRESS' || ! $locked->held_at) throw ValidationException::withMessages(['status' => 'เปิดงานต่อได้เฉพาะ WO ที่ถูกพัก']);
            $locked->forceFill(['held_at' => null, 'held_by' => null, 'hold_reason' => null, 'resumed_at' => now(), 'resumed_by' => $actor->id, 'updated_by' => $actor->id])->save();
            $locked->events()->create(['event_type' => 'resumed', 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.order.resumed', $locked, [], $locked->fresh()->toArray(), $actor, $request);
            return $locked->fresh();
        }, 3);
    }

    public function cancel(ProductionOrder $order, Warehouse $warehouse, User $actor, Request $request, StockReservationService $reservations): ProductionOrder
    {
        return DB::transaction(function () use ($order, $warehouse, $actor, $request, $reservations): ProductionOrder {
            $locked = ProductionOrder::query()->with('materials')->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->branch_id !== (int) $warehouse->branch_id) {
                throw ValidationException::withMessages(['branch_id' => 'WO ไม่อยู่ในสาขาปัจจุบัน']);
            }
            if (! in_array($locked->status, ['DRAFT', 'RELEASED'], true)) {
                throw ValidationException::withMessages(['status' => 'ยกเลิกได้เฉพาะ WO ร่างหรือ Release ที่ยังไม่มีเอกสารลง Stock']);
            }
            $posted = $locked->events()->where('event_type', 'material_issue_created')->pluck('source_id')->filter()->contains(fn ($id): bool => IssueDocument::query()->whereKey($id)->where('status', 'POSTED')->exists());
            if ($posted) {
                throw ValidationException::withMessages(['status' => 'ยกเลิก WO ไม่ได้เมื่อมีใบเบิกวัตถุดิบลง Stock แล้ว']);
            }
            $released = [];
            StockReservation::query()->where('source_type', 'PRODUCTION_ORDER')->where('source_id', (string) $locked->id)->where('status', 'OPEN')->lockForUpdate()->get()->each(function (StockReservation $reservation) use ($reservations, &$released): void {
                $released[] = $reservations->release($reservation)->id;
            });
            $before = $locked->toArray();
            $locked->forceFill(['status' => 'CANCELLED', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => (string) $request->input('reason', 'ยกเลิกใบสั่งผลิต'), 'updated_by' => $actor->id])->save();
            $locked->events()->create(['event_type' => 'cancelled', 'payload' => ['released_reservation_ids' => $released], 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.order.cancelled', $locked, $before, $locked->fresh()->toArray(), $actor, $request);

            return $locked->fresh(['materials', 'salesOrder']);
        }, 3);
    }

    public function reportNonRecoverableScrap(ProductionOrder $order, Warehouse $warehouse, User $actor, Request $request): ProductionOrderScrap
    {
        $values = $request->validate([
            'source_material_line_id' => ['nullable', 'integer'],
            'uom_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return DB::transaction(function () use ($order, $warehouse, $actor, $request, $values): ProductionOrderScrap {
            $locked = ProductionOrder::query()->with('materials')->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->branch_id !== (int) $warehouse->branch_id || (int) $locked->issue_warehouse_id !== (int) $warehouse->id) {
                throw ValidationException::withMessages(['warehouse_id' => 'WO ไม่อยู่ในคลัง/สาขาปัจจุบัน']);
            }
            if (! in_array($locked->status, ['RELEASED', 'IN_PROGRESS'], true)) {
                throw ValidationException::withMessages(['status' => 'บันทึกของเสียได้เฉพาะ WO ที่ Release หรือกำลังผลิต']);
            }
            if (! empty($values['source_material_line_id']) && ! $locked->materials->contains('id', (int) $values['source_material_line_id'])) {
                throw ValidationException::withMessages(['source_material_line_id' => 'Material line ไม่อยู่ใน WO นี้']);
            }
            $scrap = $locked->scraps()->create([
                'source_material_line_id' => $values['source_material_line_id'] ?? null,
                'scrap_type' => 'NON_RECOVERABLE_SCRAP',
                'uom_id' => (int) $values['uom_id'],
                'quantity' => $this->decimal($values['quantity']),
                'reason' => trim((string) $values['reason']),
                'status' => 'REPORTED',
                'reported_by' => $actor->id,
                'reported_at' => now(),
            ]);
            $locked->events()->create(['event_type' => 'non_recoverable_scrap_reported', 'source_type' => ProductionOrderScrap::class, 'source_id' => (string) $scrap->id, 'payload' => $scrap->toArray(), 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.order.non_recoverable_scrap_reported', $scrap, [], $scrap->toArray(), $actor, $request);

            return $scrap;
        }, 3);
    }

    public function createRecoverableScrapReceipt(ProductionOrder $order, Warehouse $warehouse, User $actor, Request $request): InventoryAdjustmentDocument
    {
        $values = $request->validate([
            'source_material_line_id' => ['nullable', 'integer'],
            'scrap_item_id' => ['required', 'integer', 'exists:wms_items,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'recovery_total_value' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        return DB::transaction(function () use ($order, $warehouse, $actor, $request, $values): InventoryAdjustmentDocument {
            $locked = ProductionOrder::query()->with('materials')->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->branch_id !== (int) $warehouse->branch_id || (int) $locked->issue_warehouse_id !== (int) $warehouse->id) {
                throw ValidationException::withMessages(['warehouse_id' => 'WO ไม่อยู่ในคลัง/สาขาปัจจุบัน']);
            }
            if (! in_array($locked->status, ['RELEASED', 'IN_PROGRESS'], true)) {
                throw ValidationException::withMessages(['status' => 'รับเศษผลิตได้เฉพาะ WO ที่ Release หรือกำลังผลิต']);
            }
            $scrapItem = Item::query()->whereKey($values['scrap_item_id'])->lockForUpdate()->first();
            if (! $scrapItem || ! $scrapItem->is_active || ! $scrapItem->is_stock_item || $scrapItem->item_type !== 'GOODS' || ! $scrapItem->can_receive_production_scrap || ! $scrapItem->base_uom_id) {
                throw ValidationException::withMessages(['scrap_item_id' => 'เลือกสินค้าเศษผลิตที่กำหนดไว้ในข้อมูลสินค้า']);
            }
            $scrapUomId = (int) $scrapItem->base_uom_id;
            if (! empty($values['source_material_line_id']) && ! $locked->materials->contains('id', (int) $values['source_material_line_id'])) {
                throw ValidationException::withMessages(['source_material_line_id' => 'Material line ไม่อยู่ใน WO นี้']);
            }
            $issueId = $locked->events()->where('event_type', 'material_issue_created')->latest('id')->value('source_id');
            $sourceIssue = $issueId ? IssueDocument::query()->where('issue_type', 'PRODUCTION')->where('status', 'POSTED')->find($issueId) : null;
            if (! $sourceIssue) {
                throw ValidationException::withMessages(['source_issue_id' => 'ต้องมีใบเบิกวัตถุดิบที่ลง Stock แล้วก่อนรับเศษผลิต']);
            }
            $wip = BigDecimal::of($this->wipSummary($sourceIssue)['available']);
            $value = BigDecimal::of((string) $values['recovery_total_value'])->toScale(8, RoundingMode::HALF_UP);
            if ($value->isGreaterThan($wip)) {
                throw ValidationException::withMessages(['recovery_total_value' => 'มูลค่ารับเศษสะสมต้องไม่เกิน Available WIP ของ WO']);
            }
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PRODUCTION_SCRAP_RECEIPT')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบรับเศษผลิต']);
            }
            $date = Carbon::today();
            $document = InventoryAdjustmentDocument::query()->create([
                'warehouse_id' => $warehouse->id,
                'document_number' => $this->sequences->issueForBranch($sequence, $warehouse->branch, $date),
                'document_date' => $date,
                'direction' => 'GAIN',
                'document_context' => 'PRODUCTION_SCRAP_RECEIPT',
                'source_issue_id' => $sourceIssue->id,
                'reason' => trim((string) $values['reason']),
                'idempotency_key' => 'production-scrap:'.bin2hex(random_bytes(12)),
                'created_by' => $actor->id,
            ]);
            $this->sequences->recordIssued($sequence->fresh(), $document->document_number, 'inventory_adjustment_document', $document->id, $date, $actor->id);
            InventoryAdjustment::query()->create([
                'document_id' => $document->id,
                'line_number' => 1,
                'warehouse_id' => $warehouse->id,
                'item_id' => (int) $values['scrap_item_id'],
                'uom_id' => $scrapUomId,
                'direction' => 'GAIN',
                'status' => 'DRAFT',
                'quantity' => $this->decimal($values['quantity']),
                'value' => $this->decimal($value),
                'business_date' => $date,
                'reason' => trim((string) $values['reason']),
                'idempotency_key' => 'production-scrap:'.$document->id.':line:1',
                'created_by' => $actor->id,
            ]);
            $scrap = $locked->scraps()->create([
                'source_material_line_id' => $values['source_material_line_id'] ?? null,
                'scrap_type' => 'RECOVERABLE_SCRAP',
                'scrap_item_id' => (int) $values['scrap_item_id'],
                'uom_id' => $scrapUomId,
                'quantity' => $this->decimal($values['quantity']),
                'recovery_total_value' => $this->decimal($value),
                'recovery_unit_value' => $this->decimal($value->dividedBy((string) $values['quantity'], 8, RoundingMode::HALF_UP)),
                'reason' => trim((string) $values['reason']),
                'status' => 'DRAFT',
                'reported_by' => $actor->id,
                'reported_at' => now(),
            ]);
            $locked->events()->create(['event_type' => 'recoverable_scrap_receipt_created', 'source_type' => InventoryAdjustmentDocument::class, 'source_id' => (string) $document->id, 'payload' => ['scrap_id' => $scrap->id, 'document_number' => $document->document_number], 'occurred_at' => now(), 'created_by' => $actor->id]);
            $this->audit->record('production.order.recoverable_scrap_receipt_created', $document, [], $document->fresh('lines')->toArray(), $actor, $request);

            return $document->fresh('lines');
        }, 3);
    }

    public function wipSummary(?IssueDocument $materialIssue): array
    {
        if (! $materialIssue || $materialIssue->status === 'VOID') {
            return ['issued' => '0.00000000', 'returned' => '0.00000000', 'finished' => '0.00000000', 'scrap' => '0.00000000', 'available' => '0.00000000'];
        }
        $issued = DB::table('wms_cost_allocations')
            ->join('wms_issue_lines', 'wms_issue_lines.stock_movement_id', '=', 'wms_cost_allocations.stock_movement_id')
            ->where('wms_issue_lines.document_id', $materialIssue->id)
            ->where('wms_cost_allocations.status', '!=', 'REVERSED')
            ->selectRaw('COALESCE(SUM(ABS(wms_cost_allocations.value)), 0) AS value')
            ->value('value');
        $returned = DB::table('wms_issue_return_line_allocations')
            ->join('wms_issue_return_lines', 'wms_issue_return_lines.id', '=', 'wms_issue_return_line_allocations.return_line_id')
            ->join('wms_issue_returns', 'wms_issue_returns.id', '=', 'wms_issue_return_lines.return_id')
            ->join('wms_cost_allocations', 'wms_cost_allocations.id', '=', 'wms_issue_return_line_allocations.cost_allocation_id')
            ->where('wms_issue_returns.issue_document_id', $materialIssue->id)
            ->where('wms_issue_returns.status', 'POSTED')
            ->where('wms_cost_allocations.status', '!=', 'REVERSED')
            ->selectRaw('COALESCE(SUM(ABS(wms_cost_allocations.value)), 0) AS value')
            ->value('value');
        $finished = DB::table('wms_inventory_adjustments')
            ->join('wms_inventory_adjustment_documents', 'wms_inventory_adjustment_documents.id', '=', 'wms_inventory_adjustments.document_id')
            ->where('wms_inventory_adjustment_documents.source_issue_id', $materialIssue->id)
            ->where('wms_inventory_adjustment_documents.document_context', 'PRODUCTION_RECEIPT')
            ->where('wms_inventory_adjustment_documents.status', 'POSTED')
            ->where('wms_inventory_adjustments.status', 'POSTED')
            ->selectRaw('COALESCE(SUM(wms_inventory_adjustments.value), 0) AS value')
            ->value('value');
        $scrap = DB::table('wms_inventory_adjustments')
            ->join('wms_inventory_adjustment_documents', 'wms_inventory_adjustment_documents.id', '=', 'wms_inventory_adjustments.document_id')
            ->where('wms_inventory_adjustment_documents.source_issue_id', $materialIssue->id)
            ->where('wms_inventory_adjustment_documents.document_context', 'PRODUCTION_SCRAP_RECEIPT')
            ->where('wms_inventory_adjustment_documents.status', 'POSTED')
            ->where('wms_inventory_adjustments.status', 'POSTED')
            ->selectRaw('COALESCE(SUM(wms_inventory_adjustments.value), 0) AS value')
            ->value('value');
        $issued = BigDecimal::of((string) $issued);
        $returned = BigDecimal::of((string) $returned);
        $finished = BigDecimal::of((string) $finished);
        $scrap = BigDecimal::of((string) $scrap);

        return [
            'issued' => $this->decimal($issued),
            'returned' => $this->decimal($returned),
            'finished' => $this->decimal($finished),
            'scrap' => $this->decimal($scrap),
            'available' => $this->decimal($issued->minus($returned)->minus($finished)->minus($scrap)),
        ];
    }

    public function createMaterialIssue(ProductionOrder $order, Warehouse $warehouse, User $actor, Request $request, IssueReturnService $issues): IssueDocument
    {
        return DB::transaction(function () use ($order, $warehouse, $actor, $request, $issues): IssueDocument {
            $locked = ProductionOrder::query()->with('materials')->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->branch_id !== (int) $warehouse->branch_id || (int) $locked->issue_warehouse_id !== (int) $warehouse->id) {
                throw ValidationException::withMessages(['warehouse_id' => 'WO ไม่อยู่ในคลัง/สาขาปัจจุบัน']);
            }
            if (! in_array($locked->status, ['RELEASED', 'IN_PROGRESS'], true) || ($locked->status === 'IN_PROGRESS' && ($locked->started_at || $locked->held_at))) {
                throw ValidationException::withMessages(['status' => 'สร้างใบเบิกได้เฉพาะ WO ที่พร้อมผลิต หรือกำลังผลิตแต่ยังไม่เริ่มงานและไม่ถูกพัก']);
            }
            $existingIds = $locked->events()->where('event_type', 'material_issue_created')->pluck('source_id')->filter()->all();
            if ($existingIds !== []) {
                $document = IssueDocument::query()
                    ->where('issue_type', 'PRODUCTION')
                    ->whereIn('status', ['DRAFT', 'APPROVED', 'POSTED'])
                    ->whereIn('id', $existingIds)
                    ->latest('id')
                    ->first();
                if ($document) return $document;
            }
            $readiness = $this->materialReadiness($locked);
            if (! $readiness['ready']) {
                throw ValidationException::withMessages(['materials' => 'วัตถุดิบไม่พอสำหรับสร้างใบเบิก']);
            }
            $document = $issues->createIssue([
                'document_date' => now()->toDateString(),
                'issue_type' => 'PRODUCTION',
                'reason' => 'WO '.$locked->document_number,
                'lines' => $locked->materials->map(fn ($line): array => [
                    'item_id' => (int) $line->item_id,
                    'uom_id' => (int) $line->uom_id,
                    'quantity' => $this->decimal($line->required_quantity),
                ])->values()->all(),
            ], $warehouse, $actor, $this->sequences, $this->audit, $request);
            $locked->events()->create(['event_type' => 'material_issue_created', 'source_type' => IssueDocument::class, 'source_id' => (string) $document->id, 'payload' => ['document_number' => $document->document_number], 'occurred_at' => now(), 'created_by' => $actor->id]);

            return $document;
        }, 3);
    }

    private function postedIssueQuantity(Collection $issueIds, int $itemId, int $uomId): BigDecimal
    {
        if ($issueIds->isEmpty()) return BigDecimal::zero();

        return BigDecimal::of((string) DB::table('wms_issue_lines')
            ->join('wms_issue_documents', 'wms_issue_documents.id', '=', 'wms_issue_lines.document_id')
            ->whereIn('wms_issue_documents.id', $issueIds)
            ->where('wms_issue_documents.status', 'POSTED')
            ->where('wms_issue_lines.item_id', $itemId)
            ->where('wms_issue_lines.uom_id', $uomId)
            ->whereNull('wms_issue_lines.deleted_at')
            ->sum('wms_issue_lines.quantity'));
    }

    private function postedReturnQuantity(Collection $issueIds, int $itemId, int $uomId): BigDecimal
    {
        if ($issueIds->isEmpty()) return BigDecimal::zero();

        return BigDecimal::of((string) DB::table('wms_issue_return_lines')
            ->join('wms_issue_returns', 'wms_issue_returns.id', '=', 'wms_issue_return_lines.return_id')
            ->join('wms_issue_lines', 'wms_issue_lines.id', '=', 'wms_issue_return_lines.issue_line_id')
            ->whereIn('wms_issue_returns.issue_document_id', $issueIds)
            ->where('wms_issue_returns.status', 'POSTED')
            ->where('wms_issue_lines.item_id', $itemId)
            ->where('wms_issue_lines.uom_id', $uomId)
            ->whereNull('wms_issue_return_lines.deleted_at')
            ->sum('wms_issue_return_lines.quantity'));
    }

    private function materialReadinessStatus(BigDecimal $required, BigDecimal $reserved, BigDecimal $available, BigDecimal $netIssued, bool $ready): string
    {
        if (! $netIssued->isLessThan($required)) return 'ISSUED';
        if ($netIssued->isPositive() || $reserved->isPositive()) return 'PARTIAL';
        if ($ready || ! $available->isLessThan($required)) return 'READY';

        return 'NOT_READY';
    }

    private function materialReservationQuantity(int $orderId, int $materialId): BigDecimal
    {
        $quantity = StockReservation::query()
            ->where('source_type', 'PRODUCTION_ORDER')
            ->where('source_id', (string) $orderId)
            ->where('idempotency_key', $this->materialReservationKey($orderId, $materialId))
            ->where('status', 'OPEN')
            ->selectRaw('COALESCE(SUM(quantity - consumed_quantity), 0) AS quantity')
            ->value('quantity');

        return BigDecimal::of((string) $quantity);
    }

    private function materialReservationKey(int $orderId, int $materialId): string
    {
        return "production-order:{$orderId}:material:{$materialId}:reservation";
    }

    private function decimal(mixed $value): string
    {
        return BigDecimal::of((string) $value)->toScale(8, RoundingMode::HALF_UP)->__toString();
    }
}
