<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\OpeningBalanceBatch;
use App\Modules\Wms\Requests\SaveOpeningBalanceRequest;
use App\Modules\Wms\Requests\StageOpeningBalanceImportRequest;
use App\Modules\Wms\Services\OpeningBalanceService;
use App\Modules\Wms\Services\OpeningBalanceImportService;
use App\Modules\Wms\Support\WmsDecimal;
use App\Modules\Wms\Support\OpeningBalanceTemplate;
use App\Modules\Platform\Models\MigrationImportBatch;
use App\Modules\Platform\Services\SpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class OpeningBalanceController extends Controller
{
    public function index(): View { return view('Wms::opening-balances.index'); }

    public function template(SpreadsheetService $spreadsheets): BinaryFileResponse
    {
        return $spreadsheets->download('wms-opening-balance-'.OpeningBalanceTemplate::VERSION.'.xlsx', OpeningBalanceTemplate::sheets());
    }

    public function importStage(StageOpeningBalanceImportRequest $request, OpeningBalanceImportService $imports): JsonResponse
    {
        $batch = $imports->stage($request->file('file'), $request->user(), $request->validated());
        return response()->json(['status' => true, 'msg' => 'อัปโหลดและตรวจสอบข้อมูลแล้ว', 'redirect' => route('wms.opening-balances.import.show', $batch)]);
    }

    public function importShow(Request $request, MigrationImportBatch $batch): View
    {
        abort_unless($batch->type === OpeningBalanceTemplate::TYPE && (int) $batch->created_by === (int) $request->user()->id, 404);
        return view('Wms::opening-balances.import-show', compact('batch'));
    }

    public function importErrors(Request $request, MigrationImportBatch $batch, SpreadsheetService $spreadsheets): BinaryFileResponse
    {
        abort_unless($batch->type === OpeningBalanceTemplate::TYPE && (int) $batch->created_by === (int) $request->user()->id, 404);
        $rows = collect($batch->staged_rows)->filter(fn (array $row) => $row['errors'] !== [])->map(fn (array $row) => [
            $row['row_number'],
            $row['normalized']['row_key'],
            $row['normalized']['branch_code'],
            $row['normalized']['warehouse_code'],
            $row['normalized']['item_code'],
            implode(' | ', $row['errors']),
        ])->values()->all();

        return $spreadsheets->download("wms-opening-balance-{$batch->id}-errors.xlsx", [[
            'title' => 'Errors',
            'headings' => ['row_number', 'row_key', 'branch_code', 'warehouse_code', 'item_code', 'errors'],
            'rows' => $rows,
        ]]);
    }

    public function importCommit(Request $request, MigrationImportBatch $batch, OpeningBalanceImportService $imports, OpeningBalanceService $openingBalances): JsonResponse
    {
        $created = $imports->commit($batch, $request->user(), $openingBalances);
        return response()->json(['status' => true, 'msg' => 'อนุมัติและสร้างยอดยกมาเป็นร่างแล้ว '.count($created).' คลัง', 'redirect' => route('wms.opening-balances.index')]);
    }

    public function data(Request $request): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $labels = ['DRAFT' => 'ร่าง', 'POSTED' => 'ลงบัญชีแล้ว', 'VOIDED' => 'ยกเลิก'];

        $query = OpeningBalanceBatch::query()->with('lines')->where('warehouse_id', $warehouse->id);
        if ($request->filled('status') && in_array($request->string('status')->toString(), ['DRAFT', 'POSTED', 'VOIDED'], true)) $query->where('status', $request->string('status')->toString());
        if ($request->filled('date_from')) $query->whereDate('cutover_date', '>=', $request->date('date_from'));
        if ($request->filled('date_to')) $query->whereDate('cutover_date', '<=', $request->date('date_to'));

        return DataTables::eloquent($query->latest('id'))
            ->addColumn('line_count', fn ($row) => $row->lines->count())
            ->editColumn('cutover_date', fn ($row) => $row->cutover_date?->format('d/m/Y'))
            ->addColumn('costing_method_label', fn ($row) => match ($row->costing_method) {
                'FIFO' => 'FIFO',
                'AVG', 'AVERAGE' => 'ถัวเฉลี่ย',
                default => $row->costing_method ?: '-',
            })
            ->editColumn('total_value', fn ($row) => WmsDecimal::format($row->total_value))
            ->addColumn('status_label', fn ($row) => $labels[$row->status] ?? $row->status)
            ->addColumn('show_url', fn ($row) => route('wms.opening-balances.show', $row))
            ->toJson();
    }

    public function create(Request $request, GlobalSettings $settings): View
    {
        $warehouses = $this->accessibleWarehouses($request);
        $selected = $request->attributes->get('selectedWarehouse');
        $warehouse = $warehouses->firstWhere('id', $selected?->id) ?? $warehouses->first();
        $branches = $warehouses->pluck('branch')->filter()->unique('id')->sortBy('name')->values();

        return view('Wms::opening-balances.create', [
            'warehouse' => $warehouse,
            'branches' => $branches,
            'warehouses' => $warehouses,
            'items' => Item::query()->with('baseUom:id,code,name')->where('is_active', true)->where('is_stock_item', true)->orderBy('code')->get(['id', 'code', 'name', 'base_uom_id'])->map(fn (Item $item): array => ['id' => $item->id, 'label' => $item->code.' · '.$item->name, 'uom_id' => $item->base_uom_id, 'uom_label' => $item->baseUom?->code])->values()->all(),
            'costingMethod' => $settings->value('inventory_costing_method') ?: 'AVG',
            'decimalPlaces' => WmsDecimal::places(),
        ]);
    }

    public function store(SaveOpeningBalanceRequest $request, OpeningBalanceService $service): JsonResponse
    {
        $data = $request->validated();
        $warehouse = $this->accessibleWarehouses($request)
            ->firstWhere('id', (int) $data['warehouse_id']);
        abort_unless($warehouse !== null && (int) $warehouse->branch_id === (int) $data['branch_id'], 403);
        $batch = $service->createDraft($data, $request->user());

        return response()->json(['status' => true, 'msg' => 'บันทึก Opening Balance เป็นร่างแล้ว', 'redirect' => route('wms.opening-balances.show', $batch)]);
    }

    public function show(Request $request, OpeningBalanceBatch $batch): View
    {
        abort_unless($this->accessibleWarehouses($request)->contains('id', (int) $batch->warehouse_id), 404);
        return view('Wms::opening-balances.show', ['batch' => $batch->load('lines.item', 'lines.uom')]);
    }

    public function post(Request $request, OpeningBalanceBatch $batch, OpeningBalanceService $service): JsonResponse
    {
        abort_unless($this->accessibleWarehouses($request)->contains('id', (int) $batch->warehouse_id), 404);
        $service->post($batch, $request->user());
        return response()->json(['status' => true, 'msg' => 'ลงบัญชี Opening Balance แล้ว', 'redirect' => route('wms.opening-balances.show', $batch)]);
    }

    private function accessibleWarehouses(Request $request)
    {
        return $request->user()->warehouses()
            ->with('branch:id,code,name')
            ->where('warehouses.is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('branches.is_active', true))
            ->orderBy('warehouses.branch_id')->orderBy('warehouses.name')->get();
    }
}
