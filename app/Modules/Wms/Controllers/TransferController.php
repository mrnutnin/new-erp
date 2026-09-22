<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Transfer;
use App\Modules\Wms\Services\TransferMovementService;
use App\Modules\Wms\Services\StockBalanceService;
use App\Modules\Wms\Support\WmsDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class TransferController extends Controller
{
    private const STATUS_LABELS = [
        'DRAFT' => 'ร่าง',
        'DISPATCHED' => 'ส่งออกแล้ว',
        'PARTIALLY_ACCEPTED' => 'รับบางส่วน',
        'ACCEPTED' => 'รับครบแล้ว',
        'REJECTED' => 'ปฏิเสธ',
        'VOID' => 'ยกเลิก',
    ];

    private const STATUS_CLASSES = [
        'DRAFT' => 'app-status-neutral',
        'DISPATCHED' => 'app-status-info',
        'PARTIALLY_ACCEPTED' => 'app-status-warning',
        'ACCEPTED' => 'app-status-success',
        'REJECTED' => 'app-status-danger',
        'VOID' => 'app-status-danger',
    ];

    private const RECEIPT_STATUS_LABELS = [
        'DRAFT' => 'ยังไม่ส่งออก',
        'DISPATCHED' => 'รอรับเข้าปลายทาง',
        'PARTIALLY_ACCEPTED' => 'รับเข้าบางส่วน',
        'ACCEPTED' => 'รับเข้าครบแล้ว',
        'REJECTED' => 'ปลายทางปฏิเสธ',
        'VOID' => 'ยกเลิก',
    ];

    public function index(Request $request): View
    {
        $direction = (string) ($request->route('direction') ?: 'all');
        abort_unless(in_array($direction, ['all', 'out', 'in'], true), 404);

        return view('Wms::transfers.index', [
            'direction' => $direction,
            'warehouse' => $request->attributes->get('selectedWarehouse'),
            'warehouses' => $this->warehouses($request),
            'branches' => $this->branches($request),
            'transferStatusLabels' => self::STATUS_LABELS,
            'transferStatusClasses' => self::STATUS_CLASSES,
        ]);
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $direction = (string) ($request->route('direction') ?: $request->query('direction', 'all'));
        $query = Transfer::query()
            ->with(['sourceWarehouse:id,branch_id,code,name', 'sourceWarehouse.branch:id,code,name', 'destinationWarehouse:id,branch_id,code,name', 'destinationWarehouse.branch:id,code,name', 'events:id,transfer_id,event_type,base_quantity'])
            ->withSum('lines as planned_base_quantity_total', 'planned_base_quantity');
        if ($request->filled('status') && in_array($request->string('status')->toString(), ['DRAFT', 'DISPATCHED', 'PARTIALLY_ACCEPTED', 'ACCEPTED', 'REJECTED', 'VOID'], true)) $query->where('status', $request->string('status')->toString());
        if ($request->filled('date_from')) $query->whereDate('document_date', '>=', $request->date('date_from'));
        if ($request->filled('date_to')) $query->whereDate('document_date', '<=', $request->date('date_to'));
        $branchIds = $this->branches($request)->pluck('id');
        if ($request->filled('source_branch_id') && $branchIds->contains((int) $request->input('source_branch_id'))) {
            $query->whereHas('sourceWarehouse', fn ($warehouse) => $warehouse->where('branch_id', (int) $request->input('source_branch_id')));
        }
        if ($request->filled('destination_branch_id') && $branchIds->contains((int) $request->input('destination_branch_id'))) {
            $query->whereHas('destinationWarehouse', fn ($warehouse) => $warehouse->where('branch_id', (int) $request->input('destination_branch_id')));
        }
        if ($direction === 'out') {
            $query->where('source_warehouse_id', $warehouseId);
        } elseif ($direction === 'in') {
            $query->where('destination_warehouse_id', $warehouseId);
        } else {
            $query->where(fn ($q) => $q->where('source_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId));
        }
        $table = DataTables::eloquent($query)
            ->addColumn('source_label', fn (Transfer $r) => $this->warehouseLabel($r->sourceWarehouse))
            ->addColumn('destination_label', fn (Transfer $r) => $this->warehouseLabel($r->destinationWarehouse))
            ->addColumn('status_label', fn (Transfer $r) => self::STATUS_LABELS[$r->status] ?? $r->status)
            ->addColumn('destination_receipt_status', fn (Transfer $r) => $this->receiptStatusLabel($r->status))
            ->addColumn('destination_receipt_summary', function (Transfer $r): string {
                $dispatched = $r->events->where('event_type', 'DISPATCH')->sum('base_quantity');
                $accepted = $r->events->where('event_type', 'ACCEPT')->sum('base_quantity');
                $rejected = $r->events->where('event_type', 'REJECT')->sum('base_quantity');

                return 'ส่งออก '.WmsDecimal::format($dispatched).' · รับแล้ว '.WmsDecimal::format($accepted).' · ปฏิเสธ '.WmsDecimal::format($rejected);
            })
            ->addColumn('destination_receipt_search', fn (Transfer $r) => $this->receiptStatusLabel($r->status).' '.$this->receiptSearchLabel($r))
            ->editColumn('document_date', fn (Transfer $r) => $r->document_date?->format((string) $settings->value('date_format')) ?: '-')
            ->addColumn('can_delete', fn (Transfer $r) => $request->user()->hasPermission('wms.transfers.delete') && $r->status === 'DRAFT' && (int) $r->source_warehouse_id === $warehouseId && $r->events->isEmpty())
            ->addColumn('delete_url', fn (Transfer $r) => route('wms.transfers.destroy', $r))
            ->addColumn('detail_url', fn (Transfer $r) => route('wms.transfers.show', $r))
            ->addColumn('edit_url', fn (Transfer $r) => route('wms.transfers.edit', $r))
            ->addColumn('can_edit', fn (Transfer $r) => $r->status === 'DRAFT' && (int) $r->source_warehouse_id === $warehouseId && $request->user()->hasPermission('wms.transfers.update'))
            ->filterColumn('document_date', fn ($query, $keyword) => $this->filterDocumentDate($query, $keyword, (string) $settings->value('date_format')))
            ->filterColumn('source_label', fn ($query, $keyword) => $this->filterWarehouseLabel($query, 'sourceWarehouse', $keyword))
            ->filterColumn('destination_label', fn ($query, $keyword) => $this->filterWarehouseLabel($query, 'destinationWarehouse', $keyword))
            ->filterColumn('status_label', fn ($query, $keyword) => $this->filterStatusLabel($query, $keyword, self::STATUS_LABELS))
            ->filterColumn('destination_receipt_search', fn ($query, $keyword) => $this->filterReceiptSearch($query, $keyword));

        return $table->toJson();
    }

    public function show(Request $request, Transfer $transfer, GlobalSettings $settings): View
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        abort_unless((int) $transfer->source_warehouse_id === $warehouseId || (int) $transfer->destination_warehouse_id === $warehouseId, 404);

        $transfer->load([
            'sourceWarehouse:id,code,name',
            'destinationWarehouse:id,code,name',
            'lines:id,transfer_id,planned_base_quantity',
            'events:id,transfer_id,transfer_line_id,event_type,base_quantity,business_date,reason,created_by,created_at',
            'events.creator:id,name',
            'events.line:id,line_number',
            'photos:id,transfer_id,stage,original_name,mime_type,uploaded_by,created_at',
            'photos.uploadedBy:id,name',
        ]);

        $receiptSummary = $this->receiptSummary($transfer);

        return view('Wms::transfers.show', [
            'transfer' => $transfer,
            'lines' => $this->linePayloads($transfer, $settings),
            'receiptSummary' => $receiptSummary,
            'dateFormat' => (string) $settings->value('date_format'),
            'transferStatusLabels' => self::STATUS_LABELS,
            'transferStatusClasses' => self::STATUS_CLASSES,
            'receiptStatusLabels' => self::RECEIPT_STATUS_LABELS,
            'transferPhotos' => $transfer->photos->groupBy('stage'),
            'canUploadOutgoingPhotos' => (int) $transfer->source_warehouse_id === $warehouseId
                && $request->user()->hasPermission('wms.transfers.dispatch')
                && $transfer->status === 'DRAFT',
            'canUploadIncomingPhotos' => (int) $transfer->destination_warehouse_id === $warehouseId
                && $request->user()->hasPermission('wms.transfers.complete')
                && in_array($transfer->status, ['DISPATCHED', 'PARTIALLY_ACCEPTED'], true),
        ]);
    }

    public function destroy(Request $request, Transfer $transfer, AuditLogger $audit): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        abort_unless((int) $transfer->source_warehouse_id === $warehouseId, 404);
        abort_unless($transfer->status === 'DRAFT' && ! $transfer->events()->exists(), 422, 'ลบได้เฉพาะ Transfer ร่างที่ยังไม่มีประวัติการเคลื่อนไหว');
        $before = $transfer->load('lines')->toArray();
        $transfer->delete();
        $audit->record('wms.transfer.deleted', $transfer, $before, [], $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบร่างใบโอนสินค้าแล้ว', 'redirect' => route('wms.transfers.outgoing.index')]);
    }

    public function receive(Request $request, Transfer $transfer, GlobalSettings $settings): View
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        abort_unless((int) $transfer->destination_warehouse_id === $warehouseId, 404);
        abort_unless(in_array($transfer->status, ['DISPATCHED', 'PARTIALLY_ACCEPTED'], true), 404);

        $transfer->load([
            'sourceWarehouse:id,name',
            'destinationWarehouse:id,name',
            'photos:id,transfer_id,stage,original_name,mime_type,uploaded_by,created_at',
            'photos.uploadedBy:id,name',
        ]);

        return view('Wms::transfers.receive', [
            'transfer' => $transfer,
            'lines' => $this->linePayloads($transfer, $settings),
            'dateFormat' => (string) $settings->value('date_format'),
            'decimalPlaces' => WmsDecimal::places(),
            'transferPhotos' => $transfer->photos->groupBy('stage'),
            'canUploadOutgoingPhotos' => false,
            'canUploadIncomingPhotos' => true,
        ]);
    }

    public function lines(Request $request, Transfer $transfer, GlobalSettings $settings): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        abort_unless((int) $transfer->source_warehouse_id === $warehouseId || (int) $transfer->destination_warehouse_id === $warehouseId, 404);

        $lines = $this->linePayloads($transfer, $settings);

        return response()->json([
            'document_date' => $transfer->document_date?->format('Y-m-d'),
            'lines' => $lines,
        ]);
    }

    public function create(Request $request): View
    {
        $sourceWarehouse = $request->attributes->get('selectedWarehouse')->loadMissing('branch');
        $warehouses = $request->user()->warehouses()
            ->where('is_active', true)
            ->with('branch:id,code,name')
            ->get(['warehouses.id', 'warehouses.name', 'warehouses.branch_id']);

        return view('Wms::transfers.form', ['transfer' => new Transfer(), 'sourceWarehouse' => $sourceWarehouse, 'warehouses' => $warehouses]);
    }

    public function edit(Request $request, Transfer $transfer): View
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        abort_unless((int) $transfer->source_warehouse_id === $warehouseId, 404);
        abort_unless($transfer->status === 'DRAFT' && ! $transfer->events()->exists(), 422, 'แก้ไขได้เฉพาะใบโอนร่างที่ยังไม่มีการเคลื่อนไหว');
        $transfer->load(['lines.item:id,code,name,base_uom_id', 'lines.uom:id,code,name']);
        $sourceWarehouse = $request->attributes->get('selectedWarehouse')->loadMissing('branch');

        return view('Wms::transfers.form', [
            'transfer' => $transfer,
            'sourceWarehouse' => $sourceWarehouse,
            'warehouses' => $this->destinationWarehouses($request, $warehouseId)->get(),
        ]);
    }

    public function itemOptions(Request $request, StockBalanceService $balances): JsonResponse
    {
        $input = $request->validate(['warehouse_id' => ['required', 'integer', 'min:1'], 'item_id' => ['nullable', 'integer', 'min:1']]);
        $sourceId = $input['warehouse_id'];
        $itemId = $input['item_id'] ?? null;
        $request->user()->warehouses()->where('is_active', true)->whereKey($sourceId)->firstOrFail();
        $q = trim((string) $request->input('q'));
        $rows = Item::query()->with('baseUom:id,code,name')->where('is_active', true)->where('is_stock_item', true)
            ->when($itemId, fn ($query) => $query->whereKey($itemId))
            ->when(! $itemId && $q, fn ($query) => $query->where(fn ($search) => $search->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['id', 'code', 'name', 'base_uom_id']);

        return response()->json(['results' => $rows->take(30)->map(function (Item $item) use ($balances, $sourceId): array {
            $balance = $balances->forItem((int) $sourceId, (int) $item->id, (int) $item->base_uom_id);
            return ['id' => $item->id, 'text' => $item->code.' · '.$item->name, 'uom_id' => $item->base_uom_id, 'uom_label' => $item->baseUom?->code ?: $item->baseUom?->name ?: '-', 'available_quantity' => $balance['available'], 'available_label' => 'คงเหลือ '.WmsDecimal::format($balance['available'])];
        })->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function store(Request $request, TransferMovementService $service, DocumentSequenceService $sequences): JsonResponse
    {
        $values = $request->validate(['source_warehouse_id' => ['required', 'integer', 'min:1'], 'destination_warehouse_id' => ['required', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['nullable', 'string', 'max:1000'], 'idempotency_key' => ['required', 'string', 'max:160'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.item_id' => ['required', 'integer', 'min:1'], 'lines.*.uom_id' => ['nullable', 'integer', 'min:1'], 'lines.*.planned_quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.planned_base_quantity' => ['nullable', 'numeric', 'gt:0']]);
        $sourceWarehouse = $request->user()->warehouses()->where('is_active', true)->whereKey($values['source_warehouse_id'])->with('branch')->firstOrFail();
        $source = (int) $sourceWarehouse->id;
        $this->destinationWarehouses($request, $source)->whereKey($values['destination_warehouse_id'])->firstOrFail();
        $warehouse = $sourceWarehouse;
        $existing = Transfer::query()->where('source_warehouse_id', $source)->where('idempotency_key', $values['idempotency_key'])->first();
        $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'WMS_TRANSFER')->where('is_active', true)->lockForUpdate()->first();
        if (! $sequence || ! $warehouse->branch) {
            throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบโอนสินค้าสำหรับสาขานี้']);
        }
        $number = $existing?->document_number ?? $sequences->issueAvailableForBranch($sequence, $warehouse->branch, Carbon::parse($values['document_date']), fn (string $candidate): bool => Transfer::query()->where('document_number', $candidate)->exists());
        $transfer = $service->createDraft([...$values, 'source_warehouse_id' => $source, 'document_number' => $number], $values['lines'], $request->user()->id);
        if (! $existing && $transfer->document_number === $number) {
            $sequences->recordIssued($sequence, $number, 'wms_transfers', $transfer->id, Carbon::parse($values['document_date']), $request->user()->id);
        }

        return response()->json(['status' => true, 'msg' => "สร้างใบโอนสินค้าออก {$transfer->document_number} แล้ว", 'redirect' => route('wms.transfers.outgoing.index')]);
    }

    public function update(Request $request, Transfer $transfer, TransferMovementService $service, AuditLogger $audit): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        abort_unless((int) $transfer->source_warehouse_id === $warehouseId, 404);
        $values = $request->validate([
            'destination_warehouse_id' => ['required', 'integer', 'min:1'],
            'document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'min:1'],
            'lines.*.uom_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.planned_quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.planned_base_quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);
        $this->destinationWarehouses($request, $warehouseId)->whereKey($values['destination_warehouse_id'])->firstOrFail();

        DB::transaction(function () use ($request, $transfer, $values, $service, $audit): void {
            $before = $transfer->fresh()->load('lines')->toArray();
            $updated = $service->updateDraft($transfer, $values, $values['lines']);
            $audit->record('wms.transfer.updated', $updated, $before, $updated->fresh()->load('lines')->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขร่างใบโอนสินค้าแล้ว', 'redirect' => route('wms.transfers.show', $transfer)]);
    }

    public function dispatch(Request $request, Transfer $transfer, TransferMovementService $service): JsonResponse
    {
        $values = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'business_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $service->dispatch($transfer, (int) $request->attributes->get('selectedWarehouse')->id, $request->user(), $values['reason'], $values['business_date'] ?? null);

        return response()->json(['status' => true, 'msg' => 'ส่งออกจากคลังแล้ว']);
    }

    public function complete(Request $request, Transfer $transfer, TransferMovementService $service): JsonResponse
    {
        $values = $request->validate(['action' => ['required', 'in:accept,reject'], 'command_key' => ['required', 'string', 'max:100'], 'reason' => ['nullable', 'string', 'max:1000'], 'business_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'full_receipt' => ['required', 'accepted']]);
        if ($values['action'] === 'reject' && trim((string) ($values['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลเมื่อปฏิเสธ Transfer']);
        }
        if ((bool) ($values['full_receipt'] ?? false)) {
            $values['quantities'] = $this->linePayloads($transfer, app(GlobalSettings::class))->mapWithKeys(fn (array $line) => [$line['id'] => $line['remaining_base_quantity']])->filter(fn ($quantity) => BigDecimal::of((string) $quantity)->isPositive())->all();
        }
        if (empty($values['quantities'])) {
            throw ValidationException::withMessages(['quantities' => 'ไม่พบรายการที่ยังรอดำเนินการ']);
        }
        $method = $values['action'] === 'accept' ? 'accept' : 'reject';
        $service->{$method}($transfer, (int) $request->attributes->get('selectedWarehouse')->id, $request->user(), $values['quantities'], $values['command_key'], $values['reason'] ?? '', $values['business_date'] ?? null);

        return response()->json(['status' => true, 'msg' => $method === 'accept' ? 'รับโอนสินค้าแล้ว' : 'ปฏิเสธการรับสินค้าแล้ว']);
    }

    public function void(Request $request, Transfer $transfer, TransferMovementService $service): JsonResponse
    {
        $values = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $service->voidRejected($transfer, (int) $request->attributes->get('selectedWarehouse')->id, $request->user(), $values['reason']);

        return response()->json(['status' => true, 'msg' => 'ยกเลิกเอกสารที่ถูกปฏิเสธแล้ว สามารถสร้างรายการใหม่ได้']);
    }

    private function linePayloads(Transfer $transfer, GlobalSettings $settings)
    {
        $places = WmsDecimal::places();

        return $transfer->lines()
            ->with(['item:id,code,name', 'uom:id,code,name', 'events:id,transfer_line_id,event_type,base_quantity'])
            ->get(['id', 'item_id', 'uom_id', 'line_number', 'planned_quantity', 'planned_base_quantity'])
            ->map(function ($line) use ($places): array {
                $sum = static fn ($events): BigDecimal => $events->reduce(static fn (BigDecimal $total, $event): BigDecimal => $total->plus((string) $event->base_quantity), BigDecimal::zero());
                $dispatched = $sum($line->events->where('event_type', 'DISPATCH'));
                $accepted = $sum($line->events->where('event_type', 'ACCEPT'));
                $rejected = $sum($line->events->where('event_type', 'REJECT'));
                $remaining = $dispatched->minus($accepted)->minus($rejected);
                if ($remaining->isNegative()) {
                    $remaining = BigDecimal::zero();
                }
                $format = static fn (BigDecimal $value): string => $value->toScale($places, RoundingMode::HALF_UP)->__toString();

                return ['id' => $line->id, 'line_number' => $line->line_number, 'item_label' => trim(($line->item?->code ?: '').' · '.($line->item?->name ?: 'ไม่พบสินค้า'), ' ·'), 'uom_label' => $line->uom?->code ?: $line->uom?->name ?: '-', 'planned_base_quantity' => $format(BigDecimal::of((string) $line->planned_base_quantity)), 'dispatched_base_quantity' => $format($dispatched), 'accepted_base_quantity' => $format($accepted), 'rejected_base_quantity' => $format($rejected), 'remaining_base_quantity' => $format($remaining)];
            })->values();
    }

    private function destinationWarehouses(Request $request, int $sourceWarehouseId)
    {
        return $request->user()->warehouses()
            ->where('is_active', true)
            ->where('branch_id', Warehouse::query()->whereKey($sourceWarehouseId)->value('branch_id'))
            ->whereKeyNot($sourceWarehouseId)
            ->orderBy('name');
    }

    private function warehouses(Request $request)
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', $request->attributes->get('selectedBranch')->id)
            ->orderBy('name')->get(['warehouses.id', 'warehouses.code', 'warehouses.name']);
    }

    private function branches(Request $request)
    {
        $branches = $request->user()->branches()->where('branches.is_active', true)->orderBy('code')->get(['branches.id', 'branches.code', 'branches.name']);
        if ($branches->isNotEmpty()) {
            return $branches;
        }

        return $request->user()->warehouses()
            ->where('warehouses.is_active', true)
            ->with('branch:id,code,name')
            ->get()
            ->pluck('branch')
            ->filter()
            ->unique('id')
            ->sortBy('code')
            ->values();
    }

    private function warehouseLabel(?\App\Models\Warehouse $warehouse): string
    {
        if (! $warehouse) {
            return '-';
        }

        return collect([$warehouse->code, $warehouse->name, $warehouse->branch?->code ?: $warehouse->branch?->name])
            ->filter()
            ->implode(' · ');
    }

    private function receiptSummary(Transfer $transfer): array
    {
        $sum = static fn ($events): BigDecimal => $events->reduce(static fn (BigDecimal $total, $event): BigDecimal => $total->plus((string) $event->base_quantity), BigDecimal::zero());
        $dispatched = $sum($transfer->events->where('event_type', 'DISPATCH'));
        $accepted = $sum($transfer->events->where('event_type', 'ACCEPT'));
        $rejected = $sum($transfer->events->where('event_type', 'REJECT'));
        $planned = $transfer->lines->reduce(static fn (BigDecimal $total, $line): BigDecimal => $total->plus((string) $line->planned_base_quantity), BigDecimal::zero());
        $remaining = $planned->minus($accepted)->minus($rejected);
        if ($remaining->isNegative()) {
            $remaining = BigDecimal::zero();
        }

        return collect(['planned' => $planned, 'dispatched' => $dispatched, 'accepted' => $accepted, 'rejected' => $rejected, 'remaining' => $remaining])
            ->map(fn (BigDecimal $value): string => WmsDecimal::format($value->__toString()))
            ->all();
    }

    private function receiptStatusLabel(string $status): string
    {
        return self::RECEIPT_STATUS_LABELS[$status] ?? $status;
    }

    private function receiptSearchLabel(Transfer $transfer): string
    {
        $dispatched = $transfer->events->where('event_type', 'DISPATCH')->sum('base_quantity');
        $accepted = $transfer->events->where('event_type', 'ACCEPT')->sum('base_quantity');
        $rejected = $transfer->events->where('event_type', 'REJECT')->sum('base_quantity');

        return 'ส่งออก '.WmsDecimal::format($dispatched).' รับแล้ว '.WmsDecimal::format($accepted).' ปฏิเสธ '.WmsDecimal::format($rejected);
    }

    private function filterDocumentDate($query, string $keyword, string $dateFormat): void
    {
        $mysqlFormat = match ($dateFormat) {
            'Y-m-d' => '%Y-%m-%d',
            'm/d/Y' => '%m/%d/%Y',
            default => '%d/%m/%Y',
        };

        $query->whereRaw("DATE_FORMAT(document_date, '{$mysqlFormat}') LIKE ?", ['%'.trim($keyword).'%']);
    }

    private function filterWarehouseLabel($query, string $relation, string $keyword): void
    {
        $keyword = '%'.trim($keyword).'%';

        $query->whereHas($relation, function ($warehouse) use ($keyword): void {
            $warehouse->where(function ($match) use ($keyword): void {
                $match->where('code', 'like', $keyword)
                    ->orWhere('name', 'like', $keyword)
                    ->orWhereHas('branch', fn ($branch) => $branch->where('code', 'like', $keyword)->orWhere('name', 'like', $keyword));
            });
        });
    }

    private function filterStatusLabel($query, string $keyword, array $labels): void
    {
        $keyword = trim($keyword);
        $matches = $this->matchingStatuses($labels, $keyword);

        $query->where(function ($match) use ($keyword, $matches): void {
            $match->where('status', 'like', '%'.$keyword.'%');
            if ($matches !== []) {
                $match->orWhereIn('status', $matches);
            }
        });
    }

    private function filterReceiptSearch($query, string $keyword): void
    {
        $keyword = trim($keyword);
        $matches = $this->matchingStatuses(self::RECEIPT_STATUS_LABELS, $keyword);
        $number = str_replace(',', '', $keyword);
        $isNumber = preg_match('/^\d+(?:\.\d+)?$/', $number) === 1;
        $isStaticSummaryLabel = in_array($keyword, ['ส่ง', 'ส่งออก', 'รับ', 'รับแล้ว', 'ปฏิเสธ'], true);

        $query->where(function ($match) use ($keyword, $matches, $number, $isNumber, $isStaticSummaryLabel): void {
            if ($isStaticSummaryLabel) {
                // Every rendered summary contains this label, so preserve that visible-search behaviour.
                $match->whereRaw('1 = 1');

                return;
            }
            if ($matches !== []) {
                $match->orWhereIn('status', $matches);
            }
            if ($isNumber) {
                $match->orWhereHas('events', fn ($events) => $events->whereRaw('CAST(base_quantity AS CHAR) LIKE ?', ['%'.$number.'%']))
                    ->orWhereHas('lines', fn ($lines) => $lines->whereRaw('CAST(planned_base_quantity AS CHAR) LIKE ?', ['%'.$number.'%']));
            }
            if ($matches === [] && ! $isNumber) {
                $match->whereRaw('1 = 0');
            }
        });
    }

    private function matchingStatuses(array $labels, string $keyword): array
    {
        $needle = mb_strtolower($keyword);

        return collect($labels)
            ->filter(fn (string $label, string $status): bool => str_contains(mb_strtolower($status), $needle) || mb_stripos($label, $keyword) !== false)
            ->keys()
            ->all();
    }
}
