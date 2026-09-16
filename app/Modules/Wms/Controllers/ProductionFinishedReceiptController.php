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
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Requests\SaveInventoryAdjustmentRequest;
use App\Modules\Wms\Services\ManualProductionReceiptPostingService;
use App\Modules\Wms\Services\ProductionFinishedReceiptReversalService;
use App\Modules\Wms\Services\ProductionReceiptSourceAllocator;
use App\Modules\Wms\Support\ManualProductionReceiptContract;
use App\Modules\Wms\Support\WmsDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class ProductionFinishedReceiptController extends Controller
{
    public function __construct(private readonly ProductionReceiptSourceAllocator $sourceAllocator) {}

    public function index(Request $request): View
    {
        return view('Wms::production.finished-receipts.index', [
            'warehouse' => $request->attributes->get('selectedWarehouse'),
            'warehouses' => $this->warehouses($request),
        ]);
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock และบัญชีแล้ว', 'VOID' => 'ยกเลิกเอกสาร', 'REVERSED' => 'ยกเลิกเอกสารแล้ว'];
        $query = InventoryAdjustmentDocument::query()
            ->with(['lines.item:id,code,name', 'lines.uom:id,code', 'creator:id,name'])
            ->where('warehouse_id', $warehouseId)
            ->where('document_context', ManualProductionReceiptContract::CONTEXT);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('date_from')) {
            $query->where('document_date', '>=', $request->date('date_from')->startOfDay());
        }
        if ($request->filled('date_to')) {
            $query->where('document_date', '<', $request->date('date_to')->addDay()->startOfDay());
        }

        return DataTables::eloquent($query->latest('id'))
            ->addColumn('line_count', fn ($r) => $r->lines->count())
            ->addColumn('item_label', fn ($r) => $r->lines->map(fn ($line) => trim(($line->item?->code ?: '').' · '.($line->item?->name ?: '-'), ' ·'))->unique()->implode(', '))
            ->addColumn('business_date', fn ($r) => $r->document_date?->format((string) ($settings->value('date_format') ?: 'd/m/Y')) ?: '-')
            ->addColumn('direction_label', fn () => 'รับสินค้าผลิตเสร็จ')
            ->addColumn('status_label', fn ($r) => $labels[$r->status] ?? $r->status)
            ->addColumn('quantity', fn ($r) => WmsDecimal::format($r->lines->sum('quantity')))
            ->addColumn('value', fn ($r) => WmsDecimal::format($r->lines->sum('value')))
            ->addColumn('can_delete', fn ($r) => $r->status === 'DRAFT' && $request->user()->hasPermission('wms.inventory-adjustments.delete'))
            ->addColumn('show_url', fn ($r) => route('wms.production.finished-receipts.show', $r))
            ->addColumn('delete_url', fn ($r) => route('wms.production.finished-receipts.destroy', $r))
            ->toJson();
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = Item::query()->with('baseUom:id,code,name')->where('is_active', true)
            ->when($q, fn ($query) => $query->where(fn ($nested) => $nested->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['id', 'code', 'name', 'base_uom_id']);

        return response()->json(['results' => $rows->take(30)->map(fn ($row) => ['id' => $row->id, 'text' => $row->code.' · '.$row->name, 'base_uom_id' => $row->base_uom_id, 'base_uom_label' => trim(($row->baseUom?->code ?? '').' · '.($row->baseUom?->name ?? ''), ' ·')])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function sourceOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = IssueDocument::query()->with([
            'lines:id,document_id,stock_movement_id',
            'lines.costAllocations:id,stock_movement_id,cost_status,status,value',
        ])
            ->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)
            ->where('issue_type', 'PRODUCTION')->where('status', 'POSTED')
            ->when($q, fn ($query) => $query->where('document_number', 'like', '%'.addcslashes($q, '%_').'%'))
            ->orderByDesc('document_date')->orderByDesc('id')
            ->forPage(max(1, $request->integer('page', 1)), 31)
            ->get(['id', 'document_number', 'document_date']);
        $allocationIds = $rows->flatMap(fn ($row) => $row->lines->flatMap(fn ($line) => $line->costAllocations))->pluck('id')->all();
        $used = Schema::hasTable('wms_production_receipt_sources')
            ? DB::table('wms_production_receipt_sources')->whereIn('source_allocation_id', $allocationIds)
                ->when($request->integer('receipt_id'), fn ($query, int $id) => $query->where('receipt_document_id', '!=', $id))
                ->selectRaw('source_allocation_id, SUM(consumed_value) as consumed_value')->groupBy('source_allocation_id')->pluck('consumed_value', 'source_allocation_id')
            : collect();
        $results = $rows->map(function ($row) use ($used): array {
            $available = $row->lines->flatMap(fn ($line) => $line->costAllocations)
                ->where('status', '!=', 'REVERSED')->where('cost_status', 'FINAL')->reduce(
                    fn (BigDecimal $sum, $allocation): BigDecimal => $sum->plus(BigDecimal::of((string) $allocation->value)->abs()->minus((string) ($used->get($allocation->id) ?? '0'))),
                    BigDecimal::zero(),
                )->toScale(8, RoundingMode::HALF_UP)->__toString();

            return ['id' => (int) $row->id, 'text' => $row->document_number.' · '.$row->document_date?->format('d/m/Y'), 'available_value' => $available];
        })->filter(fn (array $row): bool => BigDecimal::of($row['available_value'])->isGreaterThan(0))->take(30)->values();

        return response()->json(['results' => $results, 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function create(Request $request): View
    {
        abort_unless($this->hasContextColumn(), 422, 'ยังไม่ได้เตรียมฐานข้อมูลสำหรับรับสินค้าผลิตเสร็จ กรุณากด Prepare Database จาก Installer ก่อน');
        [$sourceIssue, $sourceIssueCostTotal, $sourceIssueHasPendingCost] = $this->sourceIssue($request, $request->integer('source_issue_id'));
        $sourceSelections = $sourceIssue ? [['id' => (int) $sourceIssue->id, 'text' => $sourceIssue->document_number, 'available_value' => $sourceIssueCostTotal, 'consumed_value' => $sourceIssueCostTotal]] : [];

        return view('Wms::production.finished-receipts.create', compact('sourceIssue', 'sourceIssueCostTotal', 'sourceIssueHasPendingCost', 'sourceSelections') + ['document' => null, 'quantityDecimals' => WmsDecimal::places(), 'valueDecimals' => WmsDecimal::places()]);
    }

    public function edit(Request $request, InventoryAdjustmentDocument $document): View
    {
        $this->scope($request, $document);
        $this->assertProduction($document);
        abort_unless($document->status === 'DRAFT', 422, 'แก้ไขได้เฉพาะเอกสารร่าง');
        $document->load(['lines.item:id,code,name,base_uom_id', 'lines.item.baseUom:id,code,name', 'lines.uom:id,code,name']);
        [$sourceIssue, $sourceIssueCostTotal, $sourceIssueHasPendingCost] = $this->sourceIssue($request, (int) $document->source_issue_id);
        $sourceSelections = $this->sourceSelections($document, $sourceIssue, $sourceIssueCostTotal);

        return view('Wms::production.finished-receipts.create', compact('document', 'sourceIssue', 'sourceIssueCostTotal', 'sourceIssueHasPendingCost', 'sourceSelections') + ['quantityDecimals' => WmsDecimal::places(), 'valueDecimals' => WmsDecimal::places()]);
    }

    public function store(SaveInventoryAdjustmentRequest $request, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $values = $this->validated($request);
        $warehouse = $request->attributes->get('selectedWarehouse');
        $warehouse->loadMissing('branch');
        abort_unless($warehouse->branch, 422, 'คลังที่เลือกไม่มีสาขา');
        $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PRODUCTION_FINISHED_RECEIPT')->where('is_active', true)->first();
        abort_unless($sequence, 422, 'ยังไม่ได้ตั้งค่าเลขเอกสารใบรับสินค้าผลิตเสร็จ');
        $document = DB::transaction(function () use ($values, $warehouse, $request, $sequences, $sequence, $audit): InventoryAdjustmentDocument {
            $date = Carbon::parse($values['document_date']);
            $document = InventoryAdjustmentDocument::query()->create(['warehouse_id' => $warehouse->id, 'document_number' => $sequences->issueForBranch($sequence, $warehouse->branch, $date), 'document_date' => $date, 'direction' => 'GAIN', 'reason' => $values['reason'], 'idempotency_key' => 'production-receipt:'.bin2hex(random_bytes(12)), 'created_by' => $request->user()->id, 'document_context' => ManualProductionReceiptContract::CONTEXT, 'source_issue_id' => $values['source_issue_ids'][0]]);
            $sequences->recordIssued($sequence->fresh(), $document->document_number, 'inventory_adjustment_document', $document->id, $date, $request->user()->id);
            foreach ($values['lines'] as $position => $line) {
                $item = Item::query()->findOrFail($line['item_id']);
                abort_unless((int) $line['uom_id'] === (int) $item->base_uom_id, 422, 'รับผลิตต้องใช้หน่วยฐานของสินค้า');
                InventoryAdjustment::query()->create([...$line, 'direction' => 'GAIN', 'document_id' => $document->id, 'line_number' => $position + 1, 'warehouse_id' => $warehouse->id, 'business_date' => $date, 'reason' => $values['reason'], 'idempotency_key' => 'production-receipt:'.$document->id.':line:'.($position + 1), 'created_by' => $request->user()->id]);
            }
            $this->syncProductionSources($document, $values['source_consumptions']);
            $audit->record('wms.production_finished_receipt.created', $document, [], $document->load('lines')->toArray(), $request->user(), $request);

            return $document;
        });

        return response()->json(['status' => true, 'msg' => 'บันทึกร่างใบรับผลิตแล้ว', 'redirect' => route('wms.production.finished-receipts.show', $document)]);
    }

    public function update(SaveInventoryAdjustmentRequest $request, InventoryAdjustmentDocument $document, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $document);
        $this->assertProduction($document);
        abort_unless($document->status === 'DRAFT', 422, 'แก้ไขได้เฉพาะเอกสารร่าง');
        $values = $this->validated($request, $document);
        $before = $document->load('lines')->toArray();
        DB::transaction(function () use ($values, $document, $request, $audit, $before): void {
            $document->forceFill(['document_date' => Carbon::parse($values['document_date']), 'direction' => 'GAIN', 'reason' => $values['reason'], 'source_issue_id' => $values['source_issue_ids'][0]])->save();
            $document->lines()->forceDelete();
            foreach ($values['lines'] as $position => $line) {
                InventoryAdjustment::query()->create([...$line, 'direction' => 'GAIN', 'document_id' => $document->id, 'line_number' => $position + 1, 'warehouse_id' => $document->warehouse_id, 'business_date' => $document->document_date, 'reason' => $values['reason'], 'idempotency_key' => 'production-receipt:'.$document->id.':line:'.$position.':'.bin2hex(random_bytes(4)), 'created_by' => $document->created_by]);
            }
            $this->syncProductionSources($document, $values['source_consumptions']);
            $audit->record('wms.production_finished_receipt.updated', $document, $before, $document->fresh()->load('lines')->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขร่างใบรับผลิตแล้ว', 'redirect' => route('wms.production.finished-receipts.show', $document)]);
    }

    public function show(Request $request, InventoryAdjustmentDocument $document, GlobalSettings $settings, ManualProductionReceiptPostingService $posting): View
    {

        $this->scope($request, $document);
        $this->assertProduction($document);
        $document->load(['warehouse:id,code,name', 'lines.item:id,code,name', 'lines.uom:id,code,name', 'lines.movement', 'lines.allocation.journalEntry.lines.account:id,code,name', 'creator:id,name']);
        [$sourceIssue, $sourceIssueCostTotal] = $this->sourceIssue($request, (int) $document->source_issue_id);
        $totalQuantity = $document->lines->reduce(fn (BigDecimal $total, $line): BigDecimal => $total->plus((string) $line->quantity), BigDecimal::zero())->toScale(8, RoundingMode::HALF_UP);
        $totalValue = $document->lines->reduce(fn (BigDecimal $total, $line): BigDecimal => $total->plus((string) $line->value), BigDecimal::zero())->toScale(8, RoundingMode::HALF_UP);
        $lineUnitCosts = $document->lines->mapWithKeys(fn ($line): array => [$line->id => BigDecimal::of((string) $line->quantity)->isZero() ? '0' : BigDecimal::of((string) $line->value)->dividedBy(BigDecimal::of((string) $line->quantity), 8, RoundingMode::HALF_UP)->__toString()]);
        $postReadiness = $document->status === 'APPROVED'
            ? $posting->preflight($document->toArray())
            : ['ready' => $document->status === 'POSTED', 'blockers' => []];
        $productionReadiness = $document->status === 'APPROVED'
            ? $postReadiness
            : null;

        return view('Wms::production.finished-receipts.show', ['document' => $document, 'sourceIssue' => $sourceIssue, 'sourceIssueCostTotal' => $sourceIssueCostTotal, 'history' => AuditLog::query()->with('user:id,name')->where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->latest('created_at')->latest('id')->get(), 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'), 'postReadiness' => $postReadiness, 'receiptSummary' => ['quantity' => $totalQuantity->__toString(), 'value' => $totalValue->__toString(), 'average_unit_cost' => $totalQuantity->isZero() ? '0' : $totalValue->dividedBy($totalQuantity, 8, RoundingMode::HALF_UP)->__toString()], 'lineUnitCosts' => $lineUnitCosts, 'productionReadiness' => $productionReadiness]);
    }

    public function approve(Request $request, InventoryAdjustmentDocument $document, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $document);
        $this->assertProduction($document);
        abort_unless($document->status === 'DRAFT' && $document->lines()->exists(), 422, 'อนุมัติได้เฉพาะเอกสารร่างที่มีรายการ');
        $before = $document->toArray();
        DB::transaction(function () use ($document, $request, $audit, $before): void {
            $document->load('lines')->lines->each(fn ($line) => $line->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id])->save());
            $document->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id])->save();
            $audit->record('wms.production_finished_receipt.approved', $document, $before, $document->fresh()->load('lines')->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'อนุมัติใบรับผลิตแล้ว']);
    }

    public function post(Request $request, InventoryAdjustmentDocument $document, ManualProductionReceiptPostingService $posting): JsonResponse
    {
        $this->scope($request, $document);
        $this->assertProduction($document);
        $posting->post($document, $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'รับสินค้าผลิตเสร็จลง Stock และบัญชีแล้ว', 'redirect' => route('wms.production.finished-receipts.show', $document)]);
    }

    public function destroy(Request $request, InventoryAdjustmentDocument $document, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $document);
        $this->assertProduction($document);
        abort_unless($document->status === 'DRAFT', 422, 'ลบได้เฉพาะเอกสารร่าง');
        $before = $document->load('lines')->toArray();
        DB::transaction(function () use ($document, $audit, $before, $request): void {
            if (Schema::hasTable('wms_production_receipt_sources')) {
                DB::table('wms_production_receipt_sources')->where('receipt_document_id', $document->id)->delete();
            }
            $document->lines()->delete();
            $document->delete();
            $audit->record('wms.production_finished_receipt.deleted', $document, $before, [], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบร่างใบรับผลิตแล้ว', 'redirect' => route('wms.production.finished-receipts.index')]);
    }

    public function reverse(Request $request, InventoryAdjustmentDocument $document, ProductionFinishedReceiptReversalService $reversal): JsonResponse
    {
        $this->scope($request, $document);
        $this->assertProduction($document);
        $request->validate(['reversal_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $reversal->reverse($document, (string) $request->input('reversal_date'), (string) $request->input('reason'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'กลับรายการใบรับผลิตแล้ว', 'redirect' => route('wms.production.finished-receipts.show', $document)]);
    }

    private function validated(SaveInventoryAdjustmentRequest $request, ?InventoryAdjustmentDocument $document = null): array
    {
        $values = $request->validated();
        $values['document_context'] = ManualProductionReceiptContract::CONTEXT;
        $values['direction'] = 'GAIN';
        ManualProductionReceiptContract::assert($values);
        $sourceIds = collect($values['sources'] ?? [])->pluck('issue_id')->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        if ($sourceIds->isEmpty() && ! empty($values['source_issue_id'])) {
            $sourceIds->push((int) $values['source_issue_id']);
        }
        if ($sourceIds->isEmpty()) {
            throw ValidationException::withMessages(['sources' => 'กรุณาเลือกใบเบิกวัตถุดิบอย่างน้อยหนึ่งใบ']);
        }
        $sources = IssueDocument::query()->with([
            'lines:id,document_id,stock_movement_id',
            'lines.costAllocations:id,stock_movement_id,cost_status,status,revision,quantity,value',
        ])
            ->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)
            ->where('issue_type', 'PRODUCTION')->where('status', 'POSTED')->whereIn('id', $sourceIds)->get();
        if ($sources->count() !== $sourceIds->count()) {
            throw ValidationException::withMessages(['sources' => 'ใบเบิกต้นทางต้องเป็น Production, ลง Stock แล้ว และอยู่ในคลังเดียวกัน']);
        }
        $allocationIds = $sources->flatMap(fn ($source) => $source->lines->flatMap(fn ($line) => $line->costAllocations))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $prior = Schema::hasTable('wms_production_receipt_sources')
            ? DB::table('wms_production_receipt_sources')->whereIn('source_allocation_id', $allocationIds)
                ->when($document, fn ($query) => $query->where('receipt_document_id', '!=', $document->id))
                ->selectRaw('source_allocation_id, MIN(source_allocation_revision) as min_source_revision, MAX(source_allocation_revision) as max_source_revision, SUM(consumed_quantity) as consumed_quantity, SUM(consumed_value) as consumed_value')
                ->groupBy('source_allocation_id')->get()->keyBy('source_allocation_id')
            : collect();
        $requested = collect($values['sources'] ?? [])->keyBy(fn (array $source): int => (int) $source['issue_id']);
        $sourceTotal = BigDecimal::zero();
        $consumptions = [];
        foreach ($sources->sortBy('id') as $source) {
            $availableRows = [];
            foreach ($source->lines as $line) {
                foreach ($line->costAllocations->where('status', '!=', 'REVERSED')->sortBy('id') as $allocation) {
                    if ($allocation->cost_status !== 'FINAL') {
                        throw ValidationException::withMessages(['sources' => 'ต้นทุนใบเบิกต้นทางยังไม่เป็น Final กรุณารอคำนวณต้นทุนให้เสร็จก่อน']);
                    }
                    $used = $prior->get((int) $allocation->id);
                    if ($used && ((int) $used->min_source_revision !== (int) $allocation->revision
                        || (int) $used->max_source_revision !== (int) $allocation->revision)) {
                        throw ValidationException::withMessages(['sources' => 'ต้นทุนใบเบิกเคยถูกใช้และมี revision เปลี่ยน กรุณารอ Revaluation ให้เสร็จก่อน']);
                    }
                    $availableRows[] = [
                        'issue_document_id' => (int) $source->id,
                        'issue_line_id' => (int) $line->id,
                        'source_allocation_id' => (int) $allocation->id,
                        'source_allocation_revision' => (int) $allocation->revision,
                        'available_quantity' => BigDecimal::of((string) $allocation->quantity)->abs()->minus((string) ($used->consumed_quantity ?? '0'))->__toString(),
                        'available_value' => BigDecimal::of((string) $allocation->value)->abs()->minus((string) ($used->consumed_value ?? '0'))->__toString(),
                    ];
                }
            }
            $availableTotal = collect($availableRows)->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['available_value']), BigDecimal::zero());
            $wanted = (string) ($requested->get((int) $source->id)['consumed_value'] ?? $availableTotal->__toString());
            $allocated = $this->sourceAllocator->allocate($availableRows, $wanted);
            $consumptions = [...$consumptions, ...$allocated];
            $sourceTotal = $sourceTotal->plus($wanted);
        }
        $sourceTotal = $sourceTotal->toScale(8, RoundingMode::HALF_UP);
        $inputTotal = collect($values['lines'])->reduce(fn (BigDecimal $total, array $line): BigDecimal => $total->plus(BigDecimal::of((string) $line['value'])), BigDecimal::zero());
        $places = WmsDecimal::places();
        if (! $inputTotal->toScale($places, RoundingMode::HALF_UP)->isEqualTo($sourceTotal->toScale($places, RoundingMode::HALF_UP))) {
            throw ValidationException::withMessages(['lines' => 'มูลค่ารวมใบรับผลิตต้องเท่ากับต้นทุนวัตถุดิบต้นทางเมื่อปัดตาม Global Setting']);
        }
        $last = array_key_last($values['lines']);
        $running = BigDecimal::zero();
        foreach ($values['lines'] as $position => &$line) {
            $line['value'] = $position === $last ? $sourceTotal->minus($running)->toScale(8, RoundingMode::HALF_UP)->__toString() : BigDecimal::of((string) $line['value'])->toScale(8, RoundingMode::HALF_UP)->__toString();
            $running = $running->plus($line['value']);
        }
        unset($line);
        $values['source_issue_ids'] = $sourceIds->all();
        $values['source_consumptions'] = $consumptions;

        return $values;
    }

    private function sourceIssue(Request $request, int $id): array
    {
        if ($id < 1 || ! Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')) {
            return [null, '0', false];
        }
        $source = IssueDocument::query()->with(['lines.item:id,code,name', 'lines.uom:id,code,name', 'lines.movement', 'lines.costAllocations'])->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->where('issue_type', 'PRODUCTION')->where('status', 'POSTED')->find($id);
        $total = $source?->lines->reduce(function (BigDecimal $sum, $line): BigDecimal {
            $allocations = $line->costAllocations->where('status', '!=', 'REVERSED');
            $lineTotal = $allocations->reduce(fn (BigDecimal $lineTotal, $allocation): BigDecimal => $lineTotal->plus(BigDecimal::of((string) $allocation->value)->abs()), BigDecimal::zero())->toScale(8)->__toString();
            $lineStatus = $allocations->isNotEmpty() && $allocations->every(fn ($allocation): bool => $allocation->cost_status === 'FINAL') ? 'FINAL' : 'PENDING';
            // Keep the existing view contract while presenting the aggregate
            // of all FIFO layers for this issue movement.
            $line->setRelation('allocation', (object) ['value' => $lineTotal, 'cost_status' => $lineStatus]);
            $line->setAttribute('source_cost_total', $lineTotal);
            $line->setAttribute('source_cost_status', $lineStatus);

            return $sum->plus(BigDecimal::of((string) $line->source_cost_total));
        }, BigDecimal::zero())->toScale(8)->__toString() ?? '0';

        return [$source, $total, (bool) $source?->lines->contains(fn ($line): bool => ($line->source_cost_status ?? 'PENDING') !== 'FINAL')];
    }

    /** @param list<array<string,mixed>> $sources */
    private function syncProductionSources(InventoryAdjustmentDocument $document, array $sources): void
    {
        if (! Schema::hasTable('wms_production_receipt_sources')) {
            return;
        }

        $now = now();
        $payload = collect($sources)->values()->map(fn (array $source, int $position): array => [
            'receipt_document_id' => (int) $document->id,
            'issue_document_id' => (int) $source['issue_document_id'],
            'issue_line_id' => (int) $source['issue_line_id'],
            'source_allocation_id' => (int) $source['source_allocation_id'],
            'source_allocation_revision' => (int) $source['source_allocation_revision'],
            'consumed_quantity' => (string) $source['consumed_quantity'],
            'consumed_value' => (string) $source['consumed_value'],
            'position' => $position + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('wms_production_receipt_sources')->where('receipt_document_id', $document->id)->delete();
        if ($payload !== []) {
            DB::table('wms_production_receipt_sources')->insert($payload);
        }
    }

    private function assertProduction(InventoryAdjustmentDocument $document): void
    {
        abort_unless($document->document_context === ManualProductionReceiptContract::CONTEXT, 404);
    }

    private function sourceSelections(InventoryAdjustmentDocument $document, ?IssueDocument $legacy, string $legacyTotal): array
    {
        if (! Schema::hasTable('wms_production_receipt_sources')) {
            return $legacy ? [['id' => (int) $legacy->id, 'text' => $legacy->document_number, 'available_value' => $legacyTotal, 'consumed_value' => $legacyTotal]] : [];
        }

        $selected = DB::table('wms_production_receipt_sources as source')
            ->join('wms_issue_documents as issue', 'issue.id', '=', 'source.issue_document_id')
            ->where('source.receipt_document_id', $document->id)
            ->selectRaw('issue.id, issue.document_number as text, SUM(source.consumed_value) as consumed_value')
            ->groupBy('issue.id', 'issue.document_number')->orderBy('issue.id')->get();
        $allocations = DB::table('wms_issue_lines as line')->join('wms_cost_allocations as allocation', 'allocation.stock_movement_id', '=', 'line.stock_movement_id')
            ->whereIn('line.document_id', $selected->pluck('id')->all())->whereNull('line.deleted_at')->where('allocation.status', '!=', 'REVERSED')
            ->get(['line.document_id as issue_document_id', 'allocation.id', 'allocation.value']);
        $otherUsed = DB::table('wms_production_receipt_sources')->whereIn('source_allocation_id', $allocations->pluck('id')->all())
            ->where('receipt_document_id', '!=', $document->id)->selectRaw('source_allocation_id, SUM(consumed_value) as consumed_value')->groupBy('source_allocation_id')->pluck('consumed_value', 'source_allocation_id');

        return $selected->map(function ($row) use ($allocations, $otherUsed): array {
            $available = $allocations->where('issue_document_id', $row->id)->reduce(fn (BigDecimal $sum, $allocation): BigDecimal => $sum->plus(BigDecimal::of((string) $allocation->value)->abs()->minus((string) ($otherUsed->get($allocation->id) ?? '0'))), BigDecimal::zero());

            return ['id' => (int) $row->id, 'text' => (string) $row->text, 'available_value' => $available->toScale(8, RoundingMode::HALF_UP)->__toString(), 'consumed_value' => (string) $row->consumed_value];
        })->all();
    }

    private function scope(Request $request, InventoryAdjustmentDocument $document): void
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $branch = $request->attributes->get('selectedBranch');
        abort_unless((int) $document->warehouse_id === (int) $warehouse->id && (int) $document->branch_id === (int) $branch->id, 404);
    }

    private function hasContextColumn(): bool
    {
        return Schema::hasColumn('wms_inventory_adjustment_documents', 'document_context');
    }

    private function warehouses(Request $request)
    {
        return $request->user()->warehouses()->where('is_active', true)->where('branch_id', $request->attributes->get('selectedBranch')->id)->orderBy('name')->get(['warehouses.id', 'warehouses.code', 'warehouses.name']);
    }
}
