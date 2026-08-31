<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Party;
use App\Models\Warehouse;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\GoodsReceipt;
use App\Modules\Wms\Models\PurchaseOrder;
use App\Modules\Wms\Models\PurchaseRequisition;
use App\Modules\Wms\Requests\SavePurchaseOrderRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PurchaseOrderController extends Controller
{
    protected function modulePermission(string $permission): string
    {
        return $this->moduleRoutePrefix().'.'.$permission;
    }

    protected function moduleRoutePrefix(): string
    {
        return 'wms';
    }

    protected function moduleViewPrefix(): string
    {
        return 'Wms';
    }

    public function index(): View
    {
        return view($this->moduleViewPrefix().'::purchase-orders.index', ['moduleRoutePrefix' => $this->moduleRoutePrefix()]);
    }

    public function data(Request $request): JsonResponse
    {
        $scopeId = $this->purchasingScopeId($request);
        $format = (string) app(GlobalSettings::class)->value('date_format');
        $query = PurchaseOrder::query()->where($this->purchasingScopeColumn(), $scopeId)->whereIn('warehouse_id', $this->authorizedWarehouseIds($request))->with(['supplier', 'purchaseRequisition'])
            ->when($request->filled('document_date_from'), fn (Builder $q) => $q->whereDate('document_date', '>=', $request->input('document_date_from')))
            ->when($request->filled('document_date_to'), fn (Builder $q) => $q->whereDate('document_date', '<=', $request->input('document_date_to')))
            ->when($request->filled('expected_date_from'), fn (Builder $q) => $q->whereDate('expected_date', '>=', $request->input('expected_date_from')))
            ->when($request->filled('expected_date_to'), fn (Builder $q) => $q->whereDate('expected_date', '<=', $request->input('expected_date_to')))
            ->when($request->filled('supplier_id'), fn (Builder $q) => $q->where('supplier_id', (int) $request->input('supplier_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')));

        return DataTables::eloquent($query)
            ->addColumn('supplier_label', fn (PurchaseOrder $po) => $po->supplier_code.' · '.$po->supplier_name)
            ->addColumn('purchase_requisition_label', fn (PurchaseOrder $po) => $po->purchaseRequisition?->document_number ?: 'สร้างโดยตรง')
            ->addColumn('purchase_requisition_url', fn (PurchaseOrder $po) => $po->purchaseRequisition ? route($this->moduleRoutePrefix().'.purchase-requisitions.edit', $po->purchaseRequisition) : null)
            ->addColumn('document_date_label', fn (PurchaseOrder $po) => $po->document_date->format($format))
            ->addColumn('expected_date_label', fn (PurchaseOrder $po) => $po->expected_date?->format($format) ?? '—')
            ->addColumn('status_label', fn (PurchaseOrder $po) => ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'VOID' => 'ยกเลิก'][$po->status])
            ->addColumn('show_url', fn (PurchaseOrder $po) => route($this->moduleRoutePrefix().'.purchase-orders.show', $po))
            ->addColumn('print_url', fn (PurchaseOrder $po) => $request->user()->hasPermission($this->modulePermission('purchase-orders.print')) ? route($this->moduleRoutePrefix().'.purchase-orders.pdf', $po) : null)
            ->addColumn('approve_url', fn (PurchaseOrder $po) => $po->status === 'DRAFT' && $request->user()->hasPermission($this->modulePermission('purchase-orders.approve')) ? route($this->moduleRoutePrefix().'.purchase-orders.approve', $po) : null)
            ->addColumn('void_url', fn (PurchaseOrder $po) => in_array($po->status, ['DRAFT', 'APPROVED'], true) && $request->user()->hasPermission($this->modulePermission('purchase-orders.void')) ? route($this->moduleRoutePrefix().'.purchase-orders.void', $po) : null)
            ->addColumn('delete_url', fn (PurchaseOrder $po) => $po->status === 'DRAFT' && $request->user()->hasPermission($this->modulePermission('purchase-orders.delete')) ? route($this->moduleRoutePrefix().'.purchase-orders.destroy', $po) : null)
            ->toJson();
    }

    public function create(Request $request): View
    {
        $requisition = null;
        if ($request->filled('purchase_requisition_id')) {
            $requisition = PurchaseRequisition::query()->with(['lines.item', 'lines.uom', 'supplier'])->findOrFail((int) $request->input('purchase_requisition_id'));
            abort_unless((int) $requisition->{$this->purchasingScopeColumn()} === $this->purchasingScopeId($request) && in_array((int) $requisition->warehouse_id, $this->authorizedWarehouseIds($request), true), 404);
            abort_unless($requisition->status === 'APPROVED' && ! $requisition->purchaseOrder()->exists(), 422);
        }

        return view($this->moduleViewPrefix().'::purchase-orders.form', [
            'order' => null,
            'requisition' => $requisition,
            'terms' => PaymentTerm::query()->where('is_active', true)->orderBy('code')->get(),
            'warehouse' => $requisition?->warehouse ?? ($this->moduleRoutePrefix() === 'purchasing' ? null : $request->attributes->get('selectedWarehouse')),
            'warehouses' => $this->availableWarehouses($request),
            'moduleRoutePrefix' => $this->moduleRoutePrefix(),
        ]);
    }

    public function edit(Request $request, PurchaseOrder $purchaseOrder): View
    {
        $order = $this->scoped($request, $purchaseOrder)->load(['supplier', 'paymentTerm', 'purchaseRequisition', 'lines.item', 'lines.uom']);
        abort_unless($order->status === 'DRAFT', 404);

        return view($this->moduleViewPrefix().'::purchase-orders.form', [
            'order' => $order,
            'terms' => PaymentTerm::query()->where('is_active', true)->orderBy('code')->get(),
            'warehouse' => $order->warehouse,
            'warehouses' => $this->availableWarehouses($request),
            'moduleRoutePrefix' => $this->moduleRoutePrefix(),
        ]);
    }

    public function store(SavePurchaseOrderRequest $request, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $values = $request->validated();
        $warehouse = $request->attributes->get('selectedWarehouse');
        $requisition = null;
        if (! empty($values['purchase_requisition_id'])) {
            $requisition = PurchaseRequisition::query()->with(['lines.item', 'lines.uom'])->where($this->purchasingScopeColumn(), $this->purchasingScopeId($request))->whereIn('warehouse_id', $this->authorizedWarehouseIds($request))->findOrFail((int) $values['purchase_requisition_id']);
            if ($this->moduleRoutePrefix() === 'purchasing') {
                $warehouse = $request->user()->warehouses()->whereKey($requisition->warehouse_id)->where('is_active', true)->firstOrFail();
            }
            if ($requisition->status !== 'APPROVED' || $requisition->purchaseOrder()->exists()) {
                throw ValidationException::withMessages(['purchase_requisition_id' => 'ใบขอซื้อนี้ไม่พร้อมสร้าง PO หรือถูกสร้าง PO แล้ว']);
            }
            $allowed = $requisition->lines->keyBy('id');
            foreach ($values['lines'] as $line) {
                $sourceLine = $allowed->get((int) ($line['purchase_requisition_line_id'] ?? 0));
                if (! $sourceLine || (int) $sourceLine->item_id !== (int) ($line['item_id'] ?? 0) || (int) $sourceLine->uom_id !== (int) ($line['uom_id'] ?? 0)) {
                    throw ValidationException::withMessages(['lines' => 'รายการ PO ต้องอ้างอิงรายการเดิมจาก PR']);
                }
                if ((string) $sourceLine->quantity !== (string) $line['quantity']) {
                    throw ValidationException::withMessages(['lines' => 'จำนวนใน PO ต้องตรงกับจำนวนที่อนุมัติใน PR']);
                }
            }
        } elseif ($this->moduleRoutePrefix() === 'purchasing') {
            $warehouse = $this->purchasingWarehouse($request, $values['warehouse_id'] ?? null);
        }
        $warehouse->loadMissing('branch');
        if (! $warehouse->branch) {
            throw ValidationException::withMessages(['warehouse_id' => 'คลังที่เลือกไม่มีสาขา']);
        }
        $supplier = Party::query()->whereKey($values['supplier_id'])->where('is_active', true)->whereHas('roles', fn (Builder $q) => $q->where('role', 'SUPPLIER')->where('is_active', true))->first();
        if (! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Supplier ไม่ถูกต้องหรือไม่ได้เปิดใช้งาน']);
        }
        $term = ! empty($values['payment_term_id']) ? PaymentTerm::query()->whereKey($values['payment_term_id'])->where('is_active', true)->first() : null;
        if (! $term && ! empty($values['payment_term_id'])) {
            throw ValidationException::withMessages(['payment_term_id' => 'เงื่อนไขการชำระเงินไม่ถูกต้อง']);
        }
        $lines = collect($values['lines'])->values()->map(function (array $line, int $index): array {
            $quantity = (float) $line['quantity'];
            $price = (float) $line['unit_price'];

            return [...$line, 'line_number' => $index + 1, 'line_total' => number_format(round($quantity * $price, 2), 2, '.', '')];
        });
        $subtotal = $lines->sum(fn (array $line) => (float) $line['line_total']);
        $po = DB::transaction(function () use ($request, $warehouse, $supplier, $term, $values, $lines, $subtotal, $sequences, $audit, $requisition): PurchaseOrder {
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PURCHASE_ORDER')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร Purchase Order']);
            }
            $date = Carbon::createFromFormat('Y-m-d', $values['document_date']);
            $number = $sequences->issueAvailableForBranch($sequence, $warehouse->branch, $date, fn (string $number): bool => PurchaseOrder::query()->where('document_number', $number)->exists());
            $po = PurchaseOrder::create(['warehouse_id' => $warehouse->id, 'purchase_requisition_id' => $requisition?->id, 'supplier_id' => $supplier->id, 'supplier_code' => $supplier->code, 'supplier_name' => $supplier->name, 'payment_term_id' => $term?->id, 'document_number' => $number, 'document_date' => $values['document_date'], 'expected_date' => $values['expected_date'] ?? null, 'subtotal' => number_format($subtotal, 2, '.', ''), 'total_amount' => number_format($subtotal, 2, '.', ''), 'description' => $values['description'] ?? null, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $po->lines()->createMany($lines->all());
            $sequences->recordIssued($sequence, $number, 'purchase_orders', $po->id, $date, $request->user()->id);
            $audit->record('wms.purchase_order.created', $po, [], $po->toArray(), $request->user(), $request);

            return $po;
        }, 3);

        return response()->json(['status' => true, 'msg' => "สร้างร่าง Purchase Order {$po->document_number} แล้ว", 'redirect' => route($this->moduleRoutePrefix().'.purchase-orders.show', $po)]);
    }

    public function update(SavePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse
    {
        $po = $this->scoped($request, $purchaseOrder)->load(['lines', 'purchaseRequisition.lines']);
        if ($po->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'แก้ไขได้เฉพาะร่าง Purchase Order']);
        }
        $values = $request->validated();
        $supplier = Party::query()->whereKey($values['supplier_id'])->where('is_active', true)->whereHas('roles', fn (Builder $q) => $q->where('role', 'SUPPLIER')->where('is_active', true))->first();
        if (! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Supplier ไม่ถูกต้องหรือไม่ได้เปิดใช้งาน']);
        }
        $term = ! empty($values['payment_term_id']) ? PaymentTerm::query()->whereKey($values['payment_term_id'])->where('is_active', true)->first() : null;
        if (! $term && ! empty($values['payment_term_id'])) {
            throw ValidationException::withMessages(['payment_term_id' => 'เงื่อนไขการชำระเงินไม่ถูกต้อง']);
        }
        $lines = collect($values['lines'])->values()->map(function (array $line, int $index): array {
            $quantity = (float) $line['quantity'];
            $price = (float) $line['unit_price'];

            return [...$line, 'line_number' => $index + 1, 'line_total' => number_format(round($quantity * $price, 2), 2, '.', '')];
        });
        if ($po->purchase_requisition_id) {
            $expected = $po->lines->pluck('purchase_requisition_line_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $actual = $lines->pluck('purchase_requisition_line_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($expected !== $actual) {
                throw ValidationException::withMessages(['lines' => 'PO ที่สร้างจาก PR ต้องคงรายการและการเชื่อมโยงเดิม']);
            }
        }
        $subtotal = $lines->sum(fn (array $line) => (float) $line['line_total']);
        DB::transaction(function () use ($request, $po, $supplier, $term, $values, $lines, $subtotal, $audit, $sequences): void {
            $old = $po->toArray();
            $number = $po->document_number;
            if ($po->document_date->toDateString() !== $values['document_date']) {
                $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PURCHASE_ORDER')->where('is_active', true)->lockForUpdate()->first();
                if (! $sequence) {
                    throw ValidationException::withMessages(['document_date' => 'ยังไม่ได้ตั้งค่าเลขเอกสารสำหรับวันที่ใหม่']);
                }
                $warehouse = Warehouse::query()->with('branch')->findOrFail($po->warehouse_id);
                if (! $warehouse->branch) {
                    throw ValidationException::withMessages(['warehouse_id' => 'คลังของเอกสารไม่มีสาขา']);
                }
                $number = $sequences->replaceDraftNumberForBranch($sequence, $warehouse->branch, $po->document_number, 'purchase_orders', (int) $po->id, Carbon::parse($values['document_date']), $request->user()->id);
            }
            $po->update(['supplier_id' => $supplier->id, 'supplier_code' => $supplier->code, 'supplier_name' => $supplier->name, 'payment_term_id' => $term?->id, 'document_number' => $number, 'document_date' => $values['document_date'], 'expected_date' => $values['expected_date'] ?? null, 'subtotal' => number_format($subtotal, 2, '.', ''), 'total_amount' => number_format($subtotal, 2, '.', ''), 'description' => $values['description'] ?? null, 'updated_by' => $request->user()->id]);
            $po->lines()->delete();
            $po->lines()->createMany($lines->all());
            $audit->record('wms.purchase_order.updated', $po, $old, $po->fresh()->load('lines')->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => "แก้ไขร่าง Purchase Order {$po->document_number} แล้ว", 'redirect' => route($this->moduleRoutePrefix().'.purchase-orders.show', $po)]);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): View
    {
        abort_unless((int) $purchaseOrder->{$this->purchasingScopeColumn()} === $this->purchasingScopeId($request) && in_array((int) $purchaseOrder->warehouse_id, $this->authorizedWarehouseIds($request), true), 404);

        $order = $purchaseOrder->load(['supplier', 'paymentTerm', 'purchaseRequisition', 'lines.item', 'lines.uom']);
        $history = AuditLog::query()->with('user')->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)->latest('created_at')->latest('id')->get();

        return view($this->moduleViewPrefix().'::purchase-orders.show', ['order' => $order, 'history' => $history, 'dateFormat' => (string) app(GlobalSettings::class)->value('date_format'), 'moduleRoutePrefix' => $this->moduleRoutePrefix()]);
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder, AuditLogger $audit): JsonResponse
    {
        $po = $this->scoped($request, $purchaseOrder);
        if ($po->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะ Purchase Order ที่ยังเป็นร่าง']);
        }
        DB::transaction(function () use ($request, $po, $audit): void {
            $old = $po->load('lines')->toArray();
            $audit->record('wms.purchase_order.deleted', $po, $old, [], $request->user(), $request);
            $po->delete();
        });

        return response()->json(['status' => true, 'msg' => 'ลบร่าง Purchase Order แล้ว']);
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder, AuditLogger $audit): JsonResponse
    {
        $po = $this->scoped($request, $purchaseOrder);
        $po->loadCount('lines');
        if ($po->status !== 'DRAFT' || $po->lines_count < 1) {
            throw ValidationException::withMessages(['status' => 'อนุมัติได้เฉพาะร่างที่มีรายการ']);
        }
        $po->update(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_by' => $request->user()->id]);
        $audit->record('wms.purchase_order.approved', $po, [], $po->toArray(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'อนุมัติ Purchase Order แล้ว']);
    }

    public function void(Request $request, PurchaseOrder $purchaseOrder, AuditLogger $audit): JsonResponse
    {
        $po = $this->scoped($request, $purchaseOrder);
        if (! in_array($po->status, ['DRAFT', 'APPROVED'], true)) {
            throw ValidationException::withMessages(['status' => 'ยกเลิกได้เฉพาะ Draft หรือ Approved']);
        }
        if (GoodsReceipt::query()->where('purchase_order_id', $po->id)->where('status', '!=', 'VOID')->exists()) {
            throw ValidationException::withMessages(['status' => 'ยกเลิก PO ไม่ได้ เพราะมี Goods Receipt ที่ยังไม่ยกเลิก กรุณายกเลิก Receipt ก่อน']);
        }
        $reason = trim((string) $request->input('reason'));
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร']);
        }
        $po->update(['status' => 'VOID', 'voided_by' => $request->user()->id, 'voided_at' => now(), 'void_reason' => $reason, 'updated_by' => $request->user()->id]);
        $audit->record('wms.purchase_order.voided', $po, [], $po->toArray(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ยกเลิก Purchase Order แล้ว']);
    }

    private function scoped(Request $request, PurchaseOrder $po): PurchaseOrder
    {
        abort_unless((int) $po->{$this->purchasingScopeColumn()} === $this->purchasingScopeId($request) && in_array((int) $po->warehouse_id, $this->authorizedWarehouseIds($request), true), 404);

        return PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
    }

    private function purchasingScopeColumn(): string
    {
        return $this->moduleRoutePrefix() === 'purchasing' ? 'branch_id' : 'warehouse_id';
    }

    private function purchasingScopeId(Request $request): int
    {
        return (int) ($this->moduleRoutePrefix() === 'purchasing'
            ? $request->attributes->get('selectedBranch')->id
            : $request->attributes->get('selectedWarehouse')->id);
    }

    /** @return list<int> */
    protected function authorizedWarehouseIds(Request $request): array
    {
        return [(int) $request->attributes->get('selectedWarehouse')->id];
    }

    private function availableWarehouses(Request $request)
    {
        if ($this->moduleRoutePrefix() !== 'purchasing') {
            return collect([$request->attributes->get('selectedWarehouse')]);
        }

        return $request->user()->warehouses()
            ->where('warehouses.branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('warehouses.is_active', true)
            ->orderBy('warehouses.code')
            ->get(['warehouses.id', 'warehouses.code', 'warehouses.name']);
    }

    private function purchasingWarehouse(Request $request, mixed $warehouseId): Warehouse
    {
        if (! $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => 'กรุณาเลือกคลังรับสินค้า']);
        }

        return $request->user()->warehouses()
            ->whereKey($warehouseId)
            ->where('warehouses.branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('warehouses.is_active', true)
            ->firstOr(fn () => throw ValidationException::withMessages(['warehouse_id' => 'คลังรับสินค้าไม่อยู่ในสาขาปัจจุบัน หรือคุณไม่มีสิทธิ์ใช้งาน']));
    }
}
