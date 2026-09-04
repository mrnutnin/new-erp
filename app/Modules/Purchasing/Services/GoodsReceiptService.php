<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Wms\Models\UomConversion;
use App\Modules\Wms\Support\GoodsReceiptConversionContract;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GoodsReceiptService
{
    public function __construct(private readonly DocumentSequenceService $sequences) {}

    public function createDraft(array $attributes, ?User $actor = null): GoodsReceipt
    {
        return DB::transaction(function () use ($attributes, $actor): GoodsReceipt {
            $key = trim((string) ($attributes['idempotency_key'] ?? ''));
            if ($key === '') {
                throw ValidationException::withMessages(['idempotency_key' => 'ต้องระบุ idempotency key']);
            }
            $existing = GoodsReceipt::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                $date = (string) ($attributes['business_date'] ?? '');
                $po = PurchaseOrder::query()->with('lines.item')->lockForUpdate()->findOrFail((int) ($attributes['purchase_order_id'] ?? 0));
                $this->assertCalendarDate($date);
                if ((int) $existing->purchase_order_id !== (int) $po->id || (int) $existing->warehouse_id !== (int) $po->warehouse_id || (string) $existing->business_date->format('Y-m-d') !== $date) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key นี้ถูกใช้กับ Receipt คนละชุดข้อมูล']);
                }
                $this->assertSameIdempotentPayload($existing->load('lines'), $po, $date, $attributes['lines'] ?? []);

                return $existing->load('lines');
            }
            $date = (string) ($attributes['business_date'] ?? '');
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail((int) ($attributes['purchase_order_id'] ?? 0));
            if ($po->status !== 'APPROVED') {
                throw ValidationException::withMessages(['purchase_order_id' => 'รับสินค้าได้เฉพาะ Purchase Order ที่อนุมัติแล้ว']);
            }
            $this->assertCalendarDate($date);
            if ($date < $po->document_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['business_date' => 'วันที่รับต้องเป็น Y-m-d และไม่ก่อนวันที่ PO']);
            }
            $lines = $attributes['lines'] ?? [];
            if (! is_array($lines) || count($lines) < 1) {
                throw ValidationException::withMessages(['lines' => 'ต้องมีรายการรับอย่างน้อย 1 รายการ']);
            }
            $warehouse = Warehouse::query()->with('branch')->findOrFail($po->warehouse_id);
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'GOODS_RECEIPT')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence || ! $warehouse->branch) {
                throw ValidationException::withMessages(['receipt_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารรับสินค้าสำหรับสาขานี้']);
            }
            $receiptNumber = $this->sequences->issueAvailableForBranch($sequence, $warehouse->branch, CarbonImmutable::parse($date), fn (string $number): bool => GoodsReceipt::query()->where('receipt_number', $number)->exists());
            $receipt = GoodsReceipt::create(['warehouse_id' => $po->warehouse_id, 'purchase_order_id' => $po->id, 'supplier_id' => $po->supplier_id, 'receipt_number' => $receiptNumber, 'idempotency_key' => $key, 'business_date' => $date, 'status' => 'DRAFT', 'description' => $attributes['description'] ?? null, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
            $this->sequences->recordIssued($sequence, $receiptNumber, 'goods_receipts', $receipt->id, CarbonImmutable::parse($date), $actor?->id);
            $lineIds = [];
            foreach ($lines as $lineInput) {
                $line = PurchaseOrderLine::query()->lockForUpdate()->findOrFail((int) ($lineInput['purchase_order_line_id'] ?? 0));
                if (in_array((int) $line->id, $lineIds, true)) {
                    throw ValidationException::withMessages(['lines' => 'PO line ซ้ำใน Receipt เดียวกัน']);
                }
                $lineIds[] = (int) $line->id;
                if ((int) $line->purchase_order_id !== (int) $po->id || ! $line->item_id || ! $line->uom_id) {
                    throw ValidationException::withMessages(['lines' => 'รายการต้องอยู่ใน PO เดียวกันและต้องผูก Item/UOM']);
                }
                $received = DB::table('goods_receipt_lines')->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')->where('goods_receipts.purchase_order_id', $po->id)->where('goods_receipts.status', '!=', 'VOID')->where('goods_receipt_lines.purchase_order_line_id', $line->id)->sum('goods_receipt_lines.purchase_quantity');
                $quantity = BigDecimal::of((string) ($lineInput['purchase_qty'] ?? '0'));
                if ($quantity->isLessThanOrEqualTo(0) || $quantity->plus((string) $received)->isGreaterThan(BigDecimal::of((string) $line->quantity))) {
                    throw ValidationException::withMessages(['lines' => "จำนวนรับเกินคงเหลือของ PO line #{$line->line_number}"]);
                }
                $item = $line->item()->firstOrFail();
                $stockUomId = (int) ($item->base_uom_id ?: $line->uom_id);
                $candidates = UomConversion::query()->where('from_uom_id', $line->uom_id)->where('to_uom_id', $stockUomId)->where('effective_from', '<=', $date)->where(function ($query) use ($date): void {
                    $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
                })->get()->map(fn ($c) => ['id' => $c->id, 'from_uom_id' => $c->from_uom_id, 'to_uom_id' => $c->to_uom_id, 'factor' => $c->factor, 'is_active' => true, 'effective_from' => $c->effective_from?->format('Y-m-d'), 'effective_to' => $c->effective_to?->format('Y-m-d')])->all();
                $resolved = GoodsReceiptConversionContract::resolve(['purchase_qty' => $quantity->__toString(), 'purchase_uom_id' => $line->uom_id, 'stock_uom_id' => $stockUomId, 'business_date' => $date, 'total_cost' => $lineInput['total_cost'] ?? '0', 'conversion_candidates' => $candidates]);
                $receipt->lines()->create(['purchase_order_line_id' => $line->id, 'item_id' => $line->item_id, 'purchase_uom_id' => $line->uom_id, 'stock_uom_id' => $stockUomId, 'purchase_quantity' => $resolved['purchase_quantity'], 'factor' => $resolved['snapshot']['factor'], 'stock_quantity' => $resolved['stock_quantity'], 'total_cost' => $resolved['total_cost'], 'stock_unit_cost' => $resolved['stock_unit_cost'], 'rounding_delta' => $resolved['rounding_delta'], 'conversion_snapshot' => $resolved['snapshot']]);
            }

            return $receipt->load('lines');
        }, 3);
    }

    public function approve(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $actor): GoodsReceipt {
            $receipt = GoodsReceipt::query()->with('lines')->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'อนุมัติได้เฉพาะ Draft Receipt']);
            }
            $receipt->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_by' => $actor->id]);

            return $receipt->fresh('lines');
        }, 3);
    }

    public function updateDraft(GoodsReceipt $receipt, array $attributes, ?User $actor = null): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $attributes, $actor): GoodsReceipt {
            $receipt = GoodsReceipt::query()->with('lines')->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'แก้ไขได้เฉพาะ Draft Receipt']);
            }
            $date = (string) ($attributes['business_date'] ?? '');
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($receipt->purchase_order_id);
            $this->assertCalendarDate($date);
            if ($date < $po->document_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['business_date' => 'วันที่รับต้องเป็น Y-m-d และไม่ก่อนวันที่ PO']);
            }
            $lines = $attributes['lines'] ?? [];
            if (! is_array($lines) || count($lines) < 1) {
                throw ValidationException::withMessages(['lines' => 'ต้องมีรายการรับอย่างน้อย 1 รายการ']);
            }
            $receipt->lines()->delete();
            $lineIds = [];
            foreach ($lines as $lineInput) {
                $line = PurchaseOrderLine::query()->lockForUpdate()->findOrFail((int) ($lineInput['purchase_order_line_id'] ?? 0));
                if (in_array((int) $line->id, $lineIds, true) || (int) $line->purchase_order_id !== (int) $po->id || ! $line->item_id || ! $line->uom_id) {
                    throw ValidationException::withMessages(['lines' => 'รายการต้องอยู่ใน PO เดียวกัน ไม่ซ้ำ และต้องผูก Item/UOM']);
                }
                $lineIds[] = (int) $line->id;
                $received = DB::table('goods_receipt_lines')->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')->where('goods_receipts.purchase_order_id', $po->id)->where('goods_receipts.id', '!=', $receipt->id)->where('goods_receipts.status', '!=', 'VOID')->where('goods_receipt_lines.purchase_order_line_id', $line->id)->sum('goods_receipt_lines.purchase_quantity');
                $quantity = BigDecimal::of((string) ($lineInput['purchase_qty'] ?? '0'));
                if ($quantity->isLessThanOrEqualTo(0) || $quantity->plus((string) $received)->isGreaterThan(BigDecimal::of((string) $line->quantity))) {
                    throw ValidationException::withMessages(['lines' => "จำนวนรับเกินคงเหลือของ PO line #{$line->line_number}"]);
                }
                $item = $line->item()->firstOrFail();
                $stockUomId = (int) ($item->base_uom_id ?: $line->uom_id);
                $candidates = UomConversion::query()->where('from_uom_id', $line->uom_id)->where('to_uom_id', $stockUomId)->where('effective_from', '<=', $date)->where(function ($query) use ($date): void {
                    $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
                })->get()->map(fn ($c) => ['id' => $c->id, 'from_uom_id' => $c->from_uom_id, 'to_uom_id' => $c->to_uom_id, 'factor' => $c->factor, 'is_active' => true, 'effective_from' => $c->effective_from?->format('Y-m-d'), 'effective_to' => $c->effective_to?->format('Y-m-d')])->all();
                $resolved = GoodsReceiptConversionContract::resolve(['purchase_qty' => $quantity->__toString(), 'purchase_uom_id' => $line->uom_id, 'stock_uom_id' => $stockUomId, 'business_date' => $date, 'total_cost' => $lineInput['total_cost'] ?? '0', 'conversion_candidates' => $candidates]);
                $receipt->lines()->create(['purchase_order_line_id' => $line->id, 'item_id' => $line->item_id, 'purchase_uom_id' => $line->uom_id, 'stock_uom_id' => $stockUomId, 'purchase_quantity' => $resolved['purchase_quantity'], 'factor' => $resolved['snapshot']['factor'], 'stock_quantity' => $resolved['stock_quantity'], 'total_cost' => $resolved['total_cost'], 'stock_unit_cost' => $resolved['stock_unit_cost'], 'rounding_delta' => $resolved['rounding_delta'], 'conversion_snapshot' => $resolved['snapshot']]);
            }
            $receipt->update(['business_date' => $date, 'description' => $attributes['description'] ?? null, 'updated_by' => $actor?->id]);

            return $receipt->fresh('lines');
        }, 3);
    }

    public function void(GoodsReceipt $receipt, string $reason, User $actor): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $reason, $actor): GoodsReceipt {
            $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if (! in_array($receipt->status, ['DRAFT', 'APPROVED'], true)) {
                throw ValidationException::withMessages(['status' => 'ยกเลิกได้เฉพาะ Draft หรือ Approved Receipt']);
            }
            $hasActivePurchaseDocument = DB::table('purchase_document_receipt_allocations as allocations')
                ->join('purchase_document_lines as document_lines', 'document_lines.id', '=', 'allocations.purchase_document_line_id')
                ->join('purchase_documents as documents', 'documents.id', '=', 'document_lines.purchase_document_id')
                ->join('goods_receipt_lines as receipt_lines', 'receipt_lines.id', '=', 'allocations.goods_receipt_line_id')
                ->where('receipt_lines.goods_receipt_id', $receipt->id)
                ->where('documents.status', '!=', 'VOID')
                ->exists();
            if ($hasActivePurchaseDocument) {
                throw ValidationException::withMessages(['status' => 'ยกเลิก Receipt ไม่ได้ เพราะถูกนำไปตั้งหนี้แล้ว กรุณายกเลิกหรือลบเอกสารตั้งหนี้ก่อน']);
            }
            if (mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร']);
            }
            $receipt->update(['status' => 'VOID', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => trim($reason), 'updated_by' => $actor->id]);

            return $receipt->fresh();
        }, 3);
    }

    private function assertCalendarDate(string $date): void
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['business_date' => 'วันที่รับต้องเป็นวันที่จริงในรูปแบบ Y-m-d']);
        }
    }

    private function assertSameIdempotentPayload(GoodsReceipt $existing, PurchaseOrder $po, string $date, mixed $lines): void
    {
        if (! is_array($lines) || count($lines) < 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key นี้ถูกใช้กับ Receipt คนละชุดข้อมูล']);
        }

        $actual = collect($lines)->map(function (mixed $lineInput) use ($po, $date): array {
            $line = PurchaseOrderLine::query()->with('item')->findOrFail((int) ($lineInput['purchase_order_line_id'] ?? 0));
            if ((int) $line->purchase_order_id !== (int) $po->id) {
                throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key นี้ถูกใช้กับ Receipt คนละชุดข้อมูล']);
            }
            $quantity = BigDecimal::of((string) ($lineInput['purchase_qty'] ?? '0'));
            $stockUomId = (int) ($line->item->base_uom_id ?: $line->uom_id);
            $candidates = UomConversion::query()->where('from_uom_id', $line->uom_id)->where('to_uom_id', $stockUomId)->where('effective_from', '<=', $date)->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })->get()->map(fn ($conversion) => ['id' => $conversion->id, 'from_uom_id' => $conversion->from_uom_id, 'to_uom_id' => $conversion->to_uom_id, 'factor' => $conversion->factor, 'is_active' => true, 'effective_from' => $conversion->effective_from?->format('Y-m-d'), 'effective_to' => $conversion->effective_to?->format('Y-m-d')])->all();
            $resolved = GoodsReceiptConversionContract::resolve(['purchase_qty' => $quantity->__toString(), 'purchase_uom_id' => $line->uom_id, 'stock_uom_id' => $stockUomId, 'business_date' => $date, 'total_cost' => $lineInput['total_cost'] ?? '0', 'conversion_candidates' => $candidates]);

            return ['purchase_order_line_id' => (int) $line->id, 'purchase_uom_id' => (int) $line->uom_id, 'stock_uom_id' => $stockUomId, 'purchase_quantity' => $resolved['purchase_quantity'], 'stock_quantity' => $resolved['stock_quantity'], 'factor' => $resolved['snapshot']['factor'], 'total_cost' => $resolved['total_cost'], 'stock_unit_cost' => $resolved['stock_unit_cost'], 'rounding_delta' => $resolved['rounding_delta']];
        })->sortBy('purchase_order_line_id')->values()->all();

        $expected = $existing->lines->map(fn ($line): array => ['purchase_order_line_id' => (int) $line->purchase_order_line_id, 'purchase_uom_id' => (int) $line->purchase_uom_id, 'stock_uom_id' => (int) $line->stock_uom_id, 'purchase_quantity' => (string) $line->purchase_quantity, 'stock_quantity' => (string) $line->stock_quantity, 'factor' => (string) $line->factor, 'total_cost' => (string) $line->total_cost, 'stock_unit_cost' => (string) $line->stock_unit_cost, 'rounding_delta' => (string) $line->rounding_delta])->sortBy('purchase_order_line_id')->values()->all();
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key นี้ถูกใช้กับ Receipt คนละชุดข้อมูล']);
        }
    }
}
