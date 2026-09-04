<?php

namespace App\Modules\Purchasing\Services;

use App\Models\Party;
use App\Models\User;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequisition;
use App\Modules\Purchasing\Models\PurchaseRequisitionLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseRequisitionPurchaseOrderService
{
    public function createFromApproved(
        PurchaseRequisition $source,
        User $actor,
        Request $request,
        DocumentSequenceService $sequences,
        AuditLogger $audit,
        ?array $requestedQuantities = null,
        ?int $supplierId = null,
    ): PurchaseOrder {
        return DB::transaction(function () use ($source, $actor, $request, $sequences, $audit, $requestedQuantities, $supplierId): PurchaseOrder {
            $source = PurchaseRequisition::query()->with(['warehouse.branch', 'supplier', 'lines.item', 'lines.uom'])->lockForUpdate()->findOrFail($source->id);
            if ($source->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'สร้าง PO ได้เฉพาะใบขอซื้อที่อนุมัติแล้ว']);
            }
            $selectedSupplierId = $supplierId ?: (int) $source->supplier_id;
            $supplier = Party::query()->whereKey($selectedSupplierId)->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('role', 'SUPPLIER')->where('is_active', true))->first();
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'กรุณาเลือก Supplier ที่เปิดใช้งาน']);
            }

            $existing = PurchaseOrder::query()->where('purchase_requisition_id', $source->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->status === 'VOID') {
                    throw ValidationException::withMessages(['purchase_order' => 'ใบขอซื้อนี้เคยถูกสร้าง PO แล้วแต่ถูกยกเลิก ให้สร้างใบขอซื้อใหม่เพื่อรักษาประวัติ']);
                }

                return $existing->load('lines');
            }

            $lines = $source->lines;
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'ใบขอซื้อต้องมีรายการก่อนสร้าง PO']);
            }
            $orderLines = [];
            foreach ($lines as $line) {
                if (! $line->item || ! $line->item->is_active || ! $line->uom || ! $line->uom->is_active) {
                    throw ValidationException::withMessages(['lines' => 'สินค้า/หน่วยนับในใบขอซื้อไม่พร้อมใช้งานแล้ว ให้แก้ข้อมูลต้นทางก่อนสร้าง PO']);
                }
                $ordered = $this->remaining($line, $requestedQuantities[$line->id] ?? null);
                if ($ordered->isZero()) {
                    continue;
                }
                $orderLines[] = [
                    'purchase_requisition_line_id' => $line->id,
                    'line_number' => count($orderLines) + 1,
                    'item_id' => $line->item_id,
                    'uom_id' => $line->uom_id,
                    'description' => $line->description ?: 'จากใบขอซื้อ '.$source->document_number,
                    'quantity' => $ordered->toScale(4, RoundingMode::HALF_UP)->__toString(),
                    'unit_price' => '0.0000',
                    'line_total' => '0.00',
                ];
            }
            if ($orderLines === []) {
                throw ValidationException::withMessages(['lines' => 'ใบขอซื้อนี้ถูกสั่งซื้อครบแล้ว']);
            }

            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PURCHASE_ORDER')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร Purchase Order']);
            }
            if (! $source->warehouse?->branch) {
                throw ValidationException::withMessages(['warehouse_id' => 'คลังของใบขอซื้อไม่มีสาขา']);
            }
            $date = Carbon::parse($source->document_date);
            $number = $sequences->issueAvailableForBranch($sequence, $source->warehouse->branch, $date, fn (string $number): bool => PurchaseOrder::query()->where('document_number', $number)->exists());
            $po = PurchaseOrder::query()->create([
                'warehouse_id' => $source->warehouse_id,
                'purchase_requisition_id' => $source->id,
                'supplier_id' => $supplier->id,
                'supplier_code' => $supplier->code,
                'supplier_name' => $supplier->name,
                'document_number' => $number,
                'document_date' => $source->document_date,
                'subtotal' => '0.00',
                'total_amount' => '0.00',
                'description' => 'สร้างจากใบขอซื้อ '.$source->document_number,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $po->lines()->createMany($orderLines);
            $sequences->recordIssued($sequence, $number, 'purchase_orders', $po->id, $date, $actor->id);
            $audit->record('wms.purchase_order.created_from_requisition', $po, [], $po->fresh()->load('lines')->toArray(), $actor, $request);

            return $po->load('lines');
        }, 3);
    }

    private function remaining(PurchaseRequisitionLine $line, mixed $requested): BigDecimal
    {
        $ordered = PurchaseOrder::query()->whereHas('lines', fn ($query) => $query->where('purchase_requisition_line_id', $line->id))
            ->where('status', '!=', 'VOID')->with('lines')->get()->flatMap->lines
            ->reduce(fn (BigDecimal $sum, $poLine): BigDecimal => $sum->plus((string) $poLine->quantity), BigDecimal::zero());
        $remaining = BigDecimal::of((string) $line->quantity)->minus((string) $ordered);
        if ($remaining->isNegative()) {
            throw ValidationException::withMessages(['lines' => 'พบจำนวนที่สั่งซื้อแล้วมากกว่าจำนวนในใบขอซื้อ']);
        }
        $quantity = $requested === null || $requested === '' ? $remaining : BigDecimal::of((string) $requested);
        if ($quantity->isNegative() || $quantity->isGreaterThan($remaining)) {
            throw ValidationException::withMessages(['lines' => 'จำนวน PO ต้องไม่เกินจำนวนคงเหลือของใบขอซื้อ']);
        }

        return $quantity;
    }
}
