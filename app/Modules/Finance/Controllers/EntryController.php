<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Models\EmployeeAdvanceClearing;
use App\Modules\Finance\Models\PaymentVoucher;
use App\Modules\Finance\Models\PettyCashTopUp;
use App\Modules\Finance\Models\PettyCashVoucher;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class EntryController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('Finance::dashboard', [
            'program' => $request->attributes->get('selectedProgram'),
            'warehouse' => $request->attributes->get('selectedWarehouse'),
        ]);
    }

    public function data(Request $request, string $section, GlobalSettings $settings): JsonResponse
    {
        abort_unless(in_array($section, ['summary', 'cash-trend', 'aging', 'work', 'activities'], true), 404);

        if ($section === 'activities') {
            $this->requirePermission($request, 'finance.settlements.view');

            return $this->activities($request, $settings);
        }

        $warehouseIds = $this->authorizedWarehouseIds($request);
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $cacheKey = 'finance:dashboard:'.$section.':branch:'.$branchId.':user:'.$request->user()->id.':warehouses:'.sha1(implode(',', $warehouseIds));

        return response()->json(Cache::remember($cacheKey, now()->addSeconds(30), fn (): array => match ($section) {
            'summary' => $this->summary($request, $warehouseIds),
            'cash-trend' => $this->cashTrend($request, $warehouseIds),
            'aging' => $this->aging($request, $warehouseIds),
            'work' => $this->work($request, $warehouseIds),
        }));
    }

    private function summary(Request $request, array $warehouseIds): array
    {
        return [
            'ar_outstanding' => $request->user()->hasPermission('finance.ar-open-items.view') ? $this->outstanding('AR', $warehouseIds) : null,
            'ap_outstanding' => $request->user()->hasPermission('finance.ap-open-items.view') ? $this->outstanding('AP', $warehouseIds) : null,
            'receipts_mtd' => $request->user()->hasPermission('finance.settlements.view') ? $this->settlementMonthTotal('RECEIPT', $warehouseIds) : null,
            'payments_mtd' => $request->user()->hasPermission('finance.settlements.view') ? $this->settlementMonthTotal('PAYMENT', $warehouseIds) : null,
            'petty_cash_balance' => $request->user()->hasPermission('finance.petty-cash.view') ? $this->pettyCashBalance($warehouseIds) : null,
            'employee_advance_outstanding' => $request->user()->hasPermission('finance.employee-advances.view') ? $this->employeeAdvanceOutstanding($warehouseIds) : null,
        ];
    }

    private function cashTrend(Request $request, array $warehouseIds): array
    {
        $this->requirePermission($request, 'finance.settlements.view');
        $start = today()->startOfMonth()->subMonths(5);
        $rows = DB::table('finance_settlements as s')->join('finance_bank_accounts as b', 'b.id', '=', 's.bank_account_id')
            ->whereIn('b.warehouse_id', $warehouseIds)->whereNull('s.deleted_at')->where('s.status', 'POSTED')->whereDate('s.settlement_date', '>=', $start)
            ->selectRaw("DATE_FORMAT(s.settlement_date, '%Y-%m') AS period, s.document_type, SUM(s.net_amount) AS amount")->groupBy('period', 's.document_type')->get()->groupBy('period');
        $labels = $receipts = $payments = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = today()->startOfMonth()->subMonths($i);
            $byType = $rows->get($month->format('Y-m'), collect())->keyBy('document_type');
            $labels[] = $month->format('m/Y');
            $receipts[] = (float) ($byType->get('RECEIPT')?->amount ?? 0);
            $payments[] = (float) ($byType->get('PAYMENT')?->amount ?? 0);
        }

        return compact('labels', 'receipts', 'payments');
    }

    private function aging(Request $request, array $warehouseIds): array
    {
        return [
            'ar' => $request->user()->hasPermission('finance.ar-aging.view') ? $this->agingTotals('AR', $warehouseIds) : null,
            'ap' => $request->user()->hasPermission('finance.ap-aging.view') ? $this->agingTotals('AP', $warehouseIds) : null,
        ];
    }

    private function work(Request $request, array $warehouseIds): array
    {
        $work = [];
        if ($request->user()->hasPermission('finance.settlements.view')) {
            $work['settlements_to_post'] = $this->settlements($warehouseIds)->where('s.status', 'APPROVED')->count();
        }
        if ($request->user()->hasPermission('finance.payment-vouchers.view')) {
            $query = PaymentVoucher::query()->whereIn('warehouse_id', $warehouseIds);
            $work['vouchers_to_submit'] = (clone $query)->where('status', 'DRAFT')->count();
            $work['vouchers_to_approve'] = (clone $query)->where('status', 'SUBMITTED')->count();
            $work['vouchers_to_settle'] = (clone $query)->where('status', 'APPROVED')->whereNull('settlement_id')->count();
        }
        if ($request->user()->hasPermission('finance.advance-deposits.view')) {
            $work['advance_outstanding'] = DB::table('finance_advance_deposits')->whereIn('warehouse_id', $warehouseIds)->where('balance_amount', '>', 0)->count();
        }
        if ($request->user()->hasPermission('finance.petty-cash.view')) {
            $work['petty_cash_to_submit'] = PettyCashVoucher::query()->whereIn('warehouse_id', $warehouseIds)->where('status', 'DRAFT')->count();
            $work['petty_cash_to_approve'] = PettyCashVoucher::query()->whereIn('warehouse_id', $warehouseIds)->where('status', 'SUBMITTED')->count();
            $work['petty_cash_to_post'] = PettyCashVoucher::query()->whereIn('warehouse_id', $warehouseIds)->where('status', 'APPROVED')->count();
        }
        if ($request->user()->hasPermission('finance.petty-cash-top-ups.view')) {
            $work['petty_cash_topups_to_post'] = DB::table('finance_petty_cash_top_ups')->whereIn('warehouse_id', $warehouseIds)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count();
        }
        if ($request->user()->hasPermission('finance.petty-cash-clearings.view')) {
            $work['petty_cash_clearings_to_post'] = DB::table('finance_petty_cash_clearings')->whereIn('warehouse_id', $warehouseIds)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count();
        }
        if ($request->user()->hasPermission('finance.employee-advances.view')) {
            $work['employee_advances_to_process'] = EmployeeAdvance::query()->whereIn('warehouse_id', $warehouseIds)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count();
            $work['employee_advances_due'] = EmployeeAdvance::query()->whereIn('warehouse_id', $warehouseIds)->whereIn('status', ['POSTED', 'PARTIAL'])->whereNotNull('due_date')->whereDate('due_date', '<=', today())->count();
            $work['employee_advances_due_soon'] = EmployeeAdvance::query()->whereIn('warehouse_id', $warehouseIds)->whereIn('status', ['POSTED', 'PARTIAL'])->whereBetween('due_date', [today()->addDay(), today()->addDays(7)])->count();
        }
        if ($request->user()->hasPermission('finance.employee-advance-clearings.view')) {
            $work['employee_advance_clearings_to_process'] = EmployeeAdvanceClearing::query()->whereIn('warehouse_id', $warehouseIds)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count();
        }
        if ($request->user()->hasPermission('finance.internal-transfers.view')) {
            $work['internal_transfers_to_post'] = DB::table('finance_internal_transfers')->whereIn('warehouse_id', $warehouseIds)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count();
        }
        if ($request->user()->hasPermission('finance.reports.reconciliation.view')) {
            $work['posting_exceptions'] = $this->postingExceptionCount($warehouseIds);
        }
        if ($request->user()->hasPermission('finance.petty-cash.view') || $request->user()->hasPermission('finance.employee-advance-clearings.view')) {
            $work['duplicate_receipts'] = $this->duplicateReceiptCount($warehouseIds);
        }

        return $work;
    }

    private function postingExceptionCount(array $warehouseIds): int
    {
        $missing = 0;
        foreach ([
            ['finance_petty_cash_vouchers', ['POSTED']],
            ['finance_petty_cash_top_ups', ['POSTED']],
            ['finance_petty_cash_clearings', ['POSTED']],
            ['finance_employee_advances', ['POSTED', 'PARTIAL']],
            ['finance_employee_advance_clearings', ['POSTED', 'CLEARED']],
        ] as [$table, $statuses]) {
            $missing += DB::table($table)->whereIn('warehouse_id', $warehouseIds)->whereIn('status', $statuses)->whereNull('journal_entry_id')->whereNull($table.'.deleted_at')->count();
        }

        $unbalanced = DB::table('journal_entries as je')->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->whereIn('je.source_type', ['FINANCE_PETTY_CASH', 'FINANCE_PETTY_CASH_TOP_UP', 'FINANCE_PETTY_CASH_CLEARING', 'FINANCE_EMPLOYEE_ADVANCE', 'FIN_EMP_ADV_CLEARING'])
            ->whereIn('je.warehouse_id', $warehouseIds)->groupBy('je.id')
            ->havingRaw('ABS(ROUND(SUM(jel.debit), 2) - ROUND(SUM(jel.credit), 2)) >= 0.005')->get()->count();

        return $missing + $unbalanced;
    }

    private function duplicateReceiptCount(array $warehouseIds): int
    {
        $count = DB::table('finance_petty_cash_voucher_lines as l')->join('finance_petty_cash_vouchers as v', 'v.id', '=', 'l.petty_cash_voucher_id')
            ->whereIn('v.warehouse_id', $warehouseIds)->whereIn('v.status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED'])->whereNotNull('l.receipt_reference')->where('l.receipt_reference', '<>', '')
            ->whereNull('v.deleted_at')->select('l.receipt_reference')->groupBy('l.receipt_reference')->havingRaw('COUNT(*) > 1')->get()->count();
        $count += DB::table('finance_employee_advance_clearing_lines as l')->join('finance_employee_advance_clearings as c', 'c.id', '=', 'l.clearing_id')
            ->whereIn('c.warehouse_id', $warehouseIds)->whereIn('c.status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'CLEARED'])->whereNotNull('l.receipt_reference')->where('l.receipt_reference', '<>', '')
            ->whereNull('c.deleted_at')->select('l.receipt_reference')->groupBy('l.receipt_reference')->havingRaw('COUNT(*) > 1')->get()->count();

        return $count;
    }

    private function pettyCashBalance(array $warehouseIds): float
    {
        return (float) PettyCashTopUp::query()->whereIn('warehouse_id', $warehouseIds)->where('status', 'POSTED')->sum('amount')
            - (float) PettyCashVoucher::query()->whereIn('warehouse_id', $warehouseIds)->where('status', 'POSTED')->sum('total_amount');
    }

    private function employeeAdvanceOutstanding(array $warehouseIds): float
    {
        $issued = (float) EmployeeAdvance::query()->whereIn('warehouse_id', $warehouseIds)->where('status', 'POSTED')->sum('amount');
        $cleared = EmployeeAdvanceClearing::query()->whereIn('warehouse_id', $warehouseIds)->whereIn('status', ['POSTED', 'CLEARED'])
            ->selectRaw('COALESCE(SUM(net_expense_amount + additional_amount - refund_amount), 0) AS total')->value('total');

        return $issued - (float) $cleared;
    }

    private function activities(Request $request, GlobalSettings $settings): JsonResponse
    {
        $dateFormat = (string) $settings->value('date_format');
        $query = $this->settlements($this->authorizedWarehouseIds($request))
            ->select(['s.id', 's.document_number', 's.document_type', 's.settlement_date', 's.net_amount', 's.status', 'p.code as party_code', 'p.name as party_name', 'b.code as bank_code']);

        return DataTables::query($query)
            ->filter(function (Builder $query) use ($request): void {
                $search = trim((string) $request->input('search.value', ''));
                if ($search !== '') {
                    $query->where(fn (Builder $q) => $q->where('s.document_number', 'like', "%{$search}%")->orWhere('p.code', 'like', "%{$search}%")->orWhere('p.name', 'like', "%{$search}%")->orWhere('b.code', 'like', "%{$search}%"));
                }
            })
            ->order(function (Builder $query) use ($request): void {
                $columns = [0 => 's.document_number', 1 => 's.document_type', 2 => 's.settlement_date', 3 => 'p.code', 4 => 's.net_amount', 5 => 's.status'];
                $query->reorder($columns[(int) $request->input('order.0.column', 2)] ?? $columns[2], $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc')->orderByDesc('s.id');
            })
            ->addColumn('date_label', fn ($row) => Carbon::parse($row->settlement_date)->format($dateFormat))
            ->addColumn('type_label', fn ($row) => $row->document_type === 'RECEIPT' ? 'รับเงิน' : 'จ่ายเงิน')
            ->addColumn('party_label', fn ($row) => $row->party_code ? $row->party_code.' · '.$row->party_name : '—')
            ->addColumn('show_url', fn ($row) => route('finance.settlements.show', $row->id))
            ->toJson();
    }

    private function outstanding(string $ledgerType, array $warehouseIds): float
    {
        $positiveSide = $ledgerType === 'AR' ? 'DEBIT' : 'CREDIT';
        $remaining = 'oi.original_amount - COALESCE(a.allocated_amount, 0) - COALESCE(aa.applied_amount, 0)';

        return (float) $this->openItems($ledgerType, $warehouseIds)->selectRaw("COALESCE(SUM({$remaining} * CASE WHEN oi.balance_side = '{$positiveSide}' THEN 1 ELSE -1 END), 0) AS total")->value('total');
    }

    private function agingTotals(string $ledgerType, array $warehouseIds): array
    {
        $positiveSide = $ledgerType === 'AR' ? 'DEBIT' : 'CREDIT';
        $remaining = "(oi.original_amount - COALESCE(a.allocated_amount, 0) - COALESCE(aa.applied_amount, 0)) * CASE WHEN oi.balance_side = '{$positiveSide}' THEN 1 ELSE -1 END";
        $asOf = today()->toDateString();
        $row = $this->openItems($ledgerType, $warehouseIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN oi.due_date IS NULL OR oi.due_date >= ? THEN {$remaining} ELSE 0 END), 0) AS current_amount", [$asOf])
            ->selectRaw("COALESCE(SUM(CASE WHEN DATEDIFF(?, oi.due_date) BETWEEN 1 AND 30 THEN {$remaining} ELSE 0 END), 0) AS days_1_30", [$asOf])
            ->selectRaw("COALESCE(SUM(CASE WHEN DATEDIFF(?, oi.due_date) BETWEEN 31 AND 60 THEN {$remaining} ELSE 0 END), 0) AS days_31_60", [$asOf])
            ->selectRaw("COALESCE(SUM(CASE WHEN DATEDIFF(?, oi.due_date) BETWEEN 61 AND 90 THEN {$remaining} ELSE 0 END), 0) AS days_61_90", [$asOf])
            ->selectRaw("COALESCE(SUM(CASE WHEN DATEDIFF(?, oi.due_date) > 90 THEN {$remaining} ELSE 0 END), 0) AS days_over_90", [$asOf])->first();

        return collect($row)->map(fn ($value): float => (float) $value)->all();
    }

    private function openItems(string $ledgerType, array $warehouseIds): Builder
    {
        $asOf = today()->toDateString();
        $allocationRows = DB::table('finance_allocations')->selectRaw('debit_open_item_id AS open_item_id, amount')->where('allocation_date', '<=', $asOf)->where(fn (Builder $q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))
            ->unionAll(DB::table('finance_allocations')->selectRaw('credit_open_item_id AS open_item_id, amount')->where('allocation_date', '<=', $asOf)->where(fn (Builder $q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf)));
        $allocations = DB::query()->fromSub($allocationRows, 'allocation_rows')->select('open_item_id')->selectRaw('SUM(amount) AS allocated_amount')->groupBy('open_item_id');
        $advanceApplications = DB::table('finance_advance_deposit_applications')->selectRaw('open_item_id, SUM(amount) AS applied_amount')->where('application_date', '<=', $asOf)->where(fn (Builder $q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))->groupBy('open_item_id');

        return DB::table('finance_open_items as oi')->leftJoinSub($allocations, 'a', 'a.open_item_id', '=', 'oi.id')->leftJoinSub($advanceApplications, 'aa', 'aa.open_item_id', '=', 'oi.id')
            ->whereIn('oi.warehouse_id', $warehouseIds)->where('oi.ledger_type', $ledgerType)->where('oi.party_type', $ledgerType === 'AR' ? 'CUSTOMER' : 'SUPPLIER')->where('oi.posting_date', '<=', $asOf)
            ->whereRaw('oi.original_amount - COALESCE(a.allocated_amount, 0) - COALESCE(aa.applied_amount, 0) > 0');
    }

    private function settlementMonthTotal(string $type, array $warehouseIds): float
    {
        return (float) $this->settlements($warehouseIds)->where('s.status', 'POSTED')->where('s.document_type', $type)->whereBetween('s.settlement_date', [today()->startOfMonth(), today()->endOfMonth()])->sum('s.net_amount');
    }

    private function settlements(array $warehouseIds): Builder
    {
        return DB::table('finance_settlements as s')->join('finance_bank_accounts as b', 'b.id', '=', 's.bank_account_id')->leftJoin('parties as p', 'p.id', '=', 's.party_id')
            ->whereIn('b.warehouse_id', $warehouseIds)->whereNull('s.deleted_at');
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('is_active', true)->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function requirePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
    }
}
