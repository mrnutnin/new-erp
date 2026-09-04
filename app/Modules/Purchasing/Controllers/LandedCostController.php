<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Requests\SaveLandedCostRequest;
use App\Modules\Purchasing\Services\LandedCostService;
use App\Modules\Purchasing\Services\LandedCostPostingService;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Wms\Services\InventoryWarehouseReleaseGate;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Carbon\Carbon;
use Yajra\DataTables\Facades\DataTables;

final class LandedCostController extends Controller
{
    public function index(Request $request): View
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        return view('Purchasing::landed-costs.index', ['warehouse' => $warehouse]);
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $labels = ['DRAFT' => 'ร่าง', 'SUBMITTED' => 'รออนุมัติ', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก'];
        $query = LandedCost::query()->where('warehouse_id', $warehouse->id)->withCount('allocations')
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('business_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('business_date', '<=', $request->input('date_to')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')));

        return DataTables::eloquent($query)
            ->addColumn('status_label', fn (LandedCost $row) => $labels[$row->status] ?? $row->status)
            ->addColumn('basis_label', fn (LandedCost $row) => ['VALUE' => 'ตามมูลค่า', 'QUANTITY' => 'ตามจำนวน', 'WEIGHT' => 'ตามน้ำหนัก'][$row->allocation_basis] ?? $row->allocation_basis)
            ->editColumn('business_date', fn (LandedCost $row) => $row->business_date?->format((string) $settings->value('date_format')) ?: '-')
            ->addColumn('show_url', fn (LandedCost $row) => route('purchasing.landed-costs.show', $row))
            ->addColumn('can_submit', fn (LandedCost $row) => $row->status === 'DRAFT' && $request->user()->hasPermission('purchasing.landed-costs.submit'))
            ->addColumn('can_approve', fn (LandedCost $row) => $row->status === 'SUBMITTED' && $request->user()->hasPermission('purchasing.landed-costs.approve'))
            ->addColumn('can_post', fn (LandedCost $row) => $row->status === 'APPROVED' && $request->user()->hasPermission('purchasing.landed-costs.post'))
            ->addColumn('can_void', fn (LandedCost $row) => in_array($row->status, ['DRAFT', 'SUBMITTED', 'APPROVED'], true) && $request->user()->hasPermission('purchasing.landed-costs.void'))
            ->toJson();
    }

    public function create(Request $request): View
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $baseReceipts = GoodsReceipt::query()->with('supplier')->where('warehouse_id', $warehouse->id)->where('status', 'APPROVED');
        $receipts = (clone $baseReceipts)->whereExists(fn ($query) => $query->selectRaw('1')->from('wms_stock_movements')->whereColumn('wms_stock_movements.source_id', 'goods_receipts.id')->where('wms_stock_movements.source_type', 'GOODS_RECEIPT')->where('wms_stock_movements.status', 'POSTED'))->latest('business_date')->limit(200)->get(['id', 'receipt_number', 'business_date', 'supplier_id']);
        $pendingReceipts = (clone $baseReceipts)->whereNotExists(fn ($query) => $query->selectRaw('1')->from('wms_stock_movements')->whereColumn('wms_stock_movements.source_id', 'goods_receipts.id')->where('wms_stock_movements.source_type', 'GOODS_RECEIPT')->where('wms_stock_movements.status', 'POSTED'))->latest('business_date')->limit(200)->get(['id', 'receipt_number', 'business_date', 'supplier_id']);
        return view('Purchasing::landed-costs.form', ['warehouse' => $warehouse, 'receipts' => $receipts, 'pendingReceipts' => $pendingReceipts, 'accounts' => Account::query()->with('type')->where('is_active', true)->where('is_postable', true)->whereHas('type', fn ($query) => $query->where('code', 'EXPENSE'))->orderBy('code')->limit(200)->get(['id', 'code', 'name'])]);
    }

    public function store(SaveLandedCostRequest $request, LandedCostService $service, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $values = $request->validated();
        $document = DB::transaction(function () use ($warehouse, $values, $service, $sequences, $audit, $request): LandedCost {
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'LANDED_COST')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร Landed Cost ใน Global Setting']);
            }
            $warehouse->loadMissing('branch');
            $date = Carbon::createFromFormat('Y-m-d', $values['business_date']);
            $number = $sequences->issueAvailableForBranch($sequence, $warehouse->branch, $date, fn (string $number): bool => LandedCost::query()->where('document_number', $number)->exists());
            $document = $service->createDraft([...$values, 'warehouse_id' => $warehouse->id, 'document_number' => $number, 'idempotency_key' => 'landed-cost:'.bin2hex(random_bytes(12))], $request->user());
            $sequences->recordIssued($sequence->fresh(), $number, 'purchasing_landed_costs', $document->id, $date, $request->user()->id);
            $audit->record('purchasing.landed_cost.created', $document, [], $document->toArray(), $request->user(), $request);
            return $document;
        }, 3);
        return response()->json(['status' => true, 'msg' => "สร้างร่าง Landed Cost {$document->document_number} แล้ว", 'redirect' => route('purchasing.landed-costs.show', $document)]);
    }

    public function show(Request $request, LandedCost $landedCost): View
    {
        abort_unless((int) $landedCost->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
        return view('Purchasing::landed-costs.show', ['document' => $landedCost->load(['lines.account', 'receipts.goodsReceipt', 'allocations.item', 'allocations.uom'])]);
    }

    public function submit(Request $request, LandedCost $landedCost, LandedCostService $service): JsonResponse { return $this->transition($request, $landedCost, fn () => $service->submit($landedCost, $request->user()), 'ส่งอนุมัติแล้ว'); }
    public function approve(Request $request, LandedCost $landedCost, LandedCostService $service): JsonResponse { return $this->transition($request, $landedCost, fn () => $service->approve($landedCost, $request->user()), 'อนุมัติแล้ว'); }
    public function void(Request $request, LandedCost $landedCost, LandedCostService $service): JsonResponse { return $this->transition($request, $landedCost, fn () => $service->void($landedCost, $request->user()), 'ยกเลิกแล้ว'); }

    public function post(Request $request, LandedCost $landedCost, LandedCostPostingService $posting, InventoryWarehouseReleaseGate $releaseGate, AuditLogger $audit): JsonResponse
    {
        abort_unless((int) $landedCost->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
        $gate = $releaseGate->assertPostingAllowed($request->attributes->get('selectedWarehouse'));
        $gate['period_open'] = FiscalPeriod::query()->whereDate('start_date', '<=', $landedCost->business_date)->whereDate('end_date', '>=', $landedCost->business_date)->where('status', 'OPEN')->exists();
        $gate['reconciliation_ready'] = (bool) ($gate['reconciliation_ready'] ?? false);
        $document = $posting->postApproved($landedCost, $gate, $request->user());
        $audit->record('purchasing.landed_cost.posted', $document, ['status' => 'APPROVED'], $document->toArray(), $request->user(), $request);
        return response()->json(['status' => true, 'msg' => "Post Landed Cost {$document->document_number} แล้ว"]);
    }

    private function transition(Request $request, LandedCost $landedCost, callable $action, string $message): JsonResponse
    {
        abort_unless((int) $landedCost->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
        $document = $action();
        return response()->json(['status' => true, 'msg' => "Landed Cost {$document->document_number} {$message}"]);
    }
}
