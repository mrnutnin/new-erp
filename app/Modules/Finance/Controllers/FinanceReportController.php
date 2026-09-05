<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class FinanceReportController extends Controller
{
    public function paymentIndex(): View
    {
        return view('Finance::reports.payment-activity', ['branches' => $this->reportBranches(request())]);
    }

    public function settlementAllocationIndex(): View
    {
        return view('Finance::reports.settlement-allocations', ['branches' => $this->reportBranches(request()), 'warehouses' => $this->reportWarehouses(request())]);
    }

    public function settlementAllocationData(Request $request): JsonResponse
    {
        $query = DB::table('finance_settlement_allocation_intents as i')
            ->join('finance_settlements as s', 's.id', '=', 'i.settlement_id')
            ->join('finance_bank_accounts as ba', 'ba.id', '=', 's.bank_account_id')
            ->join('warehouses as w', 'w.id', '=', 'ba.warehouse_id')
            ->join('parties as p', 'p.id', '=', 's.party_id')
            ->leftJoin('finance_open_items as oi', 'oi.id', '=', 'i.open_item_id')
            ->leftJoin('finance_allocations as a', 'a.id', '=', 'i.allocation_id')
            ->whereIn('ba.warehouse_id', $this->reportWarehouseIds($request))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('ba.warehouse_id', (int) $request->input('warehouse_id')))
            ->whereNull('s.deleted_at')
            ->when($request->filled('status'), fn ($q) => $q->where('s.status', $request->input('status')))
            ->when($request->filled('document_type'), fn ($q) => $q->where('s.document_type', $request->input('document_type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('s.settlement_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('s.settlement_date', '<=', $request->input('to')))
            ->select(['i.id', 'i.amount', 's.id as settlement_id', 's.document_number', 's.document_type', 's.status', 's.settlement_date', 'p.code as party_code', 'p.name as party_name', 'w.code as warehouse_code', 'oi.id as open_item_id', 'oi.document_number as open_item_number', 'a.id as allocation_id', 'a.reversal_date']);

        return DataTables::query($query)
            ->addColumn('settlement_url', fn ($row) => route('finance.settlements.show', $row->settlement_id))
            ->addColumn('open_item_url', fn ($row) => $row->open_item_id ? route($row->document_type === 'RECEIPT' ? 'finance.receivables.open-items.show' : 'finance.payables.open-items.show', $row->open_item_id) : null)
            ->addColumn('allocation_status', fn ($row) => ! $row->allocation_id ? 'รอจัดสรร' : ($row->reversal_date ? 'ยกเลิกการจัดสรร' : 'จัดสรรแล้ว'))
            ->addColumn('date_label', fn ($row) => Carbon::parse($row->settlement_date)->format('d/m/Y'))
            ->addColumn('type_label', fn ($row) => $row->document_type === 'RECEIPT' ? 'รับเงิน' : 'จ่ายเงิน')
            ->toJson();
    }

    public function pettyCashIndex(): View
    {
        return view('Finance::reports.petty-cash', ['branches' => $this->reportBranches(request())]);
    }

    public function cashPositionIndex(): View
    {
        return view('Finance::reports.cash-position', ['branches' => $this->reportBranches(request())]);
    }

    public function cashPositionData(Request $request): JsonResponse
    {
        $movements = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('e.status', 'POSTED')->whereIn('l.subledger_type', ['BANK', 'CASH'])->whereNotNull('l.subledger_id')->whereIn('e.branch_id', $this->reportBranchIds($request))->when($request->filled('from'), fn ($q) => $q->whereDate('e.entry_date', '>=', $request->input('from')))->when($request->filled('to'), fn ($q) => $q->whereDate('e.entry_date', '<=', $request->input('to')))->select('l.subledger_type', 'l.subledger_id', DB::raw('SUM(l.debit) as debit_total'), DB::raw('SUM(l.credit) as credit_total'), DB::raw('SUM(l.debit - l.credit) as balance_amount'))->groupBy('l.subledger_type', 'l.subledger_id');
        $query = DB::table('finance_bank_accounts as ba')->join('warehouses as w', 'w.id', '=', 'ba.warehouse_id')->join('branches as b', 'b.id', '=', 'w.branch_id')->leftJoinSub($movements, 'm', fn ($join) => $join->on('m.subledger_id', '=', 'ba.id')->on('m.subledger_type', '=', 'ba.type'))->whereIn('ba.warehouse_id', $this->reportWarehouseIds($request))->whereNull('ba.deleted_at')->when($request->filled('account_type'), fn ($q) => $q->where('ba.type', $request->input('account_type')))->select(['ba.id', 'ba.code', 'ba.name', 'ba.type', 'ba.currency_code', 'w.code as warehouse_code', 'b.code as branch_code', DB::raw('COALESCE(m.debit_total, 0) as debit_total'), DB::raw('COALESCE(m.credit_total, 0) as credit_total'), DB::raw('COALESCE(m.balance_amount, 0) as balance_amount')])->orderBy('b.code')->orderBy('ba.code');
        return DataTables::query($query)->addColumn('account_label', fn ($row) => $row->code.' · '.$row->name)->addColumn('type_label', fn ($row) => $row->type === 'CASH' ? 'เงินสด' : 'ธนาคาร')->toJson();
    }

    public function expectedIndex(): View
    {
        return view('Finance::reports.expected', ['branches' => $this->reportBranches(request())]);
    }

    public function expectedData(Request $request): JsonResponse
    {
        $asOf = $request->input('as_of', today()->toDateString());
        $allocated = DB::table('finance_allocations')->selectRaw('debit_open_item_id AS open_item_id, amount')->where('allocation_date', '<=', $asOf)->where(fn ($q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))->unionAll(DB::table('finance_allocations')->selectRaw('credit_open_item_id AS open_item_id, amount')->where('allocation_date', '<=', $asOf)->where(fn ($q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf)));
        $allocated = DB::query()->fromSub($allocated, 'allocation_rows')->select('open_item_id')->selectRaw('SUM(amount) AS allocated_amount')->groupBy('open_item_id');
        $advanceApplied = DB::table('finance_advance_deposit_applications')->selectRaw('open_item_id, SUM(amount) AS applied_amount')->where('application_date', '<=', $asOf)->where(fn ($q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))->groupBy('open_item_id');
        $query = DB::table('finance_open_items as oi')->join('parties as p', 'p.id', '=', 'oi.party_id')->join('warehouses as w', 'w.id', '=', 'oi.warehouse_id')->join('branches as b', 'b.id', '=', 'w.branch_id')->leftJoinSub($allocated, 'a', 'a.open_item_id', '=', 'oi.id')->leftJoinSub($advanceApplied, 'aa', 'aa.open_item_id', '=', 'oi.id')->whereIn('oi.warehouse_id', $this->reportWarehouseIds($request))->where('oi.posting_date', '<=', $asOf)->whereRaw('oi.original_amount - COALESCE(a.allocated_amount, 0) - COALESCE(aa.applied_amount, 0) > 0')->when($request->filled('from'), fn ($q) => $q->whereDate('oi.due_date', '>=', $request->input('from')))->when($request->filled('to'), fn ($q) => $q->whereDate('oi.due_date', '<=', $request->input('to')))->select(['oi.id', 'oi.ledger_type', 'oi.document_number', 'oi.document_date', 'oi.due_date', 'p.code as party_code', 'p.name as party_name', 'b.code as branch_code', 'w.code as warehouse_code', 'oi.original_amount'])->selectRaw('COALESCE(a.allocated_amount, 0) + COALESCE(aa.applied_amount, 0) AS allocated_amount')->selectRaw('oi.original_amount - COALESCE(a.allocated_amount, 0) - COALESCE(aa.applied_amount, 0) AS outstanding_amount')->orderByRaw('oi.due_date IS NULL')->orderBy('oi.due_date')->orderBy('oi.id');
        return DataTables::query($query)->addColumn('type_label', fn ($row) => $row->ledger_type === 'AR' ? 'รอรับเงิน' : 'รอจ่ายเงิน')->addColumn('party_label', fn ($row) => $row->party_code.' · '.$row->party_name)->addColumn('due_date_label', fn ($row) => $row->due_date ? Carbon::parse($row->due_date)->format('d/m/Y') : 'ไม่กำหนด')->addColumn('document_date_label', fn ($row) => Carbon::parse($row->document_date)->format('d/m/Y'))->addColumn('show_url', fn ($row) => route($row->ledger_type === 'AR' ? 'finance.receivables.open-items.show' : 'finance.payables.open-items.show', $row->id))->toJson();
    }

    public function pettyCashData(Request $request): JsonResponse
    {
        $topUps = DB::table('finance_petty_cash_top_ups')->select('petty_cash_fund_id', DB::raw('SUM(amount) as top_up_amount'))->where('status', 'POSTED')->whereNull('deleted_at')->when($request->filled('from'), fn ($q) => $q->whereDate('document_date', '>=', $request->input('from')))->when($request->filled('to'), fn ($q) => $q->whereDate('document_date', '<=', $request->input('to')))->groupBy('petty_cash_fund_id');
        $vouchers = DB::table('finance_petty_cash_vouchers')->select('petty_cash_fund_id', DB::raw('SUM(total_amount) as voucher_amount'))->where('status', 'POSTED')->whereNull('deleted_at')->when($request->filled('from'), fn ($q) => $q->whereDate('document_date', '>=', $request->input('from')))->when($request->filled('to'), fn ($q) => $q->whereDate('document_date', '<=', $request->input('to')))->groupBy('petty_cash_fund_id');
        $query = DB::table('finance_petty_cash_funds as f')->leftJoinSub($topUps, 'tu', 'tu.petty_cash_fund_id', '=', 'f.id')->leftJoinSub($vouchers, 'v', 'v.petty_cash_fund_id', '=', 'f.id')->join('warehouses as w', 'w.id', '=', 'f.warehouse_id')->join('branches as b', 'b.id', '=', 'w.branch_id')->whereIn('f.warehouse_id', $this->reportWarehouseIds($request))->whereNull('f.deleted_at')->when($request->filled('fund_status'), fn ($q) => $q->where('f.is_active', $request->input('fund_status') === 'ACTIVE'))->select(['f.id', 'f.name', 'f.fund_limit', 'f.is_active', 'w.code as warehouse_code', 'b.code as branch_code', DB::raw('COALESCE(tu.top_up_amount, 0) as top_up_amount'), DB::raw('COALESCE(v.voucher_amount, 0) as voucher_amount'), DB::raw('(COALESCE(tu.top_up_amount, 0) - COALESCE(v.voucher_amount, 0)) as balance_amount')]);
        return DataTables::query($query)->addColumn('fund_label', fn ($row) => $row->name ?: 'วงเงินสดย่อย #'.$row->id)->addColumn('status_label', fn ($row) => $row->is_active ? 'ใช้งาน' : 'ปิดใช้งาน')->addColumn('utilization', fn ($row) => (float) $row->fund_limit > 0 ? ((float) $row->voucher_amount / (float) $row->fund_limit) * 100 : 0)->addColumn('show_url', fn ($row) => route('finance.petty-cash-funds.edit', $row->id))->toJson();
    }

    public function paymentData(Request $request, GlobalSettings $settings): JsonResponse
    {
        $dateFormat = (string) $settings->value('date_format');
        $query = $this->paymentQuery($request);

        return DataTables::query($query)
            ->filter(fn (Builder $query) => $this->applySearch($query, $request))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('date_label', fn ($row) => Carbon::parse($row->document_date)->format($dateFormat))
            ->addColumn('type_label', fn ($row) => $row->document_type === 'RECEIPT' ? 'รับเงิน' : 'จ่ายเงิน')
            ->addColumn('party_label', fn ($row) => $row->party_code ? $row->party_code.' · '.$row->party_name : '—')
            ->addColumn('status_label', fn ($row) => match ($row->status) {
                'POSTED' => 'ลงบัญชีแล้ว', 'APPROVED' => 'อนุมัติแล้ว', 'VOID' => 'ยกเลิก', default => 'ร่าง',
            })
            ->addColumn('show_url', fn ($row) => route('finance.settlements.show', $row->id))
            ->toJson();
    }

    public function employeeAdvanceIndex(): View
    {
        return view('Finance::reports.employee-advances', ['branches' => $this->reportBranches(request())]);
    }

    public function employeeAdvanceData(Request $request): JsonResponse
    {
        $clearings = DB::table('finance_employee_advance_clearings')
            ->select('employee_advance_id', DB::raw('SUM(net_expense_amount) as expense_amount'), DB::raw('SUM(refund_amount) as refund_amount'), DB::raw('SUM(additional_amount) as additional_amount'))
            ->whereIn('status', ['POSTED', 'CLEARED'])
            ->whereNull('deleted_at')
            ->groupBy('employee_advance_id');
        $query = DB::table('finance_employee_advances as a')
            ->join('users as u', 'u.id', '=', 'a.employee_user_id')
            ->join('branches as b', 'b.id', '=', 'a.branch_id')
            ->leftJoinSub($clearings, 'c', 'c.employee_advance_id', '=', 'a.id')
            ->whereIn('a.warehouse_id', $this->reportWarehouseIds($request))
            ->when($request->filled('status'), fn ($q) => $q->where('a.status', $request->input('status')), fn ($q) => $q->whereIn('a.status', ['POSTED', 'PARTIAL', 'CLEARED']))
            ->whereNull('a.deleted_at')
            ->when($request->filled('from'), fn ($q) => $q->whereDate('a.document_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('a.document_date', '<=', $request->input('to')))
            ->select(['a.id', 'a.document_number', 'a.document_date', 'a.due_date', 'a.amount', 'a.status', 'u.name as employee_name', 'b.code as branch_code', DB::raw('COALESCE(c.expense_amount, 0) as expense_amount'), DB::raw('COALESCE(c.refund_amount, 0) as refund_amount'), DB::raw('COALESCE(c.additional_amount, 0) as additional_amount'), DB::raw('(a.amount - COALESCE(c.expense_amount, 0) - COALESCE(c.refund_amount, 0) + COALESCE(c.additional_amount, 0)) as outstanding_amount')]);
        return DataTables::query($query)->addColumn('date_label', fn ($row) => Carbon::parse($row->document_date)->format('d/m/Y'))->addColumn('due_date_label', fn ($row) => $row->due_date ? Carbon::parse($row->due_date)->format('d/m/Y') : '—')->addColumn('status_label', fn ($row) => match ($row->status) { 'PARTIAL' => 'เคลียร์บางส่วน', 'CLEARED' => 'เคลียร์แล้ว', default => 'ลงบัญชีแล้ว' })->addColumn('show_url', fn ($row) => route('finance.employee-advances.show', $row->id))->toJson();
    }

    public function reconciliationIndex(): View
    {
        return view('Finance::reports.reconciliation', ['branches' => $this->reportBranches(request())]);
    }

    public function reconciliationData(Request $request): JsonResponse
    {
        $labels = [
            'FINANCE_PETTY_CASH' => 'ใบสำคัญเงินสดย่อย',
            'FINANCE_PETTY_CASH_TOP_UP' => 'เติมเงินสดย่อย',
            'FINANCE_PETTY_CASH_CLEARING' => 'เคลียร์เงินสดย่อย',
            'FINANCE_EMPLOYEE_ADVANCE' => 'เงินทดรองพนักงาน',
            'FIN_EMP_ADV_CLEARING' => 'เคลียร์เงินทดรองพนักงาน',
        ];
        $journalRows = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->whereIn('je.source_type', array_keys($labels))
            ->whereIn('je.branch_id', $this->reportBranchIds($request))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('je.entry_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('je.entry_date', '<=', $request->input('to')))
            ->select(['je.id', 'je.source_type'])
            ->selectRaw('ROUND(SUM(jel.debit), 2) as debit_total')
            ->selectRaw('ROUND(SUM(jel.credit), 2) as credit_total')
            ->groupBy('je.id', 'je.source_type');
        $journalTotals = DB::query()->fromSub($journalRows, 'journal_rows')
            ->select('source_type')
            ->selectRaw('COUNT(*) as journal_count')
            ->selectRaw('SUM(debit_total) as debit_total')
            ->selectRaw('SUM(credit_total) as credit_total')
            ->selectRaw('SUM(debit_total - credit_total) as difference')
            ->selectRaw('SUM(CASE WHEN ABS(debit_total - credit_total) >= 0.005 THEN 1 ELSE 0 END) as unbalanced_journal_count')
            ->groupBy('source_type');
        $sourceRows = [
            ['table' => 'finance_petty_cash_vouchers', 'type' => 'FINANCE_PETTY_CASH', 'date' => 'document_date', 'statuses' => ['POSTED'], 'scope' => 'warehouse'],
            ['table' => 'finance_petty_cash_top_ups', 'type' => 'FINANCE_PETTY_CASH_TOP_UP', 'date' => 'document_date', 'statuses' => ['POSTED'], 'scope' => 'warehouse'],
            ['table' => 'finance_petty_cash_clearings', 'type' => 'FINANCE_PETTY_CASH_CLEARING', 'date' => 'clearing_date', 'statuses' => ['POSTED'], 'scope' => 'warehouse'],
            ['table' => 'finance_employee_advances', 'type' => 'FINANCE_EMPLOYEE_ADVANCE', 'date' => 'document_date', 'statuses' => ['POSTED', 'PARTIAL'], 'scope' => 'branch'],
            ['table' => 'finance_employee_advance_clearings', 'type' => 'FIN_EMP_ADV_CLEARING', 'date' => 'document_date', 'statuses' => ['POSTED', 'CLEARED'], 'scope' => 'branch'],
        ];
        $sourceTotals = null;
        foreach ($sourceRows as $source) {
            $rows = DB::table($source['table'])
                ->when($source['scope'] === 'warehouse', fn ($q) => $q->join('warehouses as source_warehouses', 'source_warehouses.id', '=', $source['table'].'.warehouse_id')->whereIn('source_warehouses.branch_id', $this->reportBranchIds($request)))
                ->whereIn($source['table'].'.status', $source['statuses'])
                ->whereNull($source['table'].'.deleted_at')
                ->when($source['scope'] !== 'warehouse', fn ($q) => $q->whereIn($source['table'].'.branch_id', $this->reportBranchIds($request)))
                ->when($request->filled('from'), fn ($q) => $q->whereDate($source['table'].'.'.$source['date'], '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate($source['table'].'.'.$source['date'], '<=', $request->input('to')))
                ->selectRaw("'{$source['type']}' as source_type")
                ->selectRaw('COUNT(*) as source_count')
                ->selectRaw('SUM(CASE WHEN journal_entry_id IS NULL THEN 1 ELSE 0 END) as missing_journal_count')
                ->groupBy('source_type');
            $sourceTotals = $sourceTotals ? $sourceTotals->unionAll($rows) : $rows;
        }
        $sourceTotals = DB::query()->fromSub($sourceTotals, 'source_totals')
            ->select('source_type')
            ->selectRaw('SUM(source_count) as source_count')
            ->selectRaw('SUM(missing_journal_count) as missing_journal_count')
            ->groupBy('source_type');
        $query = DB::query()->fromSub($sourceTotals, 's')
            ->leftJoinSub($journalTotals, 'j', 'j.source_type', '=', 's.source_type')
            ->when($request->boolean('exceptions_only'), fn ($q) => $q->where(function ($q): void {
                $q->where('s.missing_journal_count', '>', 0)
                    ->orWhere('j.unbalanced_journal_count', '>', 0)
                    ->orWhereRaw('COALESCE(j.journal_count, 0) <> s.source_count')
                    ->orWhereRaw('ABS(COALESCE(j.difference, 0)) >= 0.005');
            }))
            ->select(['s.source_type', 's.source_count', DB::raw('COALESCE(j.journal_count, 0) as journal_count'), DB::raw('COALESCE(j.debit_total, 0) as debit_total'), DB::raw('COALESCE(j.credit_total, 0) as credit_total'), DB::raw('COALESCE(j.difference, 0) as difference'), DB::raw('COALESCE(j.unbalanced_journal_count, 0) as unbalanced_journal_count'), 's.missing_journal_count']);
        return DataTables::query($query)
            ->addColumn('source_label', fn ($row) => $labels[$row->source_type] ?? $row->source_type)
            ->addColumn('source_url', fn ($row) => match ($row->source_type) {
                'FINANCE_PETTY_CASH' => route('finance.petty-cash.index'),
                'FINANCE_PETTY_CASH_TOP_UP' => route('finance.petty-cash-top-ups.index'),
                'FINANCE_PETTY_CASH_CLEARING' => route('finance.petty-cash-clearings.index'),
                'FINANCE_EMPLOYEE_ADVANCE' => route('finance.employee-advances.index'),
                'FIN_EMP_ADV_CLEARING' => route('finance.employee-advance-clearings.index'),
                default => null,
            })
            ->addColumn('balanced', fn ($row) => (int) $row->missing_journal_count > 0 ? 'ไม่พบ Journal' : ((int) $row->journal_count !== (int) $row->source_count ? 'จำนวน Journal ไม่ตรง' : ((int) $row->unbalanced_journal_count > 0 ? 'Journal ไม่สมดุล' : 'สมดุล')))
            ->toJson();
    }

    private function paymentQuery(Request $request): Builder
    {
        return DB::table('finance_settlements as s')
            ->join('finance_bank_accounts as b', 'b.id', '=', 's.bank_account_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('branches as br', 'br.id', '=', 'w.branch_id')
            ->leftJoin('parties as p', 'p.id', '=', 's.party_id')
            ->leftJoin('journal_entries as j', 'j.id', '=', 's.journal_entry_id')
            ->whereIn('b.warehouse_id', $this->reportWarehouseIds($request))
            ->whereNull('s.deleted_at')
            ->when($request->filled('status'), fn ($q) => $q->where('s.status', $request->input('status')))
            ->when($request->filled('document_type'), fn ($q) => $q->where('s.document_type', $request->input('document_type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('s.document_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('s.document_date', '<=', $request->input('to')))
            ->select([
                's.id', 's.document_number', 's.document_type', 's.document_date', 's.status',
                's.net_amount', 'b.code as bank_code', 'br.code as branch_code', 'p.code as party_code', 'p.name as party_name',
                'j.entry_number as journal_number',
            ]);
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function reportBranches(Request $request): Collection
    {
        return $request->user()->branches()->where('branches.is_active', true)->orderBy('code')->get(['branches.id', 'branches.code', 'branches.name']);
    }

    private function reportWarehouses(Request $request): Collection
    {
        return $request->user()->warehouses()->where('warehouses.is_active', true)->whereIn('warehouses.branch_id', $this->reportBranchIds($request))->orderBy('warehouses.code')->get(['warehouses.id', 'warehouses.code', 'warehouses.name']);
    }

    /** @return list<int> */
    private function reportBranchIds(Request $request): array
    {
        $allowed = $this->reportBranches($request)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $scope = (string) $request->input('branch_scope', 'current');
        if ($scope === 'all') {
            return $allowed;
        }
        $selected = ctype_digit($scope) ? (int) $scope : (int) $request->attributes->get('selectedBranch')->id;
        return in_array($selected, $allowed, true) ? [$selected] : [(int) $request->attributes->get('selectedBranch')->id];
    }

    /** @return list<int> */
    private function reportWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('warehouses.is_active', true)->whereIn('branch_id', $this->reportBranchIds($request))->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('s.document_number', 'like', "%{$search}%")
                ->orWhere('s.document_type', 'like', "%{$search}%")
                ->orWhere('s.status', 'like', "%{$search}%")
                ->orWhere('p.code', 'like', "%{$search}%")
                ->orWhere('p.name', 'like', "%{$search}%"));
        }
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 's.document_number', 1 => 's.document_type', 2 => 's.document_date', 3 => 'p.code', 4 => 's.net_amount', 5 => 's.status'];
        $column = $columns[(int) $request->input('order.0.column', 2)] ?? $columns[2];
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($column, $direction)->orderBy('s.id', 'desc');
    }
}
