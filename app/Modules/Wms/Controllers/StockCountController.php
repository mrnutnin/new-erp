<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockCountDocument;
use App\Modules\Wms\Models\StockCountLine;
use App\Modules\Wms\Requests\SaveStockCountRequest;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class StockCountController extends Controller
{
    public function index(Request $request): View
    {
        return view('Wms::stock-counts.index', [
            'warehouse' => $request->attributes->get('selectedWarehouse'),
            'warehouses' => $this->warehouses($request),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $labels = ['DRAFT' => 'ร่าง', 'COUNTED' => 'ตรวจนับแล้ว', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ปิดผลตรวจนับ', 'VOID' => 'ยกเลิก', 'REVERSED' => 'กลับรายการแล้ว'];
        $query = StockCountDocument::query()->with('lines.item:id,code,name')->where('warehouse_id', $warehouseId);
        if ($request->filled('status') && in_array($request->string('status')->toString(), ['DRAFT', 'COUNTED', 'APPROVED', 'POSTED', 'VOID', 'REVERSED'], true)) $query->where('status', $request->string('status')->toString());
        if ($request->filled('date_from')) $query->whereDate('document_date', '>=', $request->date('date_from'));
        if ($request->filled('date_to')) $query->whereDate('document_date', '<=', $request->date('date_to'));
        $query->latest('id');

        return DataTables::eloquent($query)
            ->addColumn('date_label', fn ($r) => $r->document_date?->format('d/m/Y') ?: '-')
            ->addColumn('status_label', fn ($r) => $labels[$r->status] ?? $r->status)
            ->addColumn('line_count', fn ($r) => $r->lines->count())
            ->addColumn('variance_label', fn ($r) => WmsDecimal::format($r->lines->sum('variance_quantity')))
            ->addColumn('show_url', fn ($r) => route('wms.stock-counts.show', $r))
            ->addColumn('can_approve', fn ($r) => in_array($r->status, ['DRAFT', 'COUNTED'], true) && $request->user()->hasPermission('wms.stock-counts.approve'))
            ->addColumn('can_edit', fn ($r) => $r->status === 'DRAFT' && $request->user()->hasPermission('wms.stock-counts.update'))
            ->addColumn('can_delete', fn ($r) => $r->status === 'DRAFT' && $request->user()->hasPermission('wms.stock-counts.delete'))
            ->rawColumns([])->toJson();
    }

    public function create(): View
    {
        return view('Wms::stock-counts.form', ['items' => Item::query()->with('baseUom:id,code,name')->where('is_active', true)->where('is_stock_item', true)->orderBy('code')->limit(200)->get(['id', 'code', 'name', 'base_uom_id'])]);
    }

    public function edit(Request $request, StockCountDocument $document): View
    {
        $this->scope($request, $document);
        abort_unless($document->status === 'DRAFT', 422, 'แก้ไขได้เฉพาะเอกสารร่าง');
        $document->load(['lines.item:id,code,name', 'lines.uom:id,code,name']);

        return view('Wms::stock-counts.form', ['document' => $document, 'items' => collect()]);
    }

    public function store(SaveStockCountRequest $request, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $values = $request->validated();
        $document = DB::transaction(function () use ($values, $warehouse, $request, $audit, $sequences): StockCountDocument {
            $warehouse->loadMissing('branch');
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'STOCK_COUNT')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence || ! $warehouse->branch) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารตรวจนับสำหรับสาขานี้']);
            }
            $date = Carbon::parse($values['document_date']);
            $number = $sequences->issueAvailableForBranch($sequence, $warehouse->branch, $date, fn (string $candidate): bool => StockCountDocument::query()->where('document_number', $candidate)->exists());
            $doc = StockCountDocument::query()->create(['warehouse_id' => $warehouse->id, 'document_number' => $number, 'document_date' => $values['document_date'], 'reason' => $values['reason'] ?? null, 'idempotency_key' => 'stock-count:'.bin2hex(random_bytes(12)), 'created_by' => $request->user()->id]);
            $sequences->recordIssued($sequence, $number, 'stock_count_documents', $doc->id, $date, $request->user()->id);
            foreach ($values['lines'] as $i => $line) {
                $item = Item::query()->with('baseUom')->findOrFail($line['item_id']);
                abort_unless((int) $item->base_uom_id === (int) $line['uom_id'], 422, 'ตรวจนับต้องใช้หน่วยฐานของสินค้า');
                $balance = StockBalance::query()->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->where('uom_id', $item->base_uom_id)->lockForUpdate()->first();
                $snapshot = (string) ($balance?->on_hand ?? '0');
                $cost = (string) ($balance?->average_unit_cost ?? '0');
                $counted = (string) $line['counted_quantity'];
                $variance = bcsub($counted, $snapshot, 8);
                $value = bcmul($variance, $cost, 8);
                StockCountLine::query()->create(['document_id' => $doc->id, 'line_number' => $i + 1, 'item_id' => $item->id, 'uom_id' => $item->base_uom_id, 'snapshot_quantity' => $snapshot, 'counted_quantity' => $counted, 'variance_quantity' => $variance, 'snapshot_unit_cost' => $cost, 'variance_value' => $value, 'note' => $line['note'] ?? null]);
            }
            $audit->record('wms.stock_count.created', $doc, [], $doc->fresh('lines')->toArray(), $request->user(), $request);

            return $doc;
        });

        return response()->json(['status' => true, 'msg' => 'บันทึกเอกสารตรวจนับแล้ว', 'redirect' => route('wms.stock-counts.show', $document)]);
    }

    public function update(SaveStockCountRequest $request, StockCountDocument $document, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $document);
        $values = $request->validated();
        DB::transaction(function () use ($values, $document, $request, $audit): void {
            $document = StockCountDocument::query()->with('lines')->lockForUpdate()->findOrFail($document->id);
            abort_unless($document->status === 'DRAFT', 422, 'แก้ไขได้เฉพาะเอกสารร่าง');
            $before = $document->toArray();
            $oldByItem = $document->lines->keyBy('item_id');
            $document->forceFill(['document_date' => $values['document_date'], 'reason' => $values['reason'] ?? null])->save();
            $document->lines()->delete();
            foreach ($values['lines'] as $i => $line) {
                $item = Item::query()->with('baseUom')->findOrFail($line['item_id']);
                abort_unless((int) $item->base_uom_id === (int) $line['uom_id'], 422, 'ตรวจนับต้องใช้หน่วยฐานของสินค้า');
                $old = $oldByItem->get($item->id);
                $snapshot = $old ? (string) $old->snapshot_quantity : (string) (StockBalance::query()->where('warehouse_id', $document->warehouse_id)->where('item_id', $item->id)->where('uom_id', $item->base_uom_id)->value('on_hand') ?? '0');
                $cost = $old ? (string) $old->snapshot_unit_cost : (string) (StockBalance::query()->where('warehouse_id', $document->warehouse_id)->where('item_id', $item->id)->where('uom_id', $item->base_uom_id)->value('average_unit_cost') ?? '0');
                $counted = (string) $line['counted_quantity'];
                $variance = bcsub($counted, $snapshot, 8);
                StockCountLine::query()->create(['document_id' => $document->id, 'line_number' => $i + 1, 'item_id' => $item->id, 'uom_id' => $item->base_uom_id, 'snapshot_quantity' => $snapshot, 'counted_quantity' => $counted, 'variance_quantity' => $variance, 'snapshot_unit_cost' => $cost, 'variance_value' => bcmul($variance, $cost, 8), 'note' => $line['note'] ?? null]);
            }
            $audit->record('wms.stock_count.updated', $document, $before, $document->fresh('lines')->toArray(), $request->user(), $request);
        }, 3);

        return response()->json(['status' => true, 'msg' => 'แก้ไขเอกสารตรวจนับแล้ว', 'redirect' => route('wms.stock-counts.show', $document)]);
    }

    public function show(Request $request, StockCountDocument $document, GlobalSettings $settings): View
    {
        $this->scope($request, $document);
        $document->load(['warehouse:id,code,name', 'lines.item:id,code,name', 'lines.uom:id,code,name', 'creator:id,name']);
        // Legacy links are displayed for audit only. New Stock Count documents never create them.
        $adjustmentIds = collect($document->adjustment_document_ids ?? [])->map(fn ($id) => (int) $id)->filter()->values();
        $adjustmentDocuments = $adjustmentIds->isEmpty() ? collect() : InventoryAdjustmentDocument::query()
            ->whereIn('id', $adjustmentIds->all())
            ->where('warehouse_id', $document->warehouse_id)
            ->with('lines.item:id,code,name')
            ->get()->keyBy('id');
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->latest('created_at')->latest('id')->get();

        return view('Wms::stock-counts.show', ['document' => $document, 'adjustmentDocuments' => $adjustmentDocuments, 'history' => $history, 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y')]);
    }

    public function approve(Request $request, StockCountDocument $document, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $document);
        DB::transaction(function () use ($document, $request, $audit): void {
            $document = StockCountDocument::query()->lockForUpdate()->findOrFail($document->id);
            abort_unless(in_array($document->status, ['DRAFT', 'COUNTED'], true) && $document->lines()->exists(), 422, 'อนุมัติได้เมื่อมีผลตรวจนับแล้ว');
            $before = $document->toArray();
            $document->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id])->save();
            $audit->record('wms.stock_count.approved', $document, $before, $document->fresh()->toArray(), $request->user(), $request);
        }, 3);

        return response()->json(['status' => true, 'msg' => 'อนุมัติผลตรวจนับแล้ว']);
    }

    public function destroy(Request $request, StockCountDocument $document, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $document);
        abort_unless($document->status === 'DRAFT', 422, 'ลบได้เฉพาะเอกสารร่าง');
        $before = $document->load('lines')->toArray();
        $document->delete();
        $audit->record('wms.stock_count.deleted', $document, $before, [], $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบร่างเอกสารตรวจนับแล้ว']);
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = Item::query()->with('baseUom:id,code,name')->where('is_active', true)->where('is_stock_item', true)->when($q, fn ($x) => $x->where(fn ($n) => $n->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['id', 'code', 'name', 'base_uom_id']);

        return response()->json(['results' => $rows->take(30)->map(fn ($r) => ['id' => $r->id, 'text' => $r->code.' · '.$r->name, 'uom_id' => $r->base_uom_id, 'uom_label' => trim(($r->baseUom?->code ?? '').' · '.($r->baseUom?->name ?? ''), ' ·')])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    private function scope(Request $request, StockCountDocument $document): void
    {
        abort_unless((int) $document->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
    }

    private function warehouses(Request $request)
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', $request->attributes->get('selectedBranch')->id)
            ->orderBy('name')->get(['warehouses.id', 'warehouses.code', 'warehouses.name']);
    }
}
