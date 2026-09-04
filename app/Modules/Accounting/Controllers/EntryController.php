<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EntryController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('Accounting::dashboard', [
            'program' => $request->attributes->get('selectedProgram'),
            'warehouse' => $request->attributes->get('selectedWarehouse'),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'periods' => FiscalPeriod::query()->with('fiscalYear')->orderByDesc('start_date')->get(),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $warehouseId = (int) optional($request->attributes->get('selectedWarehouse'))->id;
        $branchScope = (string) $request->input('branch_scope', 'current');
        $warehouseIds = $branchScope === 'all' ? $request->user()->warehouses()->where('warehouses.is_active', true)->pluck('warehouses.id')->all() : ($branchScope !== 'current' ? $request->user()->warehouses()->where('warehouses.branch_id', (int) $branchScope)->pluck('warehouses.id')->all() : [$warehouseId]);
        if ($branchScope !== 'current') $warehouseId = (int) ($warehouseIds[0] ?? 0);
        $period = $request->filled('period_id') ? FiscalPeriod::query()->with('fiscalYear')->find($request->integer('period_id')) : null;
        $period = $period ?: (FiscalPeriod::query()->with('fiscalYear')->where('start_date', '<=', now()->toDateString())->where('end_date', '>=', now()->toDateString())->first()
            ?? FiscalPeriod::query()->with('fiscalYear')->orderByDesc('start_date')->first());
        $empty = ['period_label' => $period?->fiscalYear?->name.' / '.$period?->name, 'stats' => ['posted' => 0, 'pending' => 0, 'debit' => 0, 'credit' => 0, 'accounts' => 0], 'financial' => [], 'trend' => [], 'aging' => ['AR' => [], 'AP' => []]];
        if (! $period || ! $warehouseId) return response()->json($empty);
        $base = DB::table('journal_entries as e')->whereIn('e.warehouse_id', $warehouseIds ?: [0]);
        $posted = (clone $base)->where('e.status', 'POSTED')->whereBetween('e.entry_date', [$period->start_date, $period->end_date]);
        $totals = (clone $posted)->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')->selectRaw('COUNT(DISTINCT e.id) AS posted, COALESCE(SUM(l.debit),0) AS debit, COALESCE(SUM(l.credit),0) AS credit')->first();
        $open = DB::table('finance_open_items')->whereIn('warehouse_id', $warehouseIds ?: [0])->selectRaw('ledger_type, SUM(original_amount) AS amount')->groupBy('ledger_type')->pluck('amount', 'ledger_type');
        $trend = (clone $posted)->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')->join('accounts as a', 'a.id', '=', 'l.account_id')->join('account_types as at', 'at.id', '=', 'a.account_type_id')->selectRaw("DATE_FORMAT(e.entry_date, '%Y-%m') AS month, SUM(CASE WHEN at.code='REVENUE' THEN l.credit-l.debit ELSE 0 END) AS revenue, SUM(CASE WHEN at.code='EXPENSE' THEN l.debit-l.credit ELSE 0 END) AS expense")->groupByRaw("DATE_FORMAT(e.entry_date, '%Y-%m')")->orderBy('month')->get();
        $agingRows = DB::table('finance_open_items')->whereIn('warehouse_id', $warehouseIds ?: [0])->selectRaw("ledger_type, CASE WHEN due_date IS NULL OR DATEDIFF(CURDATE(), due_date) <= 0 THEN 'current' WHEN DATEDIFF(CURDATE(), due_date) <= 30 THEN 'days_1_30' WHEN DATEDIFF(CURDATE(), due_date) <= 60 THEN 'days_31_60' WHEN DATEDIFF(CURDATE(), due_date) <= 90 THEN 'days_61_90' ELSE 'over_90' END AS bucket, SUM(original_amount) AS amount")->groupBy('ledger_type', 'bucket')->get();
        $cash = (clone $base)->where('e.status', 'POSTED')->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.control_account_type', ['CASH', 'BANK'])->selectRaw('SUM(l.debit - l.credit)')->value('SUM(l.debit - l.credit)');
        $pl = (clone $posted)->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')->join('accounts as a', 'a.id', '=', 'l.account_id')->join('account_types as at', 'at.id', '=', 'a.account_type_id')->selectRaw("SUM(CASE WHEN at.code='REVENUE' THEN l.credit-l.debit ELSE 0 END) AS revenue, SUM(CASE WHEN at.code='EXPENSE' THEN l.debit-l.credit ELSE 0 END) AS expense")->first();
        $aging = ['AR' => [], 'AP' => []]; foreach ($agingRows as $row) $aging[$row->ledger_type][$row->bucket] = (float) $row->amount;
        return response()->json(['period_label' => $period->fiscalYear?->name.' / '.$period->name, 'stats' => ['posted' => (int) ($totals->posted ?? 0), 'pending' => (int) (clone $base)->whereIn('e.status', ['DRAFT', 'VALIDATED'])->count(), 'debit' => (float) ($totals->debit ?? 0), 'credit' => (float) ($totals->credit ?? 0), 'accounts' => (int) Account::query()->where('is_active', true)->where('is_postable', true)->count()], 'financial' => ['cash' => (float) ($cash ?? 0), 'receivable' => (float) ($open['AR'] ?? 0), 'payable' => (float) ($open['AP'] ?? 0), 'profit' => (float) (($pl->revenue ?? 0) - ($pl->expense ?? 0)), 'revenue' => (float) ($pl->revenue ?? 0), 'expense' => (float) ($pl->expense ?? 0)], 'trend' => $trend, 'aging' => $aging
        ]);
    }
}
