<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\InventoryAdjustment;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Requests\SaveInventoryAdjustmentRequest;
use App\Modules\Wms\Services\InventoryAdjustmentDocumentReversalService;
use App\Modules\Wms\Services\InventoryAdjustmentLiveReversalAdapter;
use App\Modules\Wms\Services\InventoryAdjustmentPostingService;
use App\Modules\Wms\Support\WmsDecimal;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class InventoryAdjustmentController extends Controller
{
    public function index(Request $request): View
    {
        return view('Wms::inventory-adjustments.index', [
            'warehouse' => $request->attributes->get('selectedWarehouse'),
            'warehouses' => $this->warehouses($request),
        ]);
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก', 'REVERSED' => 'กลับรายการแล้ว'];
        $directions = ['GAIN' => 'เพิ่มสินค้า', 'LOSS' => 'ลดสินค้า'];
        $query = InventoryAdjustmentDocument::query()->with(['lines.item:id,code,name', 'lines.uom:id,code', 'creator:id,name'])->where('warehouse_id', $warehouseId)->latest('id');

        return DataTables::eloquent($query)
            ->addColumn('document_number', fn ($r) => $r->document_number)
            ->addColumn('line_count', fn ($r) => $r->lines->count())
            ->addColumn('item_label', fn ($r) => $r->lines->map(fn ($line) => trim(($line->item?->code ?: '').' · '.($line->item?->name ?: '-'), ' ·'))->unique()->implode(', '))
            ->addColumn('uom_label', fn ($r) => $r->lines->pluck('uom.code')->unique()->implode(', '))
            ->addColumn('direction_label', fn ($r) => $directions[$r->direction] ?? $r->direction ?? '-')
            ->addColumn('status_label', fn ($r) => $labels[$r->status] ?? $r->status)
            ->addColumn('can_approve', fn ($r) => $r->status === 'DRAFT' && $request->user()->hasPermission('wms.inventory-adjustments.approve'))
            ->addColumn('can_post', fn ($r) => $r->status === 'APPROVED'
                && (bool) config('erp.inventory.adjustment_posting_enabled', false)
                && $request->user()->hasPermission('wms.inventory-adjustments.post'))
            ->addColumn('can_delete', fn ($r) => $r->status === 'DRAFT' && $request->user()->hasPermission('wms.inventory-adjustments.delete'))
            ->addColumn('can_reverse', fn ($r) => $r->status === 'POSTED' && $r->reversal_status !== 'REVERSED' && (bool) config('erp.inventory.adjustment_posting_enabled', false) && $request->user()->hasPermission('wms.inventory-adjustments.reverse'))
            ->addColumn('business_date', fn ($r) => $r->document_date?->format((string) ($settings->value('date_format') ?: 'd/m/Y')) ?: '-')
            ->addColumn('quantity', fn ($r) => WmsDecimal::format($r->lines->sum('quantity')))
            ->addColumn('value', fn ($r) => WmsDecimal::format($r->lines->sum('value')))
            ->addColumn('reason', fn ($r) => $r->reason)
            ->addColumn('show_url', fn ($r) => route('wms.inventory-adjustments.documents.show', $r))
            ->orderColumn('business_date', 'document_date $1')
            ->toJson();
    }

    public function create(): View
    {
        return view('Wms::inventory-adjustments.documents.create', ['document' => null]);
    }

    public function editDocument(Request $request, InventoryAdjustmentDocument $document): View
    {
        $this->scopeDocument($request, $document);
        abort_unless($document->status === 'DRAFT', 422, 'แก้ไขได้เฉพาะเอกสารร่าง');
        $document->load(['lines.item:id,code,name,base_uom_id', 'lines.item.baseUom:id,code,name', 'lines.uom:id,code,name']);

        return view('Wms::inventory-adjustments.documents.create', ['document' => $document]);
    }

    public function updateDocument(SaveInventoryAdjustmentRequest $request, InventoryAdjustmentDocument $document, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse
    {
        $this->scopeDocument($request, $document);
        abort_unless($document->status === 'DRAFT', 422, 'แก้ไขได้เฉพาะเอกสารร่าง');
        $values = $request->validated();
        DB::transaction(function () use ($values, $document, $request, $audit, $sequences): void {
            $before = $document->load('lines')->toArray();
            $date = Carbon::parse($values['document_date']);
            $number = $document->document_number;
            if ($document->document_date->toDateString() !== $date->toDateString()) {
                $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'INVENTORY_ADJUSTMENT')->where('is_active', true)->lockForUpdate()->first();
                if (! $sequence) {
                    throw ValidationException::withMessages(['document_date' => 'ยังไม่ได้ตั้งค่าเลขเอกสารสำหรับวันที่ใหม่']);
                }
                $warehouse = $request->attributes->get('selectedWarehouse');
                $warehouse->loadMissing('branch');
                if (! $warehouse->branch) {
                    throw ValidationException::withMessages(['warehouse_id' => 'คลังของเอกสารไม่มีสาขา']);
                }
                $number = $sequences->replaceDraftNumberForBranch($sequence, $warehouse->branch, $document->document_number, 'inventory_adjustment_document', (int) $document->id, $date, $request->user()->id);
            }
            $document->forceFill(['document_number' => $number, 'document_date' => $date, 'direction' => $values['direction'], 'reason' => $values['reason']])->save();
            $document->lines()->delete();
            foreach ($values['lines'] as $position => $line) {
                $item = Item::query()->findOrFail($line['item_id']);
                abort_unless((int) $line['uom_id'] === (int) $item->base_uom_id, 422, 'Adjustment ต้องใช้หน่วยฐานของสินค้าใน MVP');
                InventoryAdjustment::query()->create([...$line, 'direction' => $values['direction'], 'document_id' => $document->id, 'line_number' => $position + 1, 'warehouse_id' => $document->warehouse_id, 'business_date' => $date, 'reason' => $values['reason'], 'idempotency_key' => 'adjustment:'.$document->id.':line:'.($position + 1).':'.bin2hex(random_bytes(4)), 'created_by' => $document->created_by]);
            }
            $audit->record('wms.inventory_adjustment.updated', $document, $before, $document->fresh()->load('lines')->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขร่าง Adjustment แล้ว', 'redirect' => route('wms.inventory-adjustments.documents.show', $document)]);
    }

    public function storeDocument(SaveInventoryAdjustmentRequest $request, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $warehouse->loadMissing('branch');
        if (! $warehouse->branch) {
            throw ValidationException::withMessages(['warehouse_id' => 'คลังที่เลือกไม่มีสาขา']);
        }
        $values = $request->validated();
        $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'INVENTORY_ADJUSTMENT')->first();
        abort_unless($sequence, 422, 'ยังไม่ได้ตั้งค่าเลขเอกสาร Adjustment');
        $document = DB::transaction(function () use ($values, $warehouse, $request, $sequences, $sequence, $audit): InventoryAdjustmentDocument {
            $date = Carbon::parse($values['document_date']);
            $number = $sequences->issueForBranch($sequence, $warehouse->branch, $date);
            $document = InventoryAdjustmentDocument::query()->create(['warehouse_id' => $warehouse->id, 'document_number' => $number, 'document_date' => $date, 'direction' => $values['direction'], 'reason' => $values['reason'], 'idempotency_key' => 'adjustment-document:'.bin2hex(random_bytes(12)), 'created_by' => $request->user()->id]);
            $sequences->recordIssued($sequence->fresh(), $number, 'inventory_adjustment_document', $document->id, $date, $request->user()->id);
            foreach ($values['lines'] as $position => $line) {
                $item = Item::query()->findOrFail($line['item_id']);
                abort_unless((int) $line['uom_id'] === (int) $item->base_uom_id, 422, 'Adjustment ต้องใช้หน่วยฐานของสินค้าใน MVP');
                $row = InventoryAdjustment::query()->create([...$line, 'direction' => $values['direction'], 'document_id' => $document->id, 'line_number' => $position + 1, 'warehouse_id' => $warehouse->id, 'business_date' => $date, 'reason' => $values['reason'], 'idempotency_key' => 'adjustment:'.$document->id.':line:'.($position + 1), 'created_by' => $request->user()->id]);
                $row->forceFill(['idempotency_key' => 'adjustment:'.$document->id.':line:'.$row->id])->save();
            }
            $audit->record('wms.inventory_adjustment.created', $document, [], $document->load('lines')->toArray(), $request->user(), $request);

            return $document;
        });

        return response()->json(['status' => true, 'msg' => 'บันทึกร่าง Adjustment แล้ว', 'redirect' => route('wms.inventory-adjustments.documents.show', $document)]);
    }

    public function showDocument(Request $request, InventoryAdjustmentDocument $document, GlobalSettings $settings): View
    {
        $this->scopeDocument($request, $document);
        $document->load(['warehouse:id,code,name', 'lines.item:id,code,name', 'lines.uom:id,code,name', 'lines.movement', 'lines.allocation.journalEntry.lines.account:id,code,name', 'creator:id,name']);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->latest('created_at')->latest('id')->get();

        return view('Wms::inventory-adjustments.documents.show', ['document' => $document, 'history' => $history, 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y')]);
    }

    public function approveDocument(Request $request, InventoryAdjustmentDocument $document, AuditLogger $audit): JsonResponse
    {
        $this->scopeDocument($request, $document);
        abort_unless($document->status === 'DRAFT' && $document->lines()->exists(), 422, 'อนุมัติได้เฉพาะเอกสารร่างที่มีรายการ');
        $before = $document->toArray();
        DB::transaction(function () use ($document, $request, $audit, $before): void {
            $document->load('lines');
            $document->lines->each(fn ($line) => $line->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id])->save());
            $document->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id])->save();
            $audit->record('wms.inventory_adjustment.approved', $document, $before, $document->fresh()->load('lines')->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'อนุมัติ Adjustment แล้ว']);
    }

    public function deleteDocument(Request $request, InventoryAdjustmentDocument $document, AuditLogger $audit): JsonResponse
    {
        $this->scopeDocument($request, $document);
        abort_unless($document->status === 'DRAFT', 422, 'ลบได้เฉพาะเอกสารร่าง');
        DB::transaction(function () use ($document, $request, $audit): void {
            $before = $document->load('lines')->toArray();
            $document->lines()->delete();
            $document->delete();
            $audit->record('wms.inventory_adjustment.deleted', $document, $before, [], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบร่าง Adjustment แล้ว', 'redirect' => route('wms.inventory-adjustments.index')]);
    }

    public function postDocument(Request $request, InventoryAdjustmentDocument $document, InventoryAdjustmentPostingService $posting, AuditLogger $audit): JsonResponse
    {
        $this->scopeDocument($request, $document);
        abort_unless($document->status === 'APPROVED', 422, 'ลงบัญชีได้เฉพาะเอกสารที่อนุมัติแล้ว');
        $warehouse = $request->attributes->get('selectedWarehouse');
        DB::transaction(function () use ($document, $posting, $warehouse, $request, $audit): void {
            $document->load('lines');
            foreach ($document->lines as $line) {
                $posting->postAdjustment($line, $warehouse, $request->user(), $request);
            } $before = $document->toArray();
            $document->forceFill(['status' => 'POSTED', 'posted_by' => $request->user()->id])->save();
            $audit->record('wms.inventory_adjustment.posted', $document, $before, $document->fresh()->load('lines')->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'Adjustment ลงบัญชีแล้ว', 'redirect' => route('wms.inventory-adjustments.documents.show', $document)]);
    }

    public function reverseDocument(Request $request, InventoryAdjustmentDocument $document, InventoryAdjustmentDocumentReversalService $reversal): JsonResponse
    {
        $this->scopeDocument($request, $document);
        $request->validate(['reversal_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $reversal->reverse($document, (string) $request->input('reversal_date'), (string) $request->input('reason'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'กลับรายการ Adjustment ทั้งเอกสารแล้ว', 'redirect' => route('wms.inventory-adjustments.documents.show', $document)]);
    }

    public function show(Request $request, InventoryAdjustment $adjustment, GlobalSettings $settings): View
    {
        $this->scope($request, $adjustment);
        $adjustment->load([
            'warehouse:id,code,name',
            'item:id,code,name',
            'uom:id,code,name',
            'creator:id,name',
            'movement:id,warehouse_id,item_id,uom_id,movement_type,direction,status,quantity,base_quantity,business_date,source_reference,posted_at,metadata',
            'allocation:id,stock_movement_id,warehouse_id,item_id,uom_id,allocation_type,direction,cost_status,status,method,revision,quantity,unit_cost,value,business_date,journal_entry_id,metadata',
            'allocation.journalEntry.lines.account:id,code,name',
            'allocation.journalEntry.lines.taxCode:id,code,name',
            'allocation.journalLineLinks.journalEntryLine.account:id,code,name',
        ]);

        $history = AuditLog::query()->with('user:id,name')
            ->where('subject_type', $adjustment->getMorphClass())
            ->where('subject_id', $adjustment->id)
            ->latest('created_at')->latest('id')->get();

        return view('Wms::inventory-adjustments.show', [
            'adjustment' => $adjustment,
            'history' => $history,
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
        ]);
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = Item::query()->with('baseUom:id,code,name')->where('is_active', true)->when($q, fn ($query) => $query->where(fn ($nested) => $nested->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['id', 'code', 'name', 'base_uom_id']);

        return response()->json(['results' => $rows->take(30)->map(fn ($row) => ['id' => $row->id, 'text' => $row->code.' · '.$row->name, 'base_uom_id' => $row->base_uom_id, 'base_uom_label' => trim(($row->baseUom?->code ?? '').' · '.($row->baseUom?->name ?? ''), ' ·')])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function destroy(Request $request, InventoryAdjustment $adjustment, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $adjustment);
        abort_unless($adjustment->status === 'DRAFT', 422, 'ลบได้เฉพาะรายการร่าง');
        $before = $adjustment->toArray();
        $adjustment->delete();
        $audit->record('wms.inventory_adjustment.deleted', $adjustment, $before, [], $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบร่าง Adjustment แล้ว']);
    }

    public function store(SaveInventoryAdjustmentRequest $request, AuditLogger $audit): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $values = $request->validated();
        $item = Item::query()->with('baseUom')->findOrFail($values['item_id']);
        abort_unless((int) $values['uom_id'] === (int) $item->base_uom_id, 422, 'Adjustment ต้องใช้หน่วยฐานของสินค้าใน MVP');
        $adjustment = DB::transaction(function () use ($values, $warehouse, $request, $audit): InventoryAdjustment {
            $row = InventoryAdjustment::query()->create([...$values, 'warehouse_id' => $warehouse->id, 'idempotency_key' => 'adjustment:'.bin2hex(random_bytes(12)), 'created_by' => $request->user()->id]);
            $audit->record('wms.inventory_adjustment.created', $row, [], $row->toArray(), $request->user(), $request);

            return $row;
        });

        return response()->json(['status' => true, 'msg' => 'บันทึก Adjustment เป็นร่างแล้ว', 'redirect' => route('wms.inventory-adjustments.index')]);
    }

    public function approve(Request $request, InventoryAdjustment $adjustment, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $adjustment);
        abort_unless($adjustment->status === 'DRAFT', 422, 'อนุมัติได้เฉพาะรายการร่าง');
        $before = $adjustment->toArray();
        $adjustment->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id])->save();
        $audit->record('wms.inventory_adjustment.approved', $adjustment, $before, $adjustment->fresh()->toArray(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'อนุมัติ Adjustment แล้ว']);
    }

    public function post(Request $request, InventoryAdjustment $adjustment, InventoryAdjustmentPostingService $posting, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $adjustment);
        $warehouse = $request->attributes->get('selectedWarehouse');
        $posting->postAdjustment($adjustment, $warehouse, $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'Adjustment ลงบัญชีแล้ว', 'redirect' => route('wms.inventory-adjustments.index')]);
    }

    public function reverse(Request $request, InventoryAdjustment $adjustment, InventoryAdjustmentLiveReversalAdapter $reversal): JsonResponse
    {
        $this->scope($request, $adjustment);
        $request->validate(['reversal_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $reversal->reverse($adjustment, (string) $request->input('reversal_date'), (string) $request->input('reason'), $request->user(), $request, true);

        return response()->json(['status' => true, 'msg' => 'กลับรายการ Adjustment แล้ว', 'redirect' => route('wms.inventory-adjustments.show', $adjustment)]);
    }

    private function scope(Request $request, InventoryAdjustment $adjustment): void
    {
        abort_unless((int) $adjustment->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
    }

    private function scopeDocument(Request $request, InventoryAdjustmentDocument $document): void
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
