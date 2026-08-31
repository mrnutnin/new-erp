<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class OpenItemController extends Controller
{
    public function index(Request $request): View
    {
        $ledgerType = $this->ledgerType($request);
        $prefix = $ledgerType === 'AR' ? 'finance.receivables' : 'finance.payables';

        return view('Finance::open-items.index', [
            'ledgerType' => $ledgerType,
            'dataUrl' => route("{$prefix}.open-items.data"),
            'partyOptionsUrl' => route("{$prefix}.party-options"),
            'agingUrl' => route("{$prefix}.aging.index"),
        ]);
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        [$asOf, $partyId] = $this->filters($request);
        $query = $this->openItemsQuery($request, $asOf, $partyId);
        $dateFormat = (string) $settings->value('date_format');

        return DataTables::query($query)
            ->filter(fn (Builder $query) => $this->applyOpenItemSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyOpenItemOrder($query, $request))
            ->addColumn('document_date_label', fn ($row) => Carbon::parse($row->document_date)->format($dateFormat))
            ->addColumn('due_date_label', fn ($row) => $row->due_date ? Carbon::parse($row->due_date)->format($dateFormat) : '—')
            ->addColumn('party_label', fn ($row) => $row->party_code.' · '.$row->party_name)
            ->addColumn('status_label', fn ($row) => $row->allocated_amount == 0 ? 'OPEN' : ($row->outstanding_amount == 0 ? 'CLOSED' : 'PARTIAL'))
            ->addColumn('status_class', fn ($row) => $row->allocated_amount == 0 ? 'text-bg-warning' : ($row->outstanding_amount == 0 ? 'text-bg-success' : 'text-bg-info'))
            ->addColumn('show_url', fn ($row) => route($this->ledgerType($request) === 'AR' ? 'finance.receivables.open-items.show' : 'finance.payables.open-items.show', $row->id))
            ->toJson();
    }

    public function show(Request $request, OpenItem $openItem, GlobalSettings $settings): View
    {
        $ledgerType = $this->ledgerType($request);
        abort_unless(in_array((int) $openItem->warehouse_id, $this->authorizedWarehouseIds($request), true) && $openItem->ledger_type === $ledgerType, 404);
        abort_unless($openItem->party_type === ($ledgerType === 'AR' ? 'CUSTOMER' : 'SUPPLIER'), 404);

        $openItem->load(['party', 'account', 'journalEntryLine.entry']);
        $dateFormat = (string) $settings->value('date_format');
        $allocationRows = DB::table('finance_allocations')
            ->where(fn ($query) => $query->where('debit_open_item_id', $openItem->id)->orWhere('credit_open_item_id', $openItem->id))
            ->orderByDesc('allocation_date')->orderByDesc('id')->get()
            ->map(function ($row) use ($dateFormat) {
                $row->allocation_date_label = Carbon::parse($row->allocation_date)->format($dateFormat);

                return $row;
            });
        $counterpartIds = $allocationRows->map(fn ($row) => (int) ($row->debit_open_item_id == $openItem->id ? $row->credit_open_item_id : $row->debit_open_item_id))->unique()->values();
        $counterparts = OpenItem::with('party')->whereIn('id', $counterpartIds)->get()->keyBy('id');
        $allocatedAmount = $allocationRows->filter(fn ($row) => $row->reversal_date === null || $row->reversal_date > today()->toDateString())->sum('amount');
        $advanceApplicationRows = DB::table('finance_advance_deposit_applications')
            ->where('open_item_id', $openItem->id)
            ->orderByDesc('application_date')->orderByDesc('id')->get()
            ->map(function ($row) use ($dateFormat) {
                $row->application_date_label = Carbon::parse($row->application_date)->format($dateFormat);

                return $row;
            });
        $advanceAppliedAmount = $advanceApplicationRows
            ->filter(fn ($row) => $row->reversal_date === null || $row->reversal_date > today()->toDateString())
            ->sum('amount');
        $allocatedAmount += $advanceAppliedAmount;

        return view('Finance::open-items.show', compact('openItem', 'ledgerType', 'allocationRows', 'advanceApplicationRows', 'counterparts', 'allocatedAmount', 'dateFormat'));
    }

    public function partyOptions(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        [$asOf] = $this->filters($request);
        $search = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));
        $query = $this->openItemsQuery($request, $asOf)
            ->where('parties.is_active', true)
            ->whereNull('parties.deleted_at')
            ->whereExists(fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('party_roles')
                ->whereColumn('party_roles.party_id', 'parties.id')
                ->where('party_roles.role', $this->ledgerType($request) === 'AR' ? 'CUSTOMER' : 'SUPPLIER')
                ->where('party_roles.is_active', true))
            ->select(['oi.party_type', 'oi.party_id', 'parties.code as party_code', 'parties.name as party_name'])
            ->distinct()
            ->reorder('parties.code');

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('parties.code', 'like', "%{$search}%")
                ->orWhere('parties.name', 'like', "%{$search}%")
                ->orWhere('parties.tax_id', 'like', "%{$search}%")
                ->orWhere('parties.phone', 'like', "%{$search}%")
                ->when(ctype_digit($search), fn (Builder $query) => $query->orWhere('parties.id', (int) $search)));
        }

        $parties = $query->forPage($page, 31)->get();

        return response()->json([
            'results' => $parties->take(30)->map(fn ($party) => [
                'id' => (int) $party->party_id,
                'text' => $party->party_code.' · '.$party->party_name,
                'code' => $party->party_code,
                'name' => $party->party_name,
            ])->values(),
            'pagination' => ['more' => $parties->count() > 30],
        ]);
    }

    public function agingIndex(Request $request): View
    {
        $ledgerType = $this->ledgerType($request);
        $prefix = $ledgerType === 'AR' ? 'finance.receivables' : 'finance.payables';

        return view('Finance::open-items.aging', [
            'ledgerType' => $ledgerType,
            'dataUrl' => route("{$prefix}.aging.data"),
            'partyOptionsUrl' => route("{$prefix}.party-options"),
            'openItemsUrl' => route("{$prefix}.open-items.index"),
        ]);
    }

    public function agingData(Request $request): JsonResponse
    {
        [$asOf, $partyId] = $this->filters($request);
        $remaining = '(oi.original_amount - COALESCE(a.allocated_amount, 0) - COALESCE(aa.applied_amount, 0))';
        $ledgerType = $this->ledgerType($request);
        $positiveSide = $ledgerType === 'AR' ? 'DEBIT' : 'CREDIT';
        $signed = "{$remaining} * CASE WHEN oi.balance_side = '{$positiveSide}' THEN 1 ELSE -1 END";
        $query = $this->openItemsQuery($request, $asOf, $partyId)
            ->select(['oi.party_type', 'oi.party_id', 'parties.code as party_code', 'parties.name as party_name'])
            ->selectRaw("SUM(CASE WHEN oi.due_date IS NULL OR oi.due_date >= ? THEN {$signed} ELSE 0 END) AS current_amount", [$asOf])
            ->selectRaw("SUM(CASE WHEN DATEDIFF(?, oi.due_date) BETWEEN 1 AND 30 THEN {$signed} ELSE 0 END) AS days_1_30", [$asOf])
            ->selectRaw("SUM(CASE WHEN DATEDIFF(?, oi.due_date) BETWEEN 31 AND 60 THEN {$signed} ELSE 0 END) AS days_31_60", [$asOf])
            ->selectRaw("SUM(CASE WHEN DATEDIFF(?, oi.due_date) BETWEEN 61 AND 90 THEN {$signed} ELSE 0 END) AS days_61_90", [$asOf])
            ->selectRaw("SUM(CASE WHEN DATEDIFF(?, oi.due_date) > 90 THEN {$signed} ELSE 0 END) AS days_over_90", [$asOf])
            ->selectRaw("SUM({$signed}) AS total_amount")
            ->groupBy('oi.party_type', 'oi.party_id', 'parties.code', 'parties.name');

        return DataTables::query($query)
            ->filter(fn (Builder $query) => $this->applyAgingSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyAgingOrder($query, $request))
            ->addColumn('party_label', fn ($row) => $row->party_code.' · '.$row->party_name)
            ->addColumn('details_url', fn ($row) => route($ledgerType === 'AR' ? 'finance.receivables.open-items.index' : 'finance.payables.open-items.index', ['party_id' => $row->party_id, 'as_of' => $asOf]))
            ->toJson();
    }

    private function openItemsQuery(Request $request, string $asOf, ?int $partyId = null): Builder
    {
        $ledgerType = $this->ledgerType($request);
        $positiveSide = $ledgerType === 'AR' ? 'DEBIT' : 'CREDIT';
        $partyType = $ledgerType === 'AR' ? 'CUSTOMER' : 'SUPPLIER';
        $sign = "CASE WHEN oi.balance_side = '{$positiveSide}' THEN 1 ELSE -1 END";
        $allocations = DB::query()->fromSub(
            DB::table('finance_allocations')
                ->selectRaw('debit_open_item_id AS open_item_id, amount')
                ->where('allocation_date', '<=', $asOf)
                ->where(fn (Builder $query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))
                ->unionAll(
                    DB::table('finance_allocations')
                        ->selectRaw('credit_open_item_id AS open_item_id, amount')
                        ->where('allocation_date', '<=', $asOf)
                        ->where(fn (Builder $query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))
                ),
            'allocation_rows'
        )->select('open_item_id')->selectRaw('SUM(amount) AS allocated_amount')->groupBy('open_item_id');
        $advanceApplications = DB::table('finance_advance_deposit_applications')
            ->selectRaw('open_item_id, SUM(amount) AS applied_amount')
            ->where('application_date', '<=', $asOf)
            ->where(fn (Builder $query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))
            ->groupBy('open_item_id');

        $query = DB::table('finance_open_items as oi')
            ->join('parties', 'parties.id', '=', 'oi.party_id')
            ->leftJoinSub($allocations, 'a', 'a.open_item_id', '=', 'oi.id')
            ->leftJoinSub($advanceApplications, 'aa', 'aa.open_item_id', '=', 'oi.id')
            ->whereIn('oi.warehouse_id', $this->authorizedWarehouseIds($request))
            ->where('oi.ledger_type', $ledgerType)
            ->where('oi.party_type', $partyType)
            ->where('oi.posting_date', '<=', $asOf)
            ->whereRaw('oi.original_amount - COALESCE(a.allocated_amount, 0) - COALESCE(aa.applied_amount, 0) > 0')
            ->select([
                'oi.id', 'oi.party_type', 'oi.party_id', 'parties.code as party_code', 'parties.name as party_name', 'oi.document_type', 'oi.document_number',
                'oi.document_date', 'oi.posting_date', 'oi.due_date', 'oi.balance_side', 'oi.original_amount',
            ])
            ->selectRaw('COALESCE(a.allocated_amount, 0) + COALESCE(aa.applied_amount, 0) AS allocated_amount')
            ->selectRaw('oi.original_amount - COALESCE(a.allocated_amount, 0) - COALESCE(aa.applied_amount, 0) AS outstanding_amount')
            ->selectRaw("oi.original_amount * {$sign} AS signed_original_amount")
            ->selectRaw("(COALESCE(a.allocated_amount, 0) + COALESCE(aa.applied_amount, 0)) * {$sign} AS signed_allocated_amount")
            ->selectRaw("(oi.original_amount - COALESCE(a.allocated_amount, 0) - COALESCE(aa.applied_amount, 0)) * {$sign} AS signed_outstanding_amount")
            ->selectRaw('CASE WHEN oi.due_date < ? THEN DATEDIFF(?, oi.due_date) ELSE 0 END AS days_overdue', [$asOf, $asOf]);

        if ($partyId !== null) {
            $query->where('oi.party_id', $partyId);
        }

        return $query;
    }

    private function filters(Request $request): array
    {
        $values = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'party_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return [$values['as_of'] ?? today()->toDateString(), $values['party_id'] ?? null];
    }

    private function ledgerType(Request $request): string
    {
        $ledgerType = strtoupper((string) $request->route('ledgerType'));
        abort_unless(in_array($ledgerType, ['AR', 'AP'], true), 404);

        return $ledgerType;
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function applyOpenItemSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('oi.document_number', 'like', "%{$search}%")
                ->orWhere('oi.document_type', 'like', "%{$search}%")
                ->orWhere('parties.code', 'like', "%{$search}%")
                ->orWhere('parties.name', 'like', "%{$search}%")
                ->orWhere('parties.tax_id', 'like', "%{$search}%"));
        }
    }

    private function applyOpenItemOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'oi.document_number', 1 => 'oi.document_date', 2 => 'oi.due_date', 3 => 'parties.code',
            4 => 'signed_original_amount', 5 => 'signed_allocated_amount', 6 => 'signed_outstanding_amount', 7 => 'days_overdue',
        ];
        $column = $columns[(int) $request->input('order.0.column', 2)] ?? 'oi.due_date';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $query->reorder($column, $direction)->orderBy('oi.id');
    }

    private function applyAgingSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('parties.code', 'like', "%{$search}%")
                ->orWhere('parties.name', 'like', "%{$search}%")
                ->orWhere('parties.tax_id', 'like', "%{$search}%"));
        }
    }

    private function applyAgingOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'parties.code', 1 => 'current_amount', 2 => 'days_1_30', 3 => 'days_31_60',
            4 => 'days_61_90', 5 => 'days_over_90', 6 => 'total_amount',
        ];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'parties.code';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $query->reorder($column, $direction);
    }
}
