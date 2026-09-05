<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Finance\Models\AdvanceDeposit;
use App\Modules\Finance\Models\AdvanceDepositApplication;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Requests\SaveAdvanceDepositApplicationRequest;
use App\Modules\Finance\Services\AdvanceDepositApplicationService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AdvanceDepositController extends Controller
{
    public function index(): View
    {
        $instrumentType = request()->routeIs('finance.advances.*') ? 'ADVANCE' : (request()->routeIs('finance.deposits.*') ? 'DEPOSIT' : null);
        return view('Finance::advance-deposits.index', compact('instrumentType'));
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $format = (string) $settings->value('date_format');
        $query = AdvanceDeposit::query()->with('party')
            ->where('branch_id', $request->attributes->get('selectedBranch')->id)
            ->whereIn('warehouse_id', $this->authorizedWarehouseIds($request));
        $instrumentType = $request->routeIs('finance.advances.*') ? 'ADVANCE' : ($request->routeIs('finance.deposits.*') ? 'DEPOSIT' : null);
        $query->when($instrumentType, fn (Builder $query) => $query->where('instrument_type', $instrumentType));
        $query->when($request->filled('status') && in_array($request->input('status'), ['POSTED', 'PARTIAL', 'APPLIED', 'REVERSED', 'VOID'], true), fn (Builder $query) => $query->where('status', $request->input('status')))
            ->when($request->filled('direction') && in_array($request->input('direction'), ['RECEIPT', 'PAYMENT'], true), fn (Builder $query) => $query->where('direction', $request->input('direction')))
            ->when($request->filled('date_from'), fn (Builder $query) => $query->whereDate('posting_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $query) => $query->whereDate('posting_date', '<=', $request->input('date_to')))
            ->when($request->filled('amount_min') && is_numeric($request->input('amount_min')), fn (Builder $query) => $query->where('original_amount', '>=', (float) $request->input('amount_min')))
            ->when($request->filled('amount_max') && is_numeric($request->input('amount_max')), fn (Builder $query) => $query->where('original_amount', '<=', (float) $request->input('amount_max')));

        return DataTables::eloquent($query)
            ->order(fn (Builder $q) => $q->reorder('finance_advance_deposits.posting_date', 'desc')->orderByDesc('finance_advance_deposits.id'))
            ->addColumn('party_label', fn (AdvanceDeposit $a) => ($a->party?->code ?: '—').' · '.($a->party?->name ?: '—'))
            ->addColumn('direction_label', fn (AdvanceDeposit $a) => $a->direction === 'RECEIPT' ? 'รับล่วงหน้า' : 'จ่ายล่วงหน้า')
            ->addColumn('instrument_label', fn (AdvanceDeposit $a) => $a->instrument_type === 'DEPOSIT' ? 'เงินมัดจำ' : 'เงินล่วงหน้า')
            ->addColumn('posting_date_label', fn (AdvanceDeposit $a) => $a->posting_date?->format($format) ?? '—')
            ->addColumn('status_label', fn (AdvanceDeposit $a) => match ($a->status) {
                'POSTED' => 'ลงบัญชีแล้ว', 'PARTIAL' => 'ตัดบางส่วน', 'APPLIED' => 'ตัดครบแล้ว', 'REVERSED' => 'กลับรายการแล้ว', 'VOID' => 'ยกเลิก', default => 'ร่าง'
            })
            ->addColumn('status_class', fn (AdvanceDeposit $a) => match ($a->status) {
                'POSTED' => 'app-status-success', 'PARTIAL' => 'app-status-info', 'APPLIED' => 'app-status-neutral', 'REVERSED', 'VOID' => 'app-status-danger', default => 'app-status-neutral'
            })
            ->addColumn('apply_url', fn (AdvanceDeposit $a) => in_array($a->status, ['POSTED', 'PARTIAL'], true) && $request->user()->hasPermission('finance.advance-deposits.apply') ? route('finance.advance-deposits.applications.create', $a) : null)
            ->addColumn('show_url', fn (AdvanceDeposit $a) => route('finance.advance-deposits.show', $a))
            ->toJson();
    }

    public function show(Request $request, AdvanceDeposit $advance, GlobalSettings $settings): View
    {
        $this->scopeAdvance($request, $advance);
        $advance->load([
            'party',
            'sourceSettlement',
            'applications.openItem',
            'applications.journalEntry',
        ]);
        $applicationIds = $advance->applications->pluck('id')->all();
        $history = AuditLog::query()->with('user')
            ->where(function ($query) use ($advance, $applicationIds): void {
                $query->where(function ($nested) use ($advance): void {
                    $nested->where('subject_type', $advance->getMorphClass())->where('subject_id', $advance->id);
                });
                if ($applicationIds !== []) {
                    $query->orWhere(function ($nested) use ($applicationIds): void {
                        $nested->where('subject_type', (new AdvanceDepositApplication)->getMorphClass())->whereIn('subject_id', $applicationIds);
                    });
                }
            })
            ->latest('created_at')->latest('id')->get();

        return view('Finance::advance-deposits.show', [
            'advance' => $advance,
            'history' => $history,
            'dateFormat' => (string) $settings->value('date_format'),
        ]);
    }

    public function createApplication(Request $request, AdvanceDeposit $advance, GlobalSettings $settings): View
    {
        $this->scopeAdvance($request, $advance);
        abort_unless(in_array($advance->status, ['POSTED', 'PARTIAL'], true), 422, 'Advance/Deposit นี้ยังไม่พร้อมตัดรายการ');
        $partyType = strtoupper($advance->party_type);
        $ledger = $partyType === 'CUSTOMER' ? 'AR' : 'AP';
        $side = $partyType === 'CUSTOMER' ? 'DEBIT' : 'CREDIT';
        $options = $this->openItems($advance, $ledger, $side)->limit(50)->get();

        return view('Finance::advance-deposits.application-form', compact('advance', 'options'));
    }

    public function storeApplication(SaveAdvanceDepositApplicationRequest $request, AdvanceDeposit $advance, AdvanceDepositApplicationService $service, AuditLogger $audit): JsonResponse
    {
        $this->scopeAdvance($request, $advance);
        $values = $request->validated();
        $item = OpenItem::query()->findOrFail($values['open_item_id']);
        $application = $service->apply($advance, $item, [
            'application_date' => $values['application_date'], 'amount' => $values['amount'],
            'source_type' => 'MANUAL_UI', 'source_id' => $values['source_id'],
        ], $request->user());
        $audit->record('finance.advance_deposit.application_created', $application, [], $application->only(['advance_deposit_id', 'open_item_id', 'application_date', 'amount', 'source_type', 'source_id']), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'บันทึกการตัดเงินล่วงหน้า/เงินมัดจำแล้ว', 'redirect' => route('finance.advance-deposits.index')]);
    }

    private function scopeAdvance(Request $request, AdvanceDeposit $advance): void
    {
        abort_unless((int) $advance->branch_id === (int) $request->attributes->get('selectedBranch')->id
            && in_array((int) $advance->warehouse_id, $this->authorizedWarehouseIds($request), true), 404);
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function openItems(AdvanceDeposit $advance, string $ledger, string $side): Builder
    {
        $directAllocationRows = DB::table('finance_allocations')
            ->selectRaw('debit_open_item_id as open_item_id, amount')
            ->whereNull('reversal_date')
            ->unionAll(DB::table('finance_allocations')
                ->selectRaw('credit_open_item_id as open_item_id, amount')
                ->whereNull('reversal_date'));
        $directAllocated = DB::query()->fromSub($directAllocationRows, 'direct_allocation_rows')
            ->select('open_item_id', DB::raw('SUM(amount) as allocated_amount'))
            ->groupBy('open_item_id');
        $advanceApplied = DB::table('finance_advance_deposit_applications')->select('open_item_id', DB::raw('SUM(amount) as applied_amount'))->whereNull('reversed_at')->groupBy('open_item_id');

        return OpenItem::query()->join('accounts', 'accounts.id', '=', 'finance_open_items.account_id')->leftJoinSub($directAllocated, 'direct_allocated', 'direct_allocated.open_item_id', '=', 'finance_open_items.id')->leftJoinSub($advanceApplied, 'advance_applied', 'advance_applied.open_item_id', '=', 'finance_open_items.id')
            ->where('finance_open_items.warehouse_id', $advance->warehouse_id)->where('finance_open_items.party_type', $advance->party_type)->where('finance_open_items.party_id', $advance->party_id)->where('finance_open_items.ledger_type', $ledger)->where('finance_open_items.balance_side', $side)->where('accounts.is_active', true)->where('accounts.is_postable', true)
            ->whereRaw('finance_open_items.original_amount - COALESCE(direct_allocated.allocated_amount, 0) - COALESCE(advance_applied.applied_amount, 0) > 0')->select('finance_open_items.*')->selectRaw('finance_open_items.original_amount - COALESCE(direct_allocated.allocated_amount, 0) - COALESCE(advance_applied.applied_amount, 0) AS advance_remaining_amount')->orderBy('finance_open_items.due_date')->orderBy('finance_open_items.id');
    }
}
