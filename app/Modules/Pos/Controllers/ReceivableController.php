<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Modules\Finance\Models\AdvanceDepositApplication;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class ReceivableController extends Controller
{
    public function index(Request $request): View
    {
        $partyId = $request->integer('party_id');
        $selectedParty = $partyId ? Party::query()->find($partyId, ['id', 'code', 'name']) : null;

        return view('Pos::receivables.index', compact('selectedParty'));
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $filters = $request->validate([
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date', 'after_or_equal:due_from'],
            'party_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['UNPAID', 'PARTIAL', 'PAID'])],
        ]);
        $query = $this->items($request)
            ->when($filters['due_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('oi.due_date', '>=', $date))
            ->when($filters['due_to'] ?? null, fn (Builder $q, string $date) => $q->whereDate('oi.due_date', '<=', $date))
            ->when($filters['party_id'] ?? null, fn (Builder $q, int $partyId) => $q->where('oi.party_id', $partyId));

        if ($filters['status'] ?? null) {
            $applied = 'COALESCE(allocations.amount, 0) + COALESCE(advances.amount, 0)';
            match ($filters['status']) {
                'UNPAID' => $query->whereRaw("{$applied} = 0"),
                'PARTIAL' => $query->whereRaw("{$applied} > 0")->whereRaw("oi.original_amount - {$applied} > 0"),
                'PAID' => $query->whereRaw("oi.original_amount - {$applied} <= 0"),
            };
        }

        $format = (string) ($settings->value('date_format') ?: 'd/m/Y');
        $canReceive = $request->user()->hasPermission('pos.receipts.create');

        return DataTables::query($query)
            ->filter(function (Builder $query) use ($request): void {
                $search = trim((string) data_get($request->input('search'), 'value'));
                if ($search !== '') {
                    $query->where(fn (Builder $q) => $q->where('oi.document_number', 'like', "%{$search}%")
                        ->orWhere('parties.code', 'like', "%{$search}%")
                        ->orWhere('parties.name', 'like', "%{$search}%"));
                }
            })
            ->order(fn (Builder $query) => $query->orderByRaw('oi.due_date IS NULL')->orderBy('oi.due_date')->orderByDesc('oi.id'))
            ->addColumn('document_date_label', fn ($row) => Carbon::parse($row->document_date)->format($format))
            ->addColumn('due_date_label', fn ($row) => $row->due_date ? Carbon::parse($row->due_date)->format($format) : '—')
            ->addColumn('party_label', fn ($row) => $row->party_code.' · '.$row->party_name)
            ->addColumn('payment_status', fn ($row) => (float) $row->remaining_amount <= 0 ? 'PAID' : ((float) $row->applied_amount <= 0 ? 'UNPAID' : 'PARTIAL'))
            ->addColumn('payment_status_label', fn ($row) => (float) $row->remaining_amount <= 0 ? 'ชำระครบ' : ((float) $row->applied_amount <= 0 ? 'ยังไม่ชำระ' : 'ชำระบางส่วน'))
            ->addColumn('show_url', fn ($row) => route('pos.receivables.show', ['openItem' => $row->id]))
            ->addColumn('receive_receipt_url', fn ($row) => $canReceive && (float) $row->remaining_amount > 0 ? route('pos.physical-sales.receive-payment.create', $row->physical_sale_id) : null)
            ->toJson();
    }

    public function partyOptions(Request $request): JsonResponse
    {
        $values = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'as_of' => ['nullable', 'date']]);
        $search = trim((string) $request->input('q'));
        $page = max(1, (int) $request->input('page', 1));
        $parties = $this->items($request, $values['as_of'] ?? null)
            ->select(['oi.party_id', 'parties.code', 'parties.name'])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $where) => $where->where('parties.code', 'like', "%{$search}%")->orWhere('parties.name', 'like', "%{$search}%")))
            ->distinct()->orderBy('parties.code')->forPage($page, 31)->get();

        return response()->json(['results' => $parties->take(30)->map(fn ($party) => ['id' => (int) $party->party_id, 'text' => $party->code.' · '.$party->name])->values(), 'pagination' => ['more' => $parties->count() > 30]]);
    }

    public function agingIndex(): View
    {
        return view('Pos::receivables.aging');
    }

    public function agingData(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'as_of' => ['nullable', 'date'],
            'party_id' => ['nullable', 'integer'],
        ]);
        $asOf = $filters['as_of'] ?? today()->toDateString();
        $remaining = 'oi.original_amount - COALESCE(allocations.amount, 0) - COALESCE(advances.amount, 0)';
        $query = $this->items($request, $asOf)
            ->whereRaw("{$remaining} > 0")
            ->when($filters['party_id'] ?? null, fn (Builder $q, int $partyId) => $q->where('oi.party_id', $partyId))
            ->select(['oi.party_id', 'parties.code as party_code', 'parties.name as party_name'])
            ->selectRaw("SUM(CASE WHEN oi.due_date IS NULL OR oi.due_date >= ? THEN {$remaining} ELSE 0 END) AS current_amount", [$asOf])
            ->selectRaw("SUM(CASE WHEN DATEDIFF(?, oi.due_date) BETWEEN 1 AND 30 THEN {$remaining} ELSE 0 END) AS days_1_30", [$asOf])
            ->selectRaw("SUM(CASE WHEN DATEDIFF(?, oi.due_date) BETWEEN 31 AND 60 THEN {$remaining} ELSE 0 END) AS days_31_60", [$asOf])
            ->selectRaw("SUM(CASE WHEN DATEDIFF(?, oi.due_date) BETWEEN 61 AND 90 THEN {$remaining} ELSE 0 END) AS days_61_90", [$asOf])
            ->selectRaw("SUM(CASE WHEN DATEDIFF(?, oi.due_date) > 90 THEN {$remaining} ELSE 0 END) AS days_over_90", [$asOf])
            ->selectRaw("SUM({$remaining}) AS total_amount")
            ->groupBy('oi.party_id', 'parties.code', 'parties.name');

        return DataTables::query($query)
            ->filter(function (Builder $query) use ($request): void {
                $search = trim((string) data_get($request->input('search'), 'value'));
                if ($search !== '') {
                    $query->where(fn (Builder $q) => $q->where('parties.code', 'like', "%{$search}%")->orWhere('parties.name', 'like', "%{$search}%"));
                }
            })
            ->order(fn (Builder $query) => $query->orderByDesc('total_amount')->orderBy('parties.code'))
            ->addColumn('party_label', fn ($row) => $row->party_code.' · '.$row->party_name)
            ->addColumn('details_url', fn ($row) => route('pos.receivables.index', ['party_id' => $row->party_id]))
            ->toJson();
    }

    public function show(Request $request, OpenItem $openItem, OpenItemService $openItems, GlobalSettings $settings): View
    {
        abort_unless($openItem->ledger_type === 'AR' && $openItem->party_type === 'CUSTOMER' && $openItem->balance_side === 'DEBIT' && $openItem->document_type === 'INVOICE', 404);
        $sale = DB::table('pos_physical_sales')->where('branch_id', $request->attributes->get('selectedBranch')->id)->where('warehouse_id', $openItem->warehouse_id)->where('document_number', $openItem->document_number)->where('document_type', 'IV')->where('status', 'POSTED')->first();
        abort_unless($sale, 404);

        $openItem->load(['party', 'account']);
        $asOf = today()->toDateString();
        $allocations = Allocation::query()->with('creditOpenItem')->where('debit_open_item_id', $openItem->id)->orderByDesc('allocation_date')->orderByDesc('id')->get();
        $settlementIds = $allocations->map(fn (Allocation $allocation) => preg_match('/^settlement:(\\d+):intent:/', $allocation->source_id, $match) ? (int) $match[1] : null)->filter()->unique();
        $receipts = Settlement::withTrashed()->whereIn('id', $settlementIds)->get()->keyBy('id');
        $advanceApplications = AdvanceDepositApplication::query()->with('advanceDeposit')->where('open_item_id', $openItem->id)->orderByDesc('application_date')->orderByDesc('id')->get();
        $activeAllocations = $allocations->filter(fn (Allocation $allocation) => ! $allocation->reversal_date || $allocation->reversal_date->isAfter($asOf));
        $activeAdvances = $advanceApplications->filter(fn (AdvanceDepositApplication $application) => ! $application->reversal_date || $application->reversal_date->isAfter($asOf));
        $receiptAmount = $activeAllocations->filter(fn (Allocation $allocation) => preg_match('/^settlement:\d+:intent:/', $allocation->source_id))->sum('amount');
        $creditNoteAmount = $activeAllocations->sum('amount') - $receiptAmount;
        $remainingAmount = $openItems->remainingAt($openItem, $asOf);
        $dateFormat = (string) ($settings->value('date_format') ?: 'd/m/Y');
        $daysOverdue = $openItem->due_date && $openItem->due_date->isBefore($asOf) ? $openItem->due_date->diffInDays($asOf) : 0;

        return view('Pos::receivables.show', compact('openItem', 'sale', 'allocations', 'receipts', 'advanceApplications', 'receiptAmount', 'creditNoteAmount', 'remainingAmount', 'dateFormat', 'daysOverdue', 'activeAdvances'));
    }

    private function items(Request $request, ?string $asOf = null): Builder
    {
        $asOf ??= today()->toDateString();
        $allocations = DB::table('finance_allocations')->selectRaw('debit_open_item_id AS open_item_id, SUM(amount) AS amount')
            ->where('allocation_date', '<=', $asOf)->where(fn (Builder $q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))->groupBy('debit_open_item_id');
        $advances = DB::table('finance_advance_deposit_applications')->selectRaw('open_item_id, SUM(amount) AS amount')
            ->where('application_date', '<=', $asOf)->where(fn (Builder $q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))->groupBy('open_item_id');

        return DB::table('finance_open_items as oi')->join('parties', 'parties.id', '=', 'oi.party_id')
            ->join('pos_physical_sales as sales', fn ($join) => $join->on('sales.warehouse_id', '=', 'oi.warehouse_id')->on('sales.document_number', '=', 'oi.document_number')->where('sales.document_type', 'IV')->where('sales.status', 'POSTED'))
            ->leftJoinSub($allocations, 'allocations', 'allocations.open_item_id', '=', 'oi.id')
            ->leftJoinSub($advances, 'advances', 'advances.open_item_id', '=', 'oi.id')
            ->where('sales.branch_id', $request->attributes->get('selectedBranch')->id)->where('oi.ledger_type', 'AR')->where('oi.party_type', 'CUSTOMER')->where('oi.balance_side', 'DEBIT')->where('oi.document_type', 'INVOICE')
            ->select(['oi.id', 'sales.id as physical_sale_id', 'oi.document_number', 'oi.document_date', 'oi.due_date', 'oi.original_amount', 'parties.code as party_code', 'parties.name as party_name'])
            ->selectRaw('COALESCE(allocations.amount, 0) + COALESCE(advances.amount, 0) AS applied_amount')
            ->selectRaw('oi.original_amount - COALESCE(allocations.amount, 0) - COALESCE(advances.amount, 0) AS remaining_amount');
    }
}
