<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Party;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\PurchaseOrder;
use App\Modules\Wms\Models\PurchaseRequisition;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Requests\ChangePurchaseRequisitionStatusRequest;
use App\Modules\Wms\Requests\SavePurchaseRequisitionRequest;
use App\Modules\Wms\Services\PurchaseRequisitionPurchaseOrderService;
use App\Modules\Wms\Services\StockMinMaxAlertService;
use App\Modules\Wms\Support\PurchaseRequisitionState;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PurchaseRequisitionController extends Controller
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
        return view($this->moduleViewPrefix().'::purchase-requisitions.index', ['moduleRoutePrefix' => $this->moduleRoutePrefix()]);
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $scopeId = $this->purchasingBranchId($request);
        $dateFormat = (string) $settings->value('date_format');
        $query = PurchaseRequisition::query()->with(['supplier', 'purchaseOrder'])->where($this->purchasingScopeColumn(), $scopeId)->whereIn('warehouse_id', $this->authorizedWarehouseIds($request))
            ->when($request->filled('document_date_from'), fn (Builder $q) => $q->whereDate('document_date', '>=', $request->input('document_date_from')))
            ->when($request->filled('document_date_to'), fn (Builder $q) => $q->whereDate('document_date', '<=', $request->input('document_date_to')))
            ->when($request->filled('supplier_id'), fn (Builder $q) => $q->where('supplier_id', (int) $request->input('supplier_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')));

        return DataTables::eloquent($query)
            ->filter(fn (Builder $q) => $q->when($request->filled('search.value'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search.value'));
                $q->where(fn (Builder $x) => $x->where('document_number', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%")
                    ->orWhereHas('supplier', fn (Builder $s) => $s->where('code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")));
            }))
            ->addColumn('document_date_label', fn (PurchaseRequisition $r) => $r->document_date?->format($dateFormat) ?: '-')
            ->addColumn('supplier_label', fn (PurchaseRequisition $r) => $r->supplier ? $r->supplier->code.' · '.$r->supplier->name : '-')
            ->addColumn('purchase_order_label', fn (PurchaseRequisition $r) => $r->purchaseOrder ? $r->purchaseOrder->document_number : '-')
            ->addColumn('purchase_order_url', fn (PurchaseRequisition $r) => $r->purchaseOrder ? route($this->moduleRoutePrefix().'.purchase-orders.show', $r->purchaseOrder) : null)
            ->addColumn('status_label', fn (PurchaseRequisition $r) => [
                'DRAFT' => 'ร่าง', 'SUBMITTED' => 'รออนุมัติ', 'APPROVED' => 'อนุมัติแล้ว', 'REJECTED' => 'ตีกลับ', 'VOID' => 'ยกเลิก',
            ][$r->status] ?? $r->status)
            ->addColumn('show_url', fn (PurchaseRequisition $r) => route($this->moduleRoutePrefix().'.purchase-requisitions.edit', $r))
            ->addColumn('print_url', fn (PurchaseRequisition $r) => $request->user()->hasPermission($this->modulePermission('purchase-requisitions.print')) ? route($this->moduleRoutePrefix().'.purchase-requisitions.pdf', $r) : null)
            ->addColumn('edit_url', fn (PurchaseRequisition $r) => in_array($r->status, ['DRAFT', 'REJECTED'], true) && $request->user()->hasPermission($this->modulePermission('purchase-requisitions.update')) ? route($this->moduleRoutePrefix().'.purchase-requisitions.edit', $r) : null)
            ->addColumn('submit_url', fn (PurchaseRequisition $r) => in_array($r->status, ['DRAFT', 'REJECTED'], true) && $request->user()->hasPermission($this->modulePermission('purchase-requisitions.submit')) ? route($this->moduleRoutePrefix().'.purchase-requisitions.submit', $r) : null)
            ->addColumn('approve_url', fn (PurchaseRequisition $r) => $r->status === 'SUBMITTED' && $request->user()->hasPermission($this->modulePermission('purchase-requisitions.approve')) ? route($this->moduleRoutePrefix().'.purchase-requisitions.approve', $r) : null)
            ->addColumn('reject_url', fn (PurchaseRequisition $r) => $r->status === 'SUBMITTED' && $request->user()->hasPermission($this->modulePermission('purchase-requisitions.reject')) ? route($this->moduleRoutePrefix().'.purchase-requisitions.reject', $r) : null)
            ->addColumn('void_url', fn (PurchaseRequisition $r) => $r->status !== 'VOID'
                && (! $r->purchaseOrder || $r->purchaseOrder->status === 'VOID')
                && $request->user()->hasPermission($this->modulePermission('purchase-requisitions.void'))
                    ? route($this->moduleRoutePrefix().'.purchase-requisitions.void', $r)
                    : null)
            ->addColumn('delete_url', fn (PurchaseRequisition $r) => in_array($r->status, ['DRAFT', 'REJECTED'], true) && ! $r->purchaseOrder && $request->user()->hasPermission($this->modulePermission('purchase-requisitions.delete')) ? route($this->moduleRoutePrefix().'.purchase-requisitions.destroy', $r) : null)
            ->addColumn('create_po_url', fn (PurchaseRequisition $r) => $r->status === 'APPROVED' && $request->user()->hasPermission($this->modulePermission('purchase-requisitions.create-po')) && $request->user()->hasPermission($this->modulePermission('purchase-orders.create')) && ! $r->purchaseOrder ? route($this->moduleRoutePrefix().'.purchase-orders.create', ['purchase_requisition_id' => $r->id]) : null)
            ->toJson();
    }

    public function createPurchaseOrder(
        Request $request,
        PurchaseRequisition $purchaseRequisition,
        PurchaseRequisitionPurchaseOrderService $service,
        DocumentSequenceService $sequences,
        AuditLogger $audit,
    ): JsonResponse {
        $request->validate(['supplier_id' => ['required', 'integer', 'min:1']]);
        $source = $this->scoped($request, $purchaseRequisition);
        $po = $service->createFromApproved($source, $request->user(), $request, $sequences, $audit, null, (int) $request->input('supplier_id'));

        return response()->json(['status' => true, 'msg' => "สร้างร่าง Purchase Order {$po->document_number} จากใบขอซื้อแล้ว กรุณากรอกราคาใน PO", 'redirect' => route($this->moduleRoutePrefix().'.purchase-orders.edit', $po)]);
    }

    public function supplierOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = Party::query()->join('party_roles', fn ($join) => $join->on('party_roles.party_id', '=', 'parties.id')->where('party_roles.role', 'SUPPLIER')->where('party_roles.is_active', true))
            ->where('parties.is_active', true)->when($q, fn (Builder $x) => $x->where(fn (Builder $y) => $y->where('parties.code', 'like', "%{$q}%")->orWhere('parties.name', 'like', "%{$q}%")))
            ->orderBy('parties.code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['parties.id', 'parties.code', 'parties.name']);

        return response()->json(['results' => $rows->take(30)->map(fn (Party $p) => ['id' => $p->id, 'text' => $p->code.' · '.$p->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = Item::query()->with('baseUom')->where('is_active', true)->when($q, fn (Builder $x) => $x->where(fn (Builder $y) => $y->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['id', 'code', 'name', 'base_uom_id']);

        return response()->json(['results' => $rows->take(30)->map(fn (Item $i) => ['id' => $i->id, 'text' => $i->code.' · '.$i->name, 'uom_id' => $i->base_uom_id])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function uomOptions(Request $request): JsonResponse
    {
        $rows = Uom::query()->where('is_active', true)->when($request->filled('q'), fn (Builder $x) => $x->where(fn (Builder $y) => $y->where('code', 'like', '%'.$request->input('q').'%')->orWhere('name', 'like', '%'.$request->input('q').'%')))->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['id', 'code', 'name']);

        return response()->json(['results' => $rows->take(30)->map(fn (Uom $u) => ['id' => $u->id, 'text' => $u->code.' · '.$u->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function create(Request $request, StockMinMaxAlertService $minMaxAlerts): View
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $requestedIds = collect((array) $request->input('item_ids', []));
        if ($request->filled('item_id')) {
            $requestedIds->push($request->input('item_id'));
        }
        $requestedIds = $requestedIds->map(fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))
            ->filter()->unique()->values();
        $alerts = $request->input('source') === 'min-max' && $requestedIds->isNotEmpty()
            ? $minMaxAlerts->alerts($warehouse)->whereIn('item_id', $requestedIds)->keyBy('item_id')
            : collect();

        $lines = $alerts->map(function (array $alert): array {
            return [
                'item_id' => $alert['item_id'],
                'uom_id' => Item::query()->whereKey($alert['item_id'])->where('is_active', true)->where('is_stock_item', true)->value('base_uom_id'),
                'quantity' => $alert['recommended'],
                'description' => 'แนะนำจาก Min/Max Stock — กรุณาตรวจสอบก่อนบันทึก',
            ];
        })->values()->all();

        return view($this->moduleViewPrefix().'::purchase-requisitions.form', [
            'requisition' => new PurchaseRequisition(['document_date' => today()]),
            'lines' => $lines,
            'supplier' => null,
            'prefillSource' => $alerts->isNotEmpty() ? 'min-max' : null,
            'moduleRoutePrefix' => $this->moduleRoutePrefix(),
        ]);
    }

    public function edit(Request $request, PurchaseRequisition $purchaseRequisition): View
    {
        $requisition = $this->scoped($request, $purchaseRequisition)->load(['lines.item', 'lines.uom', 'supplier']);

        $history = AuditLog::query()->with('user')->where('subject_type', $requisition->getMorphClass())->where('subject_id', $requisition->id)->latest('created_at')->latest('id')->get();

        return view($this->moduleViewPrefix().'::purchase-requisitions.form', ['requisition' => $requisition, 'lines' => $requisition->lines, 'supplier' => $requisition->supplier, 'history' => $history, 'moduleRoutePrefix' => $this->moduleRoutePrefix()]);
    }

    public function destroy(Request $request, PurchaseRequisition $purchaseRequisition, AuditLogger $audit): JsonResponse
    {
        $requisition = $this->scoped($request, $purchaseRequisition);
        if (! in_array($requisition->status, ['DRAFT', 'REJECTED'], true)) {
            throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะใบขอซื้อที่ยังไม่อนุมัติ']);
        }
        if ($requisition->purchaseOrder()->exists()) {
            throw ValidationException::withMessages(['status' => 'ลบ PR ไม่ได้ เพราะมี PO อ้างอิงอยู่']);
        }
        DB::transaction(function () use ($request, $requisition, $audit): void {
            $old = $requisition->load('lines')->toArray();
            $audit->record('wms.purchase_requisition.deleted', $requisition, $old, [], $request->user(), $request);
            $requisition->delete();
        });

        return response()->json(['status' => true, 'msg' => 'ลบใบขอซื้อแล้ว']);
    }

    public function store(SavePurchaseRequisitionRequest $request, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse
    {
        $requisition = DB::transaction(fn () => $this->save($request, new PurchaseRequisition, $audit, $sequences, true));

        return response()->json(['status' => true, 'msg' => 'บันทึกใบขอซื้อร่างแล้ว', 'redirect' => route($this->moduleRoutePrefix().'.purchase-requisitions.index')]);
    }

    public function update(SavePurchaseRequisitionRequest $request, PurchaseRequisition $purchaseRequisition, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse
    {
        $requisition = $this->scoped($request, $purchaseRequisition);
        if (! in_array($requisition->status, ['DRAFT', 'REJECTED'], true)) {
            throw ValidationException::withMessages(['status' => 'แก้ไขได้เฉพาะ Draft หรือรายการที่ถูกตีกลับ']);
        }
        DB::transaction(fn () => $this->save($request, $requisition, $audit, $sequences, false));

        return response()->json(['status' => true, 'msg' => 'แก้ไขใบขอซื้อแล้ว']);
    }

    public function submit(ChangePurchaseRequisitionStatusRequest $request, PurchaseRequisition $purchaseRequisition, AuditLogger $audit): JsonResponse
    {
        return $this->transition($request, $purchaseRequisition, $audit, 'submit');
    }

    public function approve(ChangePurchaseRequisitionStatusRequest $request, PurchaseRequisition $purchaseRequisition, AuditLogger $audit): JsonResponse
    {
        return $this->transition($request, $purchaseRequisition, $audit, 'approve');
    }

    public function reject(ChangePurchaseRequisitionStatusRequest $request, PurchaseRequisition $purchaseRequisition, AuditLogger $audit): JsonResponse
    {
        return $this->transition($request, $purchaseRequisition, $audit, 'reject');
    }

    public function void(ChangePurchaseRequisitionStatusRequest $request, PurchaseRequisition $purchaseRequisition, AuditLogger $audit): JsonResponse
    {
        $linkedOrder = $this->scoped($request, $purchaseRequisition)->purchaseOrder()->where('status', '!=', 'VOID')->first();
        if ($linkedOrder) {
            throw ValidationException::withMessages(['status' => "ยกเลิก PR ไม่ได้ เพราะมี PO {$linkedOrder->document_number} อยู่ ต้องยกเลิก PO ก่อน"]);
        }

        return $this->transition($request, $purchaseRequisition, $audit, 'void');
    }

    private function save(SavePurchaseRequisitionRequest $request, PurchaseRequisition $requisition, AuditLogger $audit, DocumentSequenceService $sequences, bool $new): PurchaseRequisition
    {
        $values = $request->validated();
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $this->assertScope($values, $warehouseId);
        $old = $requisition->exists ? $requisition->toArray() : [];
        $requisition->fill(['warehouse_id' => $warehouseId, 'document_date' => $values['document_date'], 'supplier_id' => $values['supplier_id'] ?? null, 'description' => $values['description'] ?? null, 'created_by' => $requisition->created_by ?: $request->user()->id, 'updated_by' => $request->user()->id]);
        if (! $requisition->exists) {
            $warehouse = $request->attributes->get('selectedWarehouse')->loadMissing('branch');
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PURCHASE_REQUISITION')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence || ! $warehouse->branch) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบขอซื้อสำหรับสาขานี้']);
            }
            $requisition->status = 'DRAFT';
            $requisition->document_number = $sequences->issueAvailableForBranch($sequence, $warehouse->branch, Carbon::parse($values['document_date']), fn (string $number): bool => PurchaseRequisition::query()->where('document_number', $number)->exists());
        }
        $requisition->save();
        if ($new) {
            $sequences->recordIssued($sequence, $requisition->document_number, 'purchase_requisitions', $requisition->id, Carbon::parse($values['document_date']), $request->user()->id);
        }
        $requisition->lines()->delete();
        foreach ($values['lines'] as $index => $line) {
            $requisition->lines()->create(['line_number' => $index + 1, 'item_id' => $line['item_id'], 'uom_id' => $line['uom_id'], 'quantity' => $line['quantity'], 'description' => $line['description'] ?? null]);
        }
        $audit->record($new ? 'wms.purchase-requisition.created' : 'wms.purchase-requisition.updated', $requisition, $old, $requisition->fresh()->load('lines')->toArray(), $request->user(), $request);

        return $requisition;
    }

    private function transition(ChangePurchaseRequisitionStatusRequest $request, PurchaseRequisition $purchaseRequisition, AuditLogger $audit, string $transition): JsonResponse
    {
        $reason = trim((string) ($request->validated()['reason'] ?? ''));
        if ($transition === 'reject' && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลที่ตีกลับ']);
        }
        $requisition = $this->scoped($request, $purchaseRequisition);
        $old = $requisition->toArray();
        DB::transaction(function () use ($requisition, $transition, $reason, $request, $audit, $old): void {
            $row = PurchaseRequisition::query()->with('lines')->lockForUpdate()->findOrFail($requisition->id);
            try {
                $status = PurchaseRequisitionState::$transition($row->status);
            } catch (DomainException $e) {
                throw ValidationException::withMessages(['status' => $e->getMessage()]);
            }
            if ($transition === 'submit' && $row->lines()->count() === 0) {
                throw ValidationException::withMessages(['lines' => 'ใบขอซื้อต้องมีรายการสินค้าอย่างน้อย 1 รายการ']);
            }
            if ($transition === 'void' && PurchaseOrder::query()->where('purchase_requisition_id', $row->id)->where('status', '!=', 'VOID')->exists()) {
                throw ValidationException::withMessages(['status' => 'ยกเลิก PR ไม่ได้จนกว่า Purchase Order ที่อ้างอิงจะถูกยกเลิกก่อน']);
            }
            $data = ['status' => $status, 'updated_by' => $request->user()->id];
            if ($transition === 'submit') {
                $data += ['submitted_by' => $request->user()->id, 'submitted_at' => now(), 'rejection_reason' => null];
            }
            if ($transition === 'approve') {
                $data += ['approved_by' => $request->user()->id, 'approved_at' => now()];
            }
            if ($transition === 'reject') {
                $data += ['rejection_reason' => $reason];
            }
            if ($transition === 'void') {
                $data += ['voided_by' => $request->user()->id, 'voided_at' => now(), 'void_reason' => $reason ?: 'ยกเลิกโดยผู้ใช้'];
            }
            $row->forceFill($data)->save();
            $audit->record('wms.purchase-requisition.'.$transition, $row, $old, $row->fresh()->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => ['submit' => 'ส่งใบขอซื้อเพื่ออนุมัติแล้ว', 'approve' => 'อนุมัติใบขอซื้อแล้ว', 'reject' => 'ตีกลับใบขอซื้อแล้ว', 'void' => 'ยกเลิกใบขอซื้อแล้ว'][$transition]]);
    }

    private function scoped(Request $request, PurchaseRequisition $requisition): PurchaseRequisition
    {
        return PurchaseRequisition::query()->where($this->purchasingScopeColumn(), $this->purchasingBranchId($request))
            ->whereIn('warehouse_id', $this->authorizedWarehouseIds($request))->findOrFail($requisition->id);
    }

    /** @return list<int> */
    protected function authorizedWarehouseIds(Request $request): array
    {
        return [(int) $request->attributes->get('selectedWarehouse')->id];
    }

    private function purchasingScopeColumn(): string
    {
        return $this->moduleRoutePrefix() === 'purchasing' ? 'branch_id' : 'warehouse_id';
    }

    private function purchasingBranchId(Request $request): int
    {
        return (int) ($this->moduleRoutePrefix() === 'purchasing'
            ? $request->attributes->get('selectedBranch')->id
            : $request->attributes->get('selectedWarehouse')->id);
    }

    private function assertScope(array $values, int $warehouseId): void
    {
        if (! empty($values['supplier_id']) && ! Party::query()->whereKey($values['supplier_id'])->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('role', 'SUPPLIER')->where('is_active', true))->exists()) {
            throw ValidationException::withMessages(['supplier_id' => 'Supplier ไม่พร้อมใช้งาน']);
        }
        $items = Item::query()->whereIn('id', collect($values['lines'])->pluck('item_id'))->where('is_active', true)->get()->keyBy('id');
        $uoms = Uom::query()->whereIn('id', collect($values['lines'])->pluck('uom_id'))->where('is_active', true)->pluck('id');
        foreach ($values['lines'] as $index => $line) {
            if (! $items->has((int) $line['item_id'])) {
                throw ValidationException::withMessages(["lines.{$index}.item_id" => 'สินค้าไม่พร้อมใช้งาน']);
            }
            if (! $uoms->contains((int) $line['uom_id'])) {
                throw ValidationException::withMessages(["lines.{$index}.uom_id" => 'หน่วยนับไม่พร้อมใช้งาน']);
            }
        }
    }
}
