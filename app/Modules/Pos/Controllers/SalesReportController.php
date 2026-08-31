<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class SalesReportController extends Controller
{
    public function index(): View
    {
        return view('Pos::reports.daily-sales', ['branch' => request()->attributes->get('selectedBranch')]);
    }

    public function dailyData(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $query = $this->dailyPosQuery((int) $request->attributes->get('selectedBranch')->id, $filters);

        return DataTables::query($query)->toJson();
    }

    public function dailyTenderData(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return DataTables::query($this->dailyTenderQuery((int) $request->attributes->get('selectedBranch')->id, $filters))
            ->addColumn('type_label', fn ($row) => match ($row->type) {
                'CASH' => 'เงินสด',
                'BANK' => 'ธนาคาร',
                'CREDIT_CARD' => 'บัตรเครดิต',
                'CHEQUE' => 'เช็ค',
                default => 'อื่น ๆ',
            })
            ->toJson();
    }

    private function dailyPosQuery(int $branchId, array $filters): QueryBuilder
    {
        $range = fn ($query, string $column) => $query
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate($column, '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate($column, '<=', $date));

        $sales = $range(DB::table('pos_physical_sales')
            ->where('branch_id', $branchId)->where('status', 'POSTED')
            ->selectRaw("posting_date AS report_date, SUM(document_type = 'HS') AS hs_document_count, SUM(document_type = 'IV') AS iv_document_count, SUM(CASE WHEN document_type = 'HS' THEN total_amount ELSE 0 END) AS hs_sales, SUM(CASE WHEN document_type = 'IV' THEN total_amount ELSE 0 END) AS iv_sales, 0 AS hs_cash_received, 0 AS iv_cash_received, 0 AS return_document_count, 0 AS return_amount, 0 AS cash_refund")
            ->groupBy('posting_date'), 'posting_date');
        $hsTenders = $range(DB::table('pos_physical_sale_tenders as tenders')
            ->join('pos_physical_sales as sales', 'sales.id', '=', 'tenders.physical_sale_id')
            ->where('sales.branch_id', $branchId)->where('sales.status', 'POSTED')->where('sales.document_type', 'HS')
            ->selectRaw('sales.posting_date AS report_date, 0 AS hs_document_count, 0 AS iv_document_count, 0 AS hs_sales, 0 AS iv_sales, SUM(tenders.amount) AS hs_cash_received, 0 AS iv_cash_received, 0 AS return_document_count, 0 AS return_amount, 0 AS cash_refund')
            ->groupBy('sales.posting_date'), 'sales.posting_date');
        $ivTenders = $range(DB::table('finance_settlement_tenders as tenders')
            ->join('finance_settlements as settlements', 'settlements.id', '=', 'tenders.settlement_id')
            ->join('finance_bank_accounts as accounts', 'accounts.id', '=', 'tenders.bank_account_id')
            ->where('settlements.status', 'POSTED')->where('settlements.document_type', 'RECEIPT')
            ->where($this->isOnlyPosIvReceipt($branchId))
            ->selectRaw('settlements.settlement_date AS report_date, 0 AS hs_document_count, 0 AS iv_document_count, 0 AS hs_sales, 0 AS iv_sales, 0 AS hs_cash_received, SUM(tenders.amount) AS iv_cash_received, 0 AS return_document_count, 0 AS return_amount, 0 AS cash_refund')
            ->groupBy('settlements.settlement_date'), 'settlements.settlement_date');
        $returns = $range(DB::table('pos_sales_returns')
            ->where('branch_id', $branchId)->where('status', 'POSTED')
            ->selectRaw('posting_date AS report_date, 0 AS hs_document_count, 0 AS iv_document_count, 0 AS hs_sales, 0 AS iv_sales, 0 AS hs_cash_received, 0 AS iv_cash_received, COUNT(*) AS return_document_count, COALESCE(SUM(total_amount), 0) AS return_amount, COALESCE(SUM(refund_amount), 0) AS cash_refund')
            ->groupBy('posting_date'), 'posting_date');

        return DB::query()->fromSub($sales->unionAll($hsTenders)->unionAll($ivTenders)->unionAll($returns), 'daily_pos')
            ->selectRaw('report_date, COALESCE(SUM(hs_document_count), 0) AS hs_document_count, COALESCE(SUM(iv_document_count), 0) AS iv_document_count, COALESCE(SUM(hs_sales), 0) AS hs_sales, COALESCE(SUM(iv_sales), 0) AS iv_sales, COALESCE(SUM(hs_sales + iv_sales - return_amount), 0) AS net_sales, COALESCE(SUM(hs_cash_received), 0) AS hs_cash_received, COALESCE(SUM(iv_cash_received), 0) AS iv_cash_received, COALESCE(SUM(return_document_count), 0) AS return_document_count, COALESCE(SUM(return_amount), 0) AS return_amount, COALESCE(SUM(cash_refund), 0) AS cash_refund, COALESCE(SUM(COALESCE(hs_cash_received, 0) + COALESCE(iv_cash_received, 0) - COALESCE(cash_refund, 0)), 0) AS net_cash')
            ->groupBy('report_date')->orderByDesc('report_date');
    }

    private function dailyTenderQuery(int $branchId, array $filters): QueryBuilder
    {
        $range = fn ($query, string $column) => $query
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate($column, '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate($column, '<=', $date));
        $hsTenders = $range(DB::table('pos_physical_sale_tenders as tenders')
            ->join('pos_physical_sales as sales', 'sales.id', '=', 'tenders.physical_sale_id')
            ->join('finance_bank_accounts as accounts', 'accounts.id', '=', 'tenders.bank_account_id')
            ->where('sales.branch_id', $branchId)->where('sales.status', 'POSTED')->where('sales.document_type', 'HS')
            ->selectRaw('accounts.id AS bank_account_id, accounts.code, accounts.name, accounts.type, SUM(tenders.amount) AS hs_received, 0 AS iv_received, 0 AS cash_refund')
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type'), 'sales.posting_date');
        $ivTenders = $range(DB::table('finance_settlement_tenders as tenders')
            ->join('finance_settlements as settlements', 'settlements.id', '=', 'tenders.settlement_id')
            ->join('finance_bank_accounts as accounts', 'accounts.id', '=', 'tenders.bank_account_id')
            ->where('settlements.status', 'POSTED')->where('settlements.document_type', 'RECEIPT')
            ->where($this->isOnlyPosIvReceipt($branchId))
            ->selectRaw('accounts.id AS bank_account_id, accounts.code, accounts.name, accounts.type, 0 AS hs_received, SUM(tenders.amount) AS iv_received, 0 AS cash_refund')
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type'), 'settlements.settlement_date');
        $refunds = $range(DB::table('pos_sales_returns as returns')
            ->join('finance_bank_accounts as accounts', 'accounts.id', '=', 'returns.refund_bank_account_id')
            ->where('returns.branch_id', $branchId)->where('returns.status', 'POSTED')
            ->selectRaw('accounts.id AS bank_account_id, accounts.code, accounts.name, accounts.type, 0 AS hs_received, 0 AS iv_received, COALESCE(SUM(returns.refund_amount), 0) AS cash_refund')
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type'), 'returns.posting_date');

        return DB::query()->fromSub($hsTenders->unionAll($ivTenders)->unionAll($refunds), 'daily_tenders')
            ->selectRaw('bank_account_id, code, name, type, COALESCE(SUM(hs_received), 0) AS hs_received, COALESCE(SUM(iv_received), 0) AS iv_received, COALESCE(SUM(cash_refund), 0) AS cash_refund, COALESCE(SUM(COALESCE(hs_received, 0) + COALESCE(iv_received, 0) - COALESCE(cash_refund, 0)), 0) AS net_cash')
            ->groupBy('bank_account_id', 'code', 'name', 'type')->orderBy('code');
    }

    private function isOnlyPosIvReceipt(int $branchId): \Closure
    {
        return function ($query) use ($branchId): void {
            $query->whereExists(function ($sub) use ($branchId): void {
                $sub->selectRaw('1')->from('finance_settlement_allocation_intents as intents')
                    ->join('finance_open_items as items', 'items.id', '=', 'intents.open_item_id')
                    ->join('journal_entry_lines as journal_lines', 'journal_lines.id', '=', 'items.journal_entry_line_id')
                    ->join('pos_physical_sales as sales', 'sales.journal_entry_id', '=', 'journal_lines.journal_entry_id')
                    ->whereColumn('intents.settlement_id', 'settlements.id')
                    ->where('sales.branch_id', $branchId)->where('sales.document_type', 'IV')->where('sales.status', 'POSTED');
            })->whereNotExists(function ($sub) use ($branchId): void {
                $sub->selectRaw('1')->from('finance_settlement_allocation_intents as intents')
                    ->leftJoin('finance_open_items as items', 'items.id', '=', 'intents.open_item_id')
                    ->leftJoin('journal_entry_lines as journal_lines', 'journal_lines.id', '=', 'items.journal_entry_line_id')
                    ->leftJoin('pos_physical_sales as sales', fn ($join) => $join->on('sales.journal_entry_id', '=', 'journal_lines.journal_entry_id')->where('sales.branch_id', $branchId)->where('sales.document_type', 'IV')->where('sales.status', 'POSTED'))
                    ->whereColumn('intents.settlement_id', 'settlements.id')->whereNull('sales.id');
            });
        };
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }

    public function customerIndex(): View
    {
        return view('Pos::reports.customer-sales');
    }

    public function customerData(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'party_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $asOf = today()->format('Y-m-d');
        $allocations = DB::query()->fromSub(
            DB::table('finance_allocations')
                ->selectRaw('debit_open_item_id AS open_item_id, amount')
                ->where('allocation_date', '<=', $asOf)
                ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))
                ->unionAll(DB::table('finance_allocations')
                    ->selectRaw('credit_open_item_id AS open_item_id, amount')
                    ->where('allocation_date', '<=', $asOf)
                    ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))),
            'allocation_rows',
        )->selectRaw('open_item_id, SUM(amount) AS allocated_amount')->groupBy('open_item_id');
        $advanceApplications = DB::table('finance_advance_deposit_applications')
            ->selectRaw('open_item_id, SUM(amount) AS applied_amount')
            ->where('application_date', '<=', $asOf)
            ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))
            ->groupBy('open_item_id');
        $receivables = DB::table('finance_open_items as open_items')
            ->join('journal_entry_lines as journal_lines', 'journal_lines.id', '=', 'open_items.journal_entry_line_id')
            ->join('pos_physical_sales as invoice_sales', 'invoice_sales.journal_entry_id', '=', 'journal_lines.journal_entry_id')
            ->leftJoinSub($allocations, 'allocated', 'allocated.open_item_id', '=', 'open_items.id')
            ->leftJoinSub($advanceApplications, 'advance_applications', 'advance_applications.open_item_id', '=', 'open_items.id')
            ->where('invoice_sales.branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('open_items.ledger_type', 'AR')
            ->where('open_items.party_type', 'CUSTOMER')
            ->where('open_items.balance_side', 'DEBIT')
            ->where('open_items.document_type', 'INVOICE')
            ->where('open_items.posting_date', '<=', $asOf)
            ->where('invoice_sales.status', 'POSTED')
            ->where('invoice_sales.document_type', 'IV')
            ->selectRaw('open_items.party_id, SUM(open_items.original_amount - COALESCE(allocated.allocated_amount, 0) - COALESCE(advance_applications.applied_amount, 0)) AS outstanding_amount')
            ->groupBy('open_items.party_id');
        $sales = DB::table('pos_physical_sales as sales')
            ->where('sales.branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('sales.status', 'POSTED')
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '<=', $date))
            ->when($filters['party_id'] ?? null, fn ($q, $partyId) => $q->where('sales.party_id', $partyId))
            ->selectRaw("sales.party_id, sales.party_code, sales.party_name, (sales.document_type = 'HS') AS hs_document_count, (sales.document_type = 'IV') AS iv_document_count, 0 AS return_document_count, CASE WHEN sales.document_type = 'HS' THEN sales.total_amount ELSE 0 END AS hs_amount, CASE WHEN sales.document_type = 'IV' THEN sales.total_amount ELSE 0 END AS iv_amount, 0 AS return_amount");
        $returns = DB::table('pos_sales_returns as returns')
            ->join('pos_physical_sales as sales', 'sales.id', '=', 'returns.physical_sale_id')
            ->where('returns.branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('returns.status', 'POSTED')
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('returns.posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('returns.posting_date', '<=', $date))
            ->when($filters['party_id'] ?? null, fn ($q, $partyId) => $q->where('sales.party_id', $partyId))
            ->selectRaw('sales.party_id, sales.party_code, sales.party_name, 0 AS hs_document_count, 0 AS iv_document_count, 1 AS return_document_count, 0 AS hs_amount, 0 AS iv_amount, returns.total_amount AS return_amount');
        $query = DB::query()->fromSub($sales->unionAll($returns), 'customer_activities')
            ->leftJoinSub($receivables, 'receivables', 'receivables.party_id', '=', 'customer_activities.party_id')
            ->selectRaw('customer_activities.party_id, MAX(customer_activities.party_code) AS party_code, MAX(customer_activities.party_name) AS party_name, SUM(hs_document_count) AS hs_document_count, SUM(iv_document_count) AS iv_document_count, SUM(return_document_count) AS return_document_count, SUM(hs_amount) AS hs_amount, SUM(iv_amount) AS iv_amount, SUM(return_amount) AS return_amount, SUM(hs_amount + iv_amount - return_amount) AS net_sales, MAX(COALESCE(receivables.outstanding_amount, 0)) AS outstanding_amount')
            ->groupBy('customer_activities.party_id')
            ->orderByDesc('net_sales');

        return DataTables::query($query)
            ->addColumn('party_label', fn ($row) => $row->party_code.' · '.$row->party_name)
            ->addColumn('payment_status', fn ($row) => (float) $row->outstanding_amount > 0 ? 'OUTSTANDING' : 'CLEAR')
            ->toJson();
    }

    public function customerOptions(Request $request): JsonResponse
    {
        $values = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $query = Party::query()
            ->join('party_roles', fn ($join) => $join->on('party_roles.party_id', '=', 'parties.id')->where('party_roles.role', 'CUSTOMER')->where('party_roles.is_active', true))
            ->where('parties.is_active', true)
            ->when(trim((string) ($values['q'] ?? '')) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('parties.code', 'like', '%'.trim((string) $values['q']).'%')->orWhere('parties.name', 'like', '%'.trim((string) $values['q']).'%')))
            ->orderBy('parties.code');
        $parties = $query->forPage((int) ($values['page'] ?? 1), 31)->get(['parties.id', 'parties.code', 'parties.name']);

        return response()->json(['results' => $parties->take(30)->map(fn (Party $party) => ['id' => $party->id, 'text' => $party->code.' · '.$party->name])->values(), 'pagination' => ['more' => $parties->count() > 30]]);
    }

    public function reconciliationIndex(): View
    {
        return view('Pos::reports.sales-reconciliation');
    }

    public function reconciliationData(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'party_id' => ['nullable', 'integer', 'min:1'],
            'document_type' => ['nullable', 'in:HS,IV'],
            'status' => ['nullable', 'in:CLEAR,OUTSTANDING,CHECK'],
        ]);
        $asOf = $filters['as_of'] ?? today()->toDateString();
        $active = fn ($query, string $dateColumn) => $query->where($dateColumn, '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf));
        $receipts = $active(DB::table('finance_allocations')
            ->selectRaw("debit_open_item_id AS open_item_id, SUM(CASE WHEN source_id LIKE 'settlement:%' THEN amount ELSE 0 END) AS receipt_amount, SUM(CASE WHEN source_id NOT LIKE 'settlement:%' THEN amount ELSE 0 END) AS credit_note_amount")
            ->groupBy('debit_open_item_id'), 'allocation_date');
        $advanceByOpenItem = $active(DB::table('finance_advance_deposit_applications')
            ->selectRaw('open_item_id, SUM(amount) AS advance_amount')->whereNotNull('open_item_id')->groupBy('open_item_id'), 'application_date');
        $advanceBySale = $active(DB::table('finance_advance_deposit_applications')
            ->selectRaw('physical_sale_id, SUM(amount) AS advance_amount')->whereNotNull('physical_sale_id')->groupBy('physical_sale_id'), 'application_date');
        $cashTenders = DB::table('pos_physical_sale_tenders')->selectRaw('physical_sale_id, SUM(amount) AS cash_amount')->groupBy('physical_sale_id');
        $refunds = DB::table('pos_sales_returns')->where('status', 'POSTED')->where('posting_date', '<=', $asOf)
            ->selectRaw('physical_sale_id, SUM(refund_amount) AS refund_amount')->groupBy('physical_sale_id');
        $invoiceOpenItems = DB::table('finance_open_items as open_items')
            ->join('journal_entry_lines as journal_lines', 'journal_lines.id', '=', 'open_items.journal_entry_line_id')
            ->where('open_items.ledger_type', 'AR')->where('open_items.balance_side', 'DEBIT')->where('open_items.document_type', 'INVOICE')
            ->select(['journal_lines.journal_entry_id', 'open_items.id']);
        $query = DB::table('pos_physical_sales as sales')
            ->leftJoinSub($invoiceOpenItems, 'open_items', 'open_items.journal_entry_id', '=', 'sales.journal_entry_id')
            ->leftJoinSub($receipts, 'receipts', 'receipts.open_item_id', '=', 'open_items.id')
            ->leftJoinSub($advanceByOpenItem, 'advance_items', 'advance_items.open_item_id', '=', 'open_items.id')
            ->leftJoinSub($advanceBySale, 'advance_sales', 'advance_sales.physical_sale_id', '=', 'sales.id')
            ->leftJoinSub($cashTenders, 'cash_tenders', 'cash_tenders.physical_sale_id', '=', 'sales.id')
            ->leftJoinSub($refunds, 'refunds', 'refunds.physical_sale_id', '=', 'sales.id')
            ->where('sales.branch_id', $request->attributes->get('selectedBranch')->id)->where('sales.status', 'POSTED')->where('sales.posting_date', '<=', $asOf)
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '<=', $date))
            ->when($filters['party_id'] ?? null, fn ($q, $partyId) => $q->where('sales.party_id', $partyId))
            ->when($filters['document_type'] ?? null, fn ($q, $type) => $q->where('sales.document_type', $type))
            ->select(['sales.id', 'sales.document_number', 'sales.document_type', 'sales.posting_date', 'sales.party_code', 'sales.party_name', 'sales.total_amount', 'sales.journal_entry_id'])
            ->selectRaw("CASE WHEN sales.document_type = 'HS' THEN COALESCE(cash_tenders.cash_amount, 0) ELSE COALESCE(receipts.receipt_amount, 0) END AS received_amount")
            ->selectRaw("CASE WHEN sales.document_type = 'HS' THEN COALESCE(advance_sales.advance_amount, 0) ELSE COALESCE(advance_items.advance_amount, 0) END AS advance_amount")
            ->selectRaw("CASE WHEN sales.document_type = 'IV' THEN COALESCE(receipts.credit_note_amount, 0) ELSE 0 END AS credit_note_amount")
            ->selectRaw('COALESCE(refunds.refund_amount, 0) AS refund_amount')
            ->selectRaw("CASE WHEN sales.document_type = 'IV' THEN sales.total_amount - COALESCE(receipts.receipt_amount, 0) - COALESCE(receipts.credit_note_amount, 0) - COALESCE(advance_items.advance_amount, 0) ELSE 0 END AS outstanding_amount");

        if ($filters['status'] ?? null) {
            $ivRemaining = 'sales.total_amount - COALESCE(receipts.receipt_amount, 0) - COALESCE(receipts.credit_note_amount, 0) - COALESCE(advance_items.advance_amount, 0)';
            $hsDifference = 'sales.total_amount - COALESCE(cash_tenders.cash_amount, 0) - COALESCE(advance_sales.advance_amount, 0)';
            match ($filters['status']) {
                'OUTSTANDING' => $query->where('sales.document_type', 'IV')->whereRaw("{$ivRemaining} > 0.004"),
                'CHECK' => $query->whereRaw("(sales.document_type = 'IV' AND {$ivRemaining} < -0.004) OR (sales.document_type = 'HS' AND ABS({$hsDifference}) > 0.004)"),
                'CLEAR' => $query->whereRaw("(sales.document_type = 'IV' AND {$ivRemaining} BETWEEN -0.004 AND 0.004) OR (sales.document_type = 'HS' AND ABS({$hsDifference}) <= 0.004)"),
            };
        }

        return DataTables::query($query)
            ->filter(function (QueryBuilder $query) use ($request): void {
                $search = trim((string) data_get($request->input('search'), 'value'));
                if ($search !== '') {
                    $query->where(fn (QueryBuilder $q) => $q->where('sales.document_number', 'like', "%{$search}%")->orWhere('sales.party_code', 'like', "%{$search}%")->orWhere('sales.party_name', 'like', "%{$search}%"));
                }
            })
            ->addColumn('party_label', fn ($row) => $row->party_code.' · '.$row->party_name)
            ->addColumn('status', function ($row): string {
                $remaining = (float) $row->outstanding_amount;
                if ($row->document_type === 'IV') {
                    return $remaining > 0.004 ? 'OUTSTANDING' : ($remaining < -0.004 ? 'CHECK' : 'CLEAR');
                }

                return abs((float) $row->total_amount - (float) $row->received_amount - (float) $row->advance_amount) > 0.004 ? 'CHECK' : 'CLEAR';
            })
            ->addColumn('detail_url', fn ($row) => route('pos.physical-sales.show', $row->id))
            ->addColumn('journal_url', fn ($row) => $request->user()->hasPermission('accounting.journal-entries.view') && $row->journal_entry_id ? route('accounting.journal-preview.show', $row->journal_entry_id) : null)
            ->order(fn (QueryBuilder $query) => $query->orderByDesc('sales.posting_date')->orderByDesc('sales.id'))
            ->toJson();
    }

    public function itemIndex(): View
    {
        return view('Pos::reports.item-sales');
    }

    public function grossProfitIndex(): View
    {
        return view('Pos::reports.gross-profit');
    }

    public function grossProfitData(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'party_id' => ['nullable', 'integer', 'min:1'],
            'item_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $returned = DB::table('pos_sales_return_lines as return_lines')
            ->join('pos_sales_returns as returns', 'returns.id', '=', 'return_lines.sales_return_id')
            ->where('returns.status', 'POSTED')
            ->selectRaw('return_lines.physical_sale_line_id, SUM(return_lines.quantity) AS returned_quantity')
            ->groupBy('return_lines.physical_sale_line_id');
        $saleCosts = DB::table('wms_cost_allocations as allocations')
            ->join('wms_stock_movements as movements', 'movements.id', '=', 'allocations.stock_movement_id')
            ->where('movements.source_type', 'POS')->where('movements.direction', 'OUT')->where('movements.status', 'POSTED')
            ->where('allocations.status', 'POSTED')->where('allocations.cost_status', 'FINAL')
            ->selectRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(movements.metadata, '$.physical_sale_line_id')) AS UNSIGNED) AS physical_sale_line_id, SUM(ABS(allocations.value)) AS cogs_amount")
            ->groupByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(movements.metadata, '$.physical_sale_line_id')) AS UNSIGNED)");
        $returnCosts = DB::table('pos_sales_return_inventory_links as links')
            ->join('pos_sales_return_lines as return_lines', 'return_lines.id', '=', 'links.sales_return_line_id')
            ->join('pos_sales_returns as returns', 'returns.id', '=', 'return_lines.sales_return_id')
            ->join('wms_cost_allocations as allocations', 'allocations.id', '=', 'links.reversal_cost_allocation_id')
            ->where('returns.status', 'POSTED')->where('allocations.status', 'POSTED')
            ->selectRaw('return_lines.physical_sale_line_id, SUM(ABS(allocations.value)) AS returned_cogs_amount')
            ->groupBy('return_lines.physical_sale_line_id');
        $netQuantity = 'GREATEST(lines.quantity - COALESCE(returned.returned_quantity, 0), 0)';
        $ratio = "CASE WHEN lines.quantity = 0 THEN 0 ELSE {$netQuantity} / lines.quantity END";
        $query = DB::table('pos_physical_sale_lines as lines')
            ->join('pos_physical_sales as sales', 'sales.id', '=', 'lines.physical_sale_id')
            ->leftJoin('wms_items as items', 'items.id', '=', 'lines.item_id')
            ->leftJoin('wms_uoms as uoms', 'uoms.id', '=', 'lines.sale_uom_id')
            ->leftJoinSub($returned, 'returned', 'returned.physical_sale_line_id', '=', 'lines.id')
            ->leftJoinSub($saleCosts, 'sale_costs', 'sale_costs.physical_sale_line_id', '=', 'lines.id')
            ->leftJoinSub($returnCosts, 'return_costs', 'return_costs.physical_sale_line_id', '=', 'lines.id')
            ->where('sales.branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('sales.status', 'POSTED')
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '<=', $date))
            ->when($filters['party_id'] ?? null, fn ($q, $partyId) => $q->where('sales.party_id', $partyId))
            ->when($filters['item_id'] ?? null, fn ($q, $itemId) => $q->where('lines.item_id', $itemId))
            ->select(['sales.id AS physical_sale_id', 'sales.document_number', 'sales.document_type', 'sales.posting_date', 'sales.party_code', 'sales.party_name', 'lines.id AS physical_sale_line_id', 'lines.item_id', 'lines.line_number', 'lines.quantity', 'lines.tax_base'])
            ->selectRaw("COALESCE(items.code, JSON_UNQUOTE(JSON_EXTRACT(lines.item_snapshot, '$.code')), '—') AS item_code")
            ->selectRaw("COALESCE(items.name, JSON_UNQUOTE(JSON_EXTRACT(lines.item_snapshot, '$.name')), 'สินค้า') AS item_name")
            ->selectRaw("COALESCE(uoms.code, '—') AS uom_code, {$netQuantity} AS net_quantity, COALESCE(returned.returned_quantity, 0) AS returned_quantity")
            ->selectRaw("lines.tax_base * ({$ratio}) AS net_sales, lines.promotion_discount_amount * ({$ratio}) AS promotion_discount_amount")
            ->selectRaw('COALESCE(sale_costs.cogs_amount, 0) - COALESCE(return_costs.returned_cogs_amount, 0) AS cogs_amount')
            ->selectRaw("(lines.tax_base * ({$ratio})) - (COALESCE(sale_costs.cogs_amount, 0) - COALESCE(return_costs.returned_cogs_amount, 0)) AS gross_profit");

        return DataTables::query($query)
            ->addColumn('document_label', fn ($row) => $row->document_type.' · '.$row->document_number)
            ->addColumn('party_label', fn ($row) => $row->party_code.' · '.$row->party_name)
            ->addColumn('item_label', fn ($row) => $row->item_code.' · '.$row->item_name)
            ->addColumn('gross_profit_percent', fn ($row) => (float) $row->net_sales == 0.0 ? 0 : ((float) $row->gross_profit / (float) $row->net_sales) * 100)
            ->addColumn('detail_url', fn ($row) => route('pos.physical-sales.show', $row->physical_sale_id))
            ->order(fn (QueryBuilder $query) => $query->orderByDesc('sales.posting_date')->orderByDesc('lines.id'))
            ->toJson();
    }

    public function salesTargetPerformanceIndex(): View
    {
        return view('Pos::reports.sales-target-performance');
    }

    public function salesTargetPerformanceData(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'scope' => ['nullable', 'in:BRANCH,EMPLOYEE'],
        ]);
        $from = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['date_to'] ?? now()->endOfMonth()->toDateString();
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $facts = $this->salesTargetFacts($branchId, $this->authorizedWarehouseIds($request), $from, $to);
        $branchTarget = DB::table('pos_branch_sales_targets')->where('branch_id', $branchId)->where('period_start', $from)->where('period_end', $to)->whereNull('deleted_at')->first();
        $employeeTargets = DB::table('pos_employee_sales_targets')->where('branch_id', $branchId)->where('period_start', $from)->where('period_end', $to)->whereNull('deleted_at')->get()->keyBy('user_id');
        $employeeFacts = $facts->keyBy('employee_id');
        $users = User::query()->whereIn('id', $employeeFacts->keys()->merge($employeeTargets->keys())->filter())->get(['id', 'name'])->keyBy('id');
        $branchSales = (float) $facts->sum('net_sales');
        $branchGp = (float) $facts->sum('gross_profit');
        $rows = [[
            'scope' => 'BRANCH', 'label' => 'สาขาปัจจุบัน', 'target_sales_amount' => $branchTarget?->target_sales_amount,
            'actual_sales_amount' => $branchSales, 'sales_variance' => $branchTarget?->target_sales_amount !== null ? $branchSales - (float) $branchTarget->target_sales_amount : null,
            'target_gross_profit_amount' => $branchTarget?->target_gross_profit_amount, 'actual_gross_profit_amount' => $branchGp,
            'gross_profit_variance' => $branchTarget?->target_gross_profit_amount !== null ? $branchGp - (float) $branchTarget->target_gross_profit_amount : null,
        ]];
        foreach ($employeeFacts->keys()->merge($employeeTargets->keys())->unique()->sort()->values() as $userId) {
            $fact = $employeeFacts->get($userId);
            $target = $employeeTargets->get($userId);
            $sales = (float) ($fact->net_sales ?? 0);
            $gp = (float) ($fact->gross_profit ?? 0);
            $rows[] = [
                'scope' => 'EMPLOYEE', 'label' => $users->get($userId)?->name ?: "User #{$userId}", 'target_sales_amount' => $target?->sales_target,
                'actual_sales_amount' => $sales, 'sales_variance' => $target?->sales_target !== null ? $sales - (float) $target->sales_target : null,
                'target_gross_profit_amount' => $target?->gross_profit_target, 'actual_gross_profit_amount' => $gp,
                'gross_profit_variance' => $target?->gross_profit_target !== null ? $gp - (float) $target->gross_profit_target : null,
            ];
        }

        return DataTables::of(collect($rows)->when($filters['scope'] ?? null, fn ($rows, $scope) => $rows->where('scope', $scope)))->toJson();
    }

    private function salesTargetFacts(int $branchId, array $warehouseIds, string $from, string $to)
    {
        $returned = DB::table('pos_sales_return_lines as return_lines')->join('pos_sales_returns as returns', 'returns.id', '=', 'return_lines.sales_return_id')->where('returns.status', 'POSTED')->selectRaw('return_lines.physical_sale_line_id, SUM(return_lines.quantity) AS returned_quantity')->groupBy('return_lines.physical_sale_line_id');
        $saleCosts = DB::table('wms_cost_allocations as allocations')->join('wms_stock_movements as movements', 'movements.id', '=', 'allocations.stock_movement_id')->where('movements.source_type', 'POS')->where('movements.direction', 'OUT')->where('movements.status', 'POSTED')->where('allocations.status', 'POSTED')->where('allocations.cost_status', 'FINAL')->selectRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(movements.metadata, '$.physical_sale_line_id')) AS UNSIGNED) AS physical_sale_line_id, SUM(ABS(allocations.value)) AS cogs_amount")->groupByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(movements.metadata, '$.physical_sale_line_id')) AS UNSIGNED)");
        $returnCosts = DB::table('pos_sales_return_inventory_links as links')->join('pos_sales_return_lines as return_lines', 'return_lines.id', '=', 'links.sales_return_line_id')->join('pos_sales_returns as returns', 'returns.id', '=', 'return_lines.sales_return_id')->join('wms_cost_allocations as allocations', 'allocations.id', '=', 'links.reversal_cost_allocation_id')->where('returns.status', 'POSTED')->where('allocations.status', 'POSTED')->where('allocations.cost_status', 'FINAL')->selectRaw('return_lines.physical_sale_line_id, SUM(ABS(allocations.value)) AS returned_cogs_amount')->groupBy('return_lines.physical_sale_line_id');
        $netQuantity = 'GREATEST(lines.quantity - COALESCE(returned.returned_quantity, 0), 0)';
        $ratio = "CASE WHEN lines.quantity = 0 THEN 0 ELSE {$netQuantity} / lines.quantity END";
        $employee = 'COALESCE(intake_direct.prepared_by, intake_quote.prepared_by, intake_quote_rfq.prepared_by, intake_order_rfq.prepared_by, sales.created_by)';

        return DB::table('pos_physical_sale_lines as lines')
            ->join('pos_physical_sales as sales', 'sales.id', '=', 'lines.physical_sale_id')
            ->leftJoin('sales_orders as orders', fn ($join) => $join->on('orders.id', '=', 'sales.source_id')->where('sales.source_type', 'SALES_ORDER'))
            ->leftJoin('sales_intakes as intake_direct', 'intake_direct.id', '=', 'orders.source_sales_intake_id')
            ->leftJoin('sales_quotations as quotations', 'quotations.id', '=', 'orders.sales_quotation_id')
            ->leftJoin('sales_intakes as intake_quote', 'intake_quote.id', '=', 'quotations.source_sales_intake_id')
            ->leftJoin('sales_rfqs as quote_rfqs', 'quote_rfqs.id', '=', 'quotations.sales_rfq_id')
            ->leftJoin('sales_intakes as intake_quote_rfq', 'intake_quote_rfq.id', '=', 'quote_rfqs.source_sales_intake_id')
            ->leftJoin('sales_rfqs as order_rfqs', 'order_rfqs.id', '=', 'orders.sales_rfq_id')
            ->leftJoin('sales_intakes as intake_order_rfq', 'intake_order_rfq.id', '=', 'order_rfqs.source_sales_intake_id')
            ->leftJoinSub($returned, 'returned', 'returned.physical_sale_line_id', '=', 'lines.id')
            ->leftJoinSub($saleCosts, 'sale_costs', 'sale_costs.physical_sale_line_id', '=', 'lines.id')
            ->leftJoinSub($returnCosts, 'return_costs', 'return_costs.physical_sale_line_id', '=', 'lines.id')
            ->where('sales.branch_id', $branchId)->whereIn('sales.warehouse_id', $warehouseIds)->where('sales.status', 'POSTED')->whereBetween('sales.posting_date', [$from, $to])
            ->selectRaw("{$employee} AS employee_id, SUM(lines.tax_base * ({$ratio})) AS net_sales, SUM((lines.tax_base * ({$ratio})) - (COALESCE(sale_costs.cogs_amount, 0) - COALESCE(return_costs.returned_cogs_amount, 0))) AS gross_profit")
            ->groupByRaw($employee)->get();
    }

    public function promotionIndex(): View
    {
        return view('Pos::reports.promotion-performance');
    }

    public function campaignRoiIndex(): View
    {
        return view('Pos::reports.campaign-roi');
    }

    public function campaignRoiData(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'promotion_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $returnedLines = DB::table('pos_sales_return_lines as lines')->join('pos_sales_returns as returns', 'returns.id', '=', 'lines.sales_return_id')
            ->where('returns.status', 'POSTED')->selectRaw('lines.physical_sale_line_id, SUM(lines.quantity) AS quantity')->groupBy('lines.physical_sale_line_id');
        $saleCosts = DB::table('wms_cost_allocations as allocations')->join('wms_stock_movements as movements', 'movements.id', '=', 'allocations.stock_movement_id')
            ->where('movements.source_type', 'POS')->where('movements.direction', 'OUT')->where('movements.status', 'POSTED')->where('allocations.status', 'POSTED')->where('allocations.cost_status', 'FINAL')
            ->selectRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(movements.metadata, '$.physical_sale_line_id')) AS UNSIGNED) AS physical_sale_line_id, SUM(ABS(allocations.value)) AS amount")
            ->groupByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(movements.metadata, '$.physical_sale_line_id')) AS UNSIGNED)");
        $returnCosts = DB::table('pos_sales_return_inventory_links as links')->join('pos_sales_return_lines as lines', 'lines.id', '=', 'links.sales_return_line_id')->join('pos_sales_returns as returns', 'returns.id', '=', 'lines.sales_return_id')->join('wms_cost_allocations as allocations', 'allocations.id', '=', 'links.reversal_cost_allocation_id')
            ->where('returns.status', 'POSTED')->where('allocations.status', 'POSTED')->where('allocations.cost_status', 'FINAL')->selectRaw('lines.physical_sale_line_id, SUM(ABS(allocations.value)) AS amount')->groupBy('lines.physical_sale_line_id');
        $commissions = DB::table('pos_sales_commission_records')->where('branch_id', $branchId)->whereIn('warehouse_id', $warehouseIds)->whereIn('status', ['PENDING', 'APPROVED', 'PAID'])
            ->selectRaw('physical_sale_id, SUM(commission_amount) AS amount')->groupBy('physical_sale_id');
        $lineRatio = 'CASE WHEN lines.quantity = 0 THEN 0 ELSE GREATEST(lines.quantity - COALESCE(returned_lines.quantity, 0), 0) / lines.quantity END';
        // A document promotion is allocated and snapshotted per line.  Use that immutable
        // allocation when a line is returned; a bill-level return ratio distorts partial returns.
        $documentPromotionDiscount = "CASE WHEN JSON_EXTRACT(lines.pricing_snapshot, '$.document_promotion.discount_amount') IS NOT NULL THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(lines.pricing_snapshot, '$.document_promotion.discount_amount')) AS DECIMAL(18, 2)) * ({$lineRatio}) ELSE 0 END";
        $lineDiscount = "CASE WHEN JSON_EXTRACT(lines.pricing_snapshot, '$.document_promotion.discount_amount') IS NOT NULL THEN lines.promotion_discount_amount - CAST(JSON_UNQUOTE(JSON_EXTRACT(lines.pricing_snapshot, '$.document_promotion.discount_amount')) AS DECIMAL(18, 2)) WHEN JSON_EXTRACT(sales.promotion_snapshot, '$.promotion_id') IS NULL THEN lines.promotion_discount_amount ELSE 0 END";
        $saleFacts = DB::table('pos_physical_sale_lines as lines')->join('pos_physical_sales as sales', 'sales.id', '=', 'lines.physical_sale_id')
            ->leftJoinSub($returnedLines, 'returned_lines', 'returned_lines.physical_sale_line_id', '=', 'lines.id')
            ->leftJoinSub($saleCosts, 'sale_costs', 'sale_costs.physical_sale_line_id', '=', 'lines.id')->leftJoinSub($returnCosts, 'return_costs', 'return_costs.physical_sale_line_id', '=', 'lines.id')->leftJoinSub($commissions, 'commissions', 'commissions.physical_sale_id', '=', 'sales.id')
            ->where('sales.branch_id', $branchId)->whereIn('sales.warehouse_id', $warehouseIds)->where('sales.status', 'POSTED')
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '>=', $date))->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '<=', $date))
            ->selectRaw("sales.id AS physical_sale_id, MAX(CAST(sales.promotion_snapshot AS CHAR)) AS promotion_snapshot, SUM({$documentPromotionDiscount}) AS document_discount, SUM(lines.tax_base * ({$lineRatio})) AS net_sales, SUM(COALESCE(sale_costs.amount, 0) - COALESCE(return_costs.amount, 0)) AS cogs_amount, MAX(COALESCE(commissions.amount, 0)) AS commission_amount")
            ->groupBy('sales.id');
        $linePromotions = DB::table('pos_physical_sale_lines as lines')->join('pos_physical_sales as sales', 'sales.id', '=', 'lines.physical_sale_id')->leftJoinSub($returnedLines, 'returned_lines', 'returned_lines.physical_sale_line_id', '=', 'lines.id')
            ->where('sales.branch_id', $branchId)->whereIn('sales.warehouse_id', $warehouseIds)->where('sales.status', 'POSTED')->whereNotNull('lines.pricing_snapshot')->whereRaw("JSON_EXTRACT(lines.pricing_snapshot, '$.promotion_id') IS NOT NULL")
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '>=', $date))->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '<=', $date))
            ->selectRaw("sales.id AS physical_sale_id, CAST(JSON_UNQUOTE(JSON_EXTRACT(lines.pricing_snapshot, '$.promotion_id')) AS UNSIGNED) AS promotion_id, MAX(JSON_UNQUOTE(JSON_EXTRACT(lines.pricing_snapshot, '$.promotion_code'))) AS promotion_code, 'LINE' AS application_scope, SUM(({$lineDiscount}) * ({$lineRatio})) AS promotion_discount_amount, SUM(lines.tax_base * ({$lineRatio})) AS allocation_weight")
            ->groupBy('sales.id', DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(lines.pricing_snapshot, '$.promotion_id')) AS UNSIGNED)"));
        $documentPromotions = DB::query()->fromSub($saleFacts, 'facts')->whereRaw("JSON_EXTRACT(facts.promotion_snapshot, '$.promotion_id') IS NOT NULL")
            ->selectRaw("facts.physical_sale_id, CAST(JSON_UNQUOTE(JSON_EXTRACT(facts.promotion_snapshot, '$.promotion_id')) AS UNSIGNED) AS promotion_id, JSON_UNQUOTE(JSON_EXTRACT(facts.promotion_snapshot, '$.promotion_code')) AS promotion_code, 'DOCUMENT' AS application_scope, facts.document_discount AS promotion_discount_amount, facts.net_sales AS allocation_weight");
        $attributions = DB::query()->fromSub($linePromotions->unionAll($documentPromotions), 'promotion_rows')
            ->selectRaw('physical_sale_id, promotion_id, MAX(promotion_code) AS promotion_code, MAX(application_scope) AS application_scope, SUM(promotion_discount_amount) AS promotion_discount_amount, SUM(allocation_weight) AS allocation_weight')
            ->groupBy('physical_sale_id', 'promotion_id');
        $usage = DB::query()->fromSub($attributions, 'attribution')->joinSub($saleFacts, 'facts', 'facts.physical_sale_id', '=', 'attribution.physical_sale_id')
            ->joinSub(DB::query()->fromSub($attributions, 'totals')->selectRaw('physical_sale_id, SUM(CASE WHEN promotion_discount_amount <> 0 THEN promotion_discount_amount ELSE allocation_weight END) AS amount')->groupBy('physical_sale_id'), 'totals', 'totals.physical_sale_id', '=', 'attribution.physical_sale_id')
            ->selectRaw('attribution.promotion_id, MAX(attribution.promotion_code) AS promotion_code, MAX(attribution.application_scope) AS application_scope, COUNT(*) AS usage_count, SUM(attribution.promotion_discount_amount) AS promotion_discount_amount, SUM(facts.net_sales * (CASE WHEN attribution.promotion_discount_amount <> 0 THEN attribution.promotion_discount_amount ELSE attribution.allocation_weight END) / NULLIF(totals.amount, 0)) AS net_sales, SUM(facts.cogs_amount * (CASE WHEN attribution.promotion_discount_amount <> 0 THEN attribution.promotion_discount_amount ELSE attribution.allocation_weight END) / NULLIF(totals.amount, 0)) AS cogs_amount, SUM(facts.commission_amount * (CASE WHEN attribution.promotion_discount_amount <> 0 THEN attribution.promotion_discount_amount ELSE attribution.allocation_weight END) / NULLIF(totals.amount, 0)) AS commission_amount')
            ->groupBy('attribution.promotion_id');
        $costs = DB::table('pos_promotion_campaign_costs')->where('branch_id', $branchId)->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('cost_date', '>=', $date))->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('cost_date', '<=', $date))->selectRaw('promotion_id, SUM(amount) AS external_spend')->groupBy('promotion_id');
        $query = DB::table('pos_promotions as promotions')->leftJoinSub($usage, 'usage', 'usage.promotion_id', '=', 'promotions.id')->leftJoinSub($costs, 'costs', 'costs.promotion_id', '=', 'promotions.id')
            ->leftJoin('users as owners', 'owners.id', '=', 'promotions.campaign_owner_id')->whereNull('promotions.deleted_at')->when($filters['promotion_id'] ?? null, fn ($q, $id) => $q->where('promotions.id', $id))
            ->where(fn ($q) => $q->whereNotNull('usage.promotion_id')->orWhereNotNull('costs.promotion_id')->orWhereNotNull('promotions.campaign_budget_amount')->orWhereNotNull('promotions.campaign_target_sales_amount')->orWhereNotNull('promotions.campaign_target_gross_profit_amount'))
            ->selectRaw('promotions.id AS promotion_id, promotions.code AS promotion_code, promotions.name AS promotion_name, MAX(usage.application_scope) AS application_scope, MAX(owners.name) AS owner_name, MAX(promotions.campaign_budget_amount) AS campaign_budget_amount, MAX(promotions.campaign_target_sales_amount) AS target_sales_amount, MAX(promotions.campaign_target_gross_profit_amount) AS target_gross_profit_amount, COALESCE(MAX(usage.usage_count), 0) AS usage_count, COALESCE(MAX(usage.promotion_discount_amount), 0) AS promotion_discount_amount, COALESCE(MAX(usage.net_sales), 0) AS net_sales, COALESCE(MAX(usage.cogs_amount), 0) AS cogs_amount, COALESCE(MAX(usage.commission_amount), 0) AS commission_amount, COALESCE(MAX(costs.external_spend), 0) AS external_spend')
            ->groupBy('promotions.id', 'promotions.code', 'promotions.name');

        return DataTables::query($query)
            ->addColumn('scope_label', fn ($row) => $row->application_scope === 'DOCUMENT' ? 'ท้ายบิล' : ($row->application_scope === 'LINE' ? 'ต่อรายการ' : '—'))
            ->addColumn('gross_profit', fn ($row) => (float) $row->net_sales - (float) $row->cogs_amount)
            ->addColumn('contribution', fn ($row) => (float) $row->net_sales - (float) $row->cogs_amount - (float) $row->commission_amount - (float) $row->external_spend)
            ->addColumn('budget_remaining', fn ($row) => $row->campaign_budget_amount === null ? null : (float) $row->campaign_budget_amount - (float) $row->external_spend)
            ->addColumn('sales_target_variance', fn ($row) => $row->target_sales_amount === null ? null : (float) $row->net_sales - (float) $row->target_sales_amount)
            ->addColumn('gross_profit_target_variance', fn ($row) => $row->target_gross_profit_amount === null ? null : ((float) $row->net_sales - (float) $row->cogs_amount) - (float) $row->target_gross_profit_amount)
            ->addColumn('roi_percent', fn ($row) => (float) $row->external_spend == 0.0 ? null : (((float) $row->net_sales - (float) $row->cogs_amount - (float) $row->commission_amount - (float) $row->external_spend) / (float) $row->external_spend) * 100)
            ->order(fn (QueryBuilder $query) => $query->orderByRaw('COALESCE(MAX(usage.net_sales), 0) - COALESCE(MAX(usage.cogs_amount), 0) - COALESCE(MAX(usage.commission_amount), 0) - COALESCE(MAX(costs.external_spend), 0) DESC')->orderBy('promotions.code'))->toJson();
    }

    public function promotionData(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'promotion_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $returnsBySale = DB::table('pos_sales_returns')
            ->where('status', 'POSTED')
            ->selectRaw('physical_sale_id, SUM(total_amount) AS return_amount')
            ->groupBy('physical_sale_id');
        $returnedByLine = DB::table('pos_sales_return_lines as return_lines')
            ->join('pos_sales_returns as returns', 'returns.id', '=', 'return_lines.sales_return_id')
            ->where('returns.status', 'POSTED')
            ->selectRaw('return_lines.physical_sale_line_id, SUM(return_lines.quantity) AS returned_quantity')
            ->groupBy('return_lines.physical_sale_line_id');
        $documentRatio = 'GREATEST(sales.total_amount - COALESCE(returned_sales.return_amount, 0), 0) / NULLIF(sales.total_amount, 0)';
        $documents = DB::table('pos_physical_sales as sales')
            ->leftJoinSub($returnsBySale, 'returned_sales', 'returned_sales.physical_sale_id', '=', 'sales.id')
            ->where('sales.branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('sales.status', 'POSTED')->whereNotNull('sales.promotion_snapshot')
            ->whereRaw("JSON_EXTRACT(sales.promotion_snapshot, '$.promotion_id') IS NOT NULL")
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '<=', $date))
            ->when($filters['promotion_id'] ?? null, fn ($q, $id) => $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(sales.promotion_snapshot, '$.promotion_id')) = ?", [(string) $id]))
            ->selectRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(sales.promotion_snapshot, '$.promotion_id')) AS UNSIGNED) AS promotion_id, JSON_UNQUOTE(JSON_EXTRACT(sales.promotion_snapshot, '$.promotion_code')) AS promotion_code, 'DOCUMENT' AS application_scope, 1 AS usage_count, 0 AS net_quantity, sales.promotion_discount_amount * ({$documentRatio}) AS promotion_discount_amount, sales.tax_base * ({$documentRatio}) AS net_sales");
        $lineRatio = 'GREATEST(lines.quantity - COALESCE(returned_lines.returned_quantity, 0), 0) / NULLIF(lines.quantity, 0)';
        $lineDiscount = "CASE WHEN JSON_EXTRACT(lines.pricing_snapshot, '$.document_promotion.discount_amount') IS NOT NULL THEN lines.promotion_discount_amount - CAST(JSON_UNQUOTE(JSON_EXTRACT(lines.pricing_snapshot, '$.document_promotion.discount_amount')) AS DECIMAL(18, 2)) WHEN JSON_EXTRACT(sales.promotion_snapshot, '$.promotion_id') IS NULL THEN lines.promotion_discount_amount ELSE 0 END";
        $lines = DB::table('pos_physical_sale_lines as lines')
            ->join('pos_physical_sales as sales', 'sales.id', '=', 'lines.physical_sale_id')
            ->leftJoinSub($returnedByLine, 'returned_lines', 'returned_lines.physical_sale_line_id', '=', 'lines.id')
            ->where('sales.branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('sales.status', 'POSTED')->whereNotNull('lines.pricing_snapshot')
            ->whereRaw("JSON_EXTRACT(lines.pricing_snapshot, '$.promotion_id') IS NOT NULL")
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '<=', $date))
            ->when($filters['promotion_id'] ?? null, fn ($q, $id) => $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(lines.pricing_snapshot, '$.promotion_id')) = ?", [(string) $id]))
            ->selectRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(lines.pricing_snapshot, '$.promotion_id')) AS UNSIGNED) AS promotion_id, JSON_UNQUOTE(JSON_EXTRACT(lines.pricing_snapshot, '$.promotion_code')) AS promotion_code, 'LINE' AS application_scope, 1 AS usage_count, GREATEST(lines.quantity - COALESCE(returned_lines.returned_quantity, 0), 0) AS net_quantity, ({$lineDiscount}) * ({$lineRatio}) AS promotion_discount_amount, lines.tax_base * ({$lineRatio}) AS net_sales");
        $query = DB::query()->fromSub($documents->unionAll($lines), 'promotion_usage')
            ->selectRaw('promotion_id, MAX(promotion_code) AS promotion_code, application_scope, SUM(usage_count) AS usage_count, SUM(net_quantity) AS net_quantity, SUM(promotion_discount_amount) AS promotion_discount_amount, SUM(net_sales) AS net_sales')
            ->groupBy('promotion_id', 'application_scope')
            ->orderByDesc('promotion_discount_amount');

        return DataTables::query($query)
            ->addColumn('scope_label', fn ($row) => $row->application_scope === 'DOCUMENT' ? 'ท้ายบิล' : 'ต่อรายการ')
            ->toJson();
    }

    public function promotionOptions(Request $request): JsonResponse
    {
        $values = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $q = trim((string) ($values['q'] ?? ''));
        $promotions = DB::table('pos_promotions')
            ->when($q !== '', fn ($query) => $query->where(fn ($query) => $query->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('code')->forPage((int) ($values['page'] ?? 1), 31)->get(['id', 'code', 'name']);

        return response()->json(['results' => $promotions->take(30)->map(fn ($promotion) => ['id' => $promotion->id, 'text' => $promotion->code.' · '.$promotion->name])->values(), 'pagination' => ['more' => $promotions->count() > 30]]);
    }

    public function itemData(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'item_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $sales = DB::table('pos_physical_sale_lines as lines')
            ->join('pos_physical_sales as sales', 'sales.id', '=', 'lines.physical_sale_id')
            ->leftJoin('wms_items as items', 'items.id', '=', 'lines.item_id')
            ->leftJoin('wms_uoms as uoms', 'uoms.id', '=', 'lines.sale_uom_id')
            ->where('sales.branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('sales.status', 'POSTED')
            ->whereNotNull('lines.item_id')
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('sales.posting_date', '<=', $date))
            ->when($filters['item_id'] ?? null, fn ($q, $itemId) => $q->where('lines.item_id', $itemId))
            ->selectRaw("lines.item_id, lines.sale_uom_id AS uom_id, COALESCE(uoms.code, '—') AS unit, COALESCE(items.code, JSON_UNQUOTE(JSON_EXTRACT(lines.item_snapshot, '$.code')), '—') AS item_code, COALESCE(items.name, JSON_UNQUOTE(JSON_EXTRACT(lines.item_snapshot, '$.name')), 'สินค้า') AS item_name, CASE WHEN sales.document_type = 'HS' THEN lines.quantity ELSE 0 END AS hs_quantity, CASE WHEN sales.document_type = 'IV' THEN lines.quantity ELSE 0 END AS iv_quantity, 0 AS return_quantity, CASE WHEN sales.document_type = 'HS' THEN lines.line_total ELSE 0 END AS hs_amount, CASE WHEN sales.document_type = 'IV' THEN lines.line_total ELSE 0 END AS iv_amount, 0 AS return_amount");
        $returns = DB::table('pos_sales_return_lines as return_lines')
            ->join('pos_sales_returns as returns', 'returns.id', '=', 'return_lines.sales_return_id')
            ->join('pos_physical_sale_lines as lines', 'lines.id', '=', 'return_lines.physical_sale_line_id')
            ->leftJoin('wms_items as items', 'items.id', '=', 'return_lines.item_id')
            ->leftJoin('wms_uoms as uoms', 'uoms.id', '=', 'return_lines.uom_id')
            ->where('returns.branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('returns.status', 'POSTED')
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('returns.posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('returns.posting_date', '<=', $date))
            ->when($filters['item_id'] ?? null, fn ($q, $itemId) => $q->where('return_lines.item_id', $itemId))
            ->selectRaw("return_lines.item_id, return_lines.uom_id, COALESCE(uoms.code, '—') AS unit, COALESCE(items.code, JSON_UNQUOTE(JSON_EXTRACT(lines.item_snapshot, '$.code')), '—') AS item_code, COALESCE(items.name, JSON_UNQUOTE(JSON_EXTRACT(lines.item_snapshot, '$.name')), 'สินค้า') AS item_name, 0 AS hs_quantity, 0 AS iv_quantity, return_lines.quantity AS return_quantity, 0 AS hs_amount, 0 AS iv_amount, return_lines.line_total AS return_amount");
        $query = DB::query()->fromSub($sales->unionAll($returns), 'item_activities')
            ->selectRaw('item_id, uom_id, unit, MAX(item_code) AS item_code, MAX(item_name) AS item_name, SUM(hs_quantity) AS hs_quantity, SUM(iv_quantity) AS iv_quantity, SUM(return_quantity) AS return_quantity, SUM(hs_quantity + iv_quantity - return_quantity) AS net_quantity, SUM(hs_amount) AS hs_amount, SUM(iv_amount) AS iv_amount, SUM(return_amount) AS return_amount, SUM(hs_amount + iv_amount - return_amount) AS net_sales')
            ->groupBy('item_id', 'uom_id', 'unit')
            ->orderByDesc('net_sales');

        return DataTables::query($query)
            ->addColumn('item_label', fn ($row) => $row->item_code.' · '.$row->item_name)
            ->toJson();
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $values = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $q = trim((string) ($values['q'] ?? ''));
        $items = DB::table('wms_items as items')
            ->where('items.is_active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($query) => $query->where('items.code', 'like', "%{$q}%")->orWhere('items.name', 'like', "%{$q}%")))
            ->select(['items.id', 'items.code', 'items.name'])
            ->orderBy('items.code')
            ->forPage((int) ($values['page'] ?? 1), 31)
            ->get();

        return response()->json(['results' => $items->take(30)->map(fn ($item) => ['id' => $item->id, 'text' => $item->code.' · '.$item->name])->values(), 'pagination' => ['more' => $items->count() > 30]]);
    }
}
