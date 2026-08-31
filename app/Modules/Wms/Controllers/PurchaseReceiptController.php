<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\GoodsReceipt;
use App\Modules\Wms\Models\PurchaseOrder;
use App\Modules\Wms\Services\GoodsReceiptService;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PurchaseReceiptController extends Controller
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
        return view($this->moduleViewPrefix().'::purchase-receipts.index', ['moduleRoutePrefix' => $this->moduleRoutePrefix()]);
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $scopeId = $this->purchasingScopeId($request);
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'VOID' => 'ยกเลิก'];
        $query = GoodsReceipt::query()->with(['purchaseOrder:id,document_number', 'supplier:id,code,name', 'lines.purchaseDocumentAllocations.purchaseDocumentLine.document'])->where($this->purchasingScopeColumn(), $scopeId)->whereIn('warehouse_id', $warehouseIds)
            ->when($request->filled('business_date_from'), fn ($q) => $q->whereDate('business_date', '>=', $request->input('business_date_from')))
            ->when($request->filled('business_date_to'), fn ($q) => $q->whereDate('business_date', '<=', $request->input('business_date_to')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', (int) $request->input('supplier_id')))
            ->when($request->filled('purchase_document_number'), fn ($q) => $q->whereHas('lines.purchaseDocumentAllocations.purchaseDocumentLine.document', fn ($document) => $document->where('document_number', 'like', '%'.trim((string) $request->input('purchase_document_number')).'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')));

        return DataTables::eloquent($query)
            ->addColumn('purchase_order_label', fn (GoodsReceipt $row) => $row->purchaseOrder?->document_number ?: '-')
            ->addColumn('supplier_label', fn (GoodsReceipt $row) => $row->supplier ? trim($row->supplier->code.' · '.$row->supplier->name, ' ·') : '-')
            ->addColumn('purchase_document_label', fn (GoodsReceipt $row) => $row->lines->flatMap(fn ($line) => $line->purchaseDocumentAllocations->map(fn ($allocation) => $allocation->purchaseDocumentLine?->document))->filter()->unique('id')->pluck('document_number')->implode(', ') ?: '-')
            ->addColumn('reason_label', fn (GoodsReceipt $row) => $row->status === 'VOID' ? ($row->void_reason ?: '-') : '-')
            ->addColumn('status_label', fn (GoodsReceipt $row) => $labels[$row->status] ?? $row->status)
            ->editColumn('business_date', fn (GoodsReceipt $row) => $row->business_date?->format((string) $settings->value('date_format')) ?: '-')
            ->addColumn('can_approve', fn (GoodsReceipt $row) => $row->status === 'DRAFT' && $request->user()->hasPermission($this->modulePermission('purchase-receipts.approve')))
            ->addColumn('can_void', fn (GoodsReceipt $row) => in_array($row->status, ['DRAFT', 'APPROVED'], true) && ! $row->lines->flatMap->purchaseDocumentAllocations->contains(fn ($allocation) => $allocation->purchaseDocumentLine?->document?->status !== 'VOID') && $request->user()->hasPermission($this->modulePermission('purchase-receipts.void')))
            ->addColumn('edit_url', fn (GoodsReceipt $row) => $row->status === 'DRAFT' && $request->user()->hasPermission($this->modulePermission('purchase-receipts.update')) ? route($this->moduleRoutePrefix().'.purchase-receipts.edit', $row) : null)
            ->addColumn('print_url', fn (GoodsReceipt $row) => $request->user()->hasPermission($this->modulePermission('purchase-receipts.print')) ? route($this->moduleRoutePrefix().'.purchase-receipts.pdf', $row) : null)
            ->toJson();
    }

    public function supplierOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = Party::query()->join('party_roles', fn ($join) => $join->on('party_roles.party_id', '=', 'parties.id')->where('party_roles.role', 'SUPPLIER')->where('party_roles.is_active', true))
            ->where('parties.is_active', true)->when($q, fn (Builder $x) => $x->where(fn (Builder $y) => $y->where('parties.code', 'like', "%{$q}%")->orWhere('parties.name', 'like', "%{$q}%")))
            ->orderBy('parties.code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['parties.id', 'parties.code', 'parties.name']);

        return response()->json(['results' => $rows->take(30)->map(fn (Party $p) => ['id' => $p->id, 'text' => $p->code.' · '.$p->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function create(): View
    {
        return view($this->moduleViewPrefix().'::purchase-receipts.form', ['today' => now()->toDateString(), 'receipt' => null, 'moduleRoutePrefix' => $this->moduleRoutePrefix()]);
    }

    public function edit(Request $request, GoodsReceipt $purchaseReceipt): View
    {
        $this->assertWarehouse($request, $purchaseReceipt);
        abort_unless($purchaseReceipt->status === 'DRAFT', 404);

        return view($this->moduleViewPrefix().'::purchase-receipts.form', ['today' => $purchaseReceipt->business_date?->format('Y-m-d') ?: now()->toDateString(), 'receipt' => $purchaseReceipt->load('lines'), 'moduleRoutePrefix' => $this->moduleRoutePrefix()]);
    }

    public function purchaseOptions(Request $request, GlobalSettings $settings): JsonResponse
    {
        $values = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = PurchaseOrder::query()->where($this->purchasingScopeColumn(), $this->purchasingScopeId($request))->whereIn('warehouse_id', $this->authorizedWarehouseIds($request))->where('status', 'APPROVED')->when($values['q'] ?? null, fn ($query, $q) => $query->where('document_number', 'like', '%'.trim($q).'%'))->orderByDesc('document_date')->forPage((int) ($values['page'] ?? 1), 31)->get(['id', 'document_number', 'document_date']);
        $dateFormat = (string) $settings->value('date_format');

        return response()->json(['results' => $query->take(30)->map(fn (PurchaseOrder $row) => ['id' => $row->id, 'text' => $row->document_number.' · '.$row->document_date->format($dateFormat)])->values(), 'pagination' => ['more' => $query->count() > 30]]);
    }

    public function lineOptions(Request $request): JsonResponse
    {
        $values = $request->validate(['purchase_order_id' => ['required', 'integer', 'min:1']]);
        $order = PurchaseOrder::query()->where($this->purchasingScopeColumn(), $this->purchasingScopeId($request))->whereIn('warehouse_id', $this->authorizedWarehouseIds($request))->where('status', 'APPROVED')->findOrFail($values['purchase_order_id']);
        $lines = $order->lines()->with(['item:id,code,name,base_uom_id', 'uom:id,code,name'])->whereNotNull('item_id')->whereNotNull('uom_id')->get();
        $received = GoodsReceipt::query()->with('lines')->where('purchase_order_id', $order->id)->where('status', '!=', 'VOID')->get()->flatMap->lines->groupBy('purchase_order_line_id')->map(fn ($rows) => $rows->sum('purchase_quantity'));

        return response()->json(['results' => $lines->map(function ($line) use ($received): array {
            $already = (float) ($received[$line->id] ?? 0);
            $remaining = max(0, (float) $line->quantity - $already);

            return ['id' => $line->id, 'text' => "#{$line->line_number} ".($line->item?->code ?? '-').' · '.($line->item?->name ?? '-').' / '.($line->uom?->code ?? '-'), 'purchase_quantity' => WmsDecimal::format($line->quantity), 'received_quantity' => WmsDecimal::format($already), 'remaining_quantity' => WmsDecimal::format($remaining), 'total_cost' => (string) $line->line_total];
        })->filter(fn (array $line): bool => (float) $line['remaining_quantity'] > 0)->values()]);
    }

    public function store(Request $request, GoodsReceiptService $receipts): JsonResponse
    {
        $values = $request->validate($this->rules());
        $order = PurchaseOrder::query()->where($this->purchasingScopeColumn(), $this->purchasingScopeId($request))->whereIn('warehouse_id', $this->authorizedWarehouseIds($request))->where('status', 'APPROVED')->findOrFail($values['purchase_order_id']);
        $receipt = $receipts->createDraft([...$values, 'warehouse_id' => $order->warehouse_id], $request->user());

        return response()->json(['status' => true, 'msg' => "สร้างร่าง Receipt {$receipt->receipt_number} แล้ว", 'redirect' => route($this->moduleRoutePrefix().'.purchase-receipts.index')]);
    }

    public function update(Request $request, GoodsReceipt $purchaseReceipt, GoodsReceiptService $receipts): JsonResponse
    {
        $this->assertWarehouse($request, $purchaseReceipt);
        $receipt = $receipts->updateDraft($purchaseReceipt, $request->validate($this->rules(false)), $request->user());

        return response()->json(['status' => true, 'msg' => "แก้ไขร่าง Receipt {$receipt->receipt_number} แล้ว", 'redirect' => route($this->moduleRoutePrefix().'.purchase-receipts.index')]);
    }

    public function approve(Request $request, GoodsReceipt $purchaseReceipt, GoodsReceiptService $receipts): JsonResponse
    {
        $this->assertWarehouse($request, $purchaseReceipt);
        $receipt = $receipts->approve($purchaseReceipt, $request->user());

        return response()->json(['status' => true, 'msg' => "อนุมัติ Receipt {$receipt->receipt_number} แล้ว"]);
    }

    public function void(Request $request, GoodsReceipt $purchaseReceipt, GoodsReceiptService $receipts): JsonResponse
    {
        $this->assertWarehouse($request, $purchaseReceipt);
        $values = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $receipt = $receipts->void($purchaseReceipt, $values['reason'], $request->user());

        return response()->json(['status' => true, 'msg' => "ยกเลิก Receipt {$receipt->receipt_number} แล้ว"]);
    }

    private function rules(bool $creating = true): array
    {
        $decimal = 'decimal:0,'.WmsDecimal::places();

        return ['purchase_order_id' => [$creating ? 'required' : 'nullable', 'integer', 'min:1'], 'idempotency_key' => [$creating ? 'required' : 'nullable', 'string', 'max:180'], 'business_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'description' => ['nullable', 'string', 'max:2000'], 'lines' => [$creating ? 'required' : 'nullable', 'array', 'min:1'], 'lines.*.purchase_order_line_id' => ['required', 'integer', 'min:1'], 'lines.*.purchase_qty' => ['required', 'numeric', 'gt:0', $decimal], 'lines.*.total_cost' => ['nullable', 'numeric', 'gte:0', $decimal]];
    }

    private function assertWarehouse(Request $request, GoodsReceipt $receipt): void
    {
        abort_unless((int) $receipt->{$this->purchasingScopeColumn()} === $this->purchasingScopeId($request) && in_array((int) $receipt->warehouse_id, $this->authorizedWarehouseIds($request), true), 404);
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

    private function purchasingScopeId(Request $request): int
    {
        return (int) ($this->moduleRoutePrefix() === 'purchasing'
            ? $request->attributes->get('selectedBranch')->id
            : $request->attributes->get('selectedWarehouse')->id);
    }
}
