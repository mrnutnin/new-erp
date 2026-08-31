<?php

namespace App\Modules\Accounting\Services;

use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Support\JournalBalance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountingReportService
{
    public function periods(): Collection
    {
        return FiscalPeriod::query()->with('fiscalYear')->orderByDesc('start_date')->get();
    }

    public function accounts(): Collection
    {
        return Account::query()->withTrashed()->orderBy('code')->get(['id', 'code', 'name', 'is_active', 'deleted_at']);
    }

    public function trialBalanceQuery(FiscalPeriod $period, Warehouse|array $warehouse): Builder
    {
        $movement = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->whereIn('entries.warehouse_id', $this->warehouseIds($warehouse))
            ->where('entries.status', 'POSTED')
            ->where('entries.entry_date', '<=', $period->end_date)
            ->select('lines.account_id')
            ->selectRaw('SUM(CASE WHEN entries.entry_date < ? THEN lines.debit ELSE 0 END) AS opening_debit', [$period->start_date])
            ->selectRaw('SUM(CASE WHEN entries.entry_date < ? THEN lines.credit ELSE 0 END) AS opening_credit', [$period->start_date])
            ->selectRaw('SUM(CASE WHEN entries.entry_date >= ? THEN lines.debit ELSE 0 END) AS period_debit', [$period->start_date])
            ->selectRaw('SUM(CASE WHEN entries.entry_date >= ? THEN lines.credit ELSE 0 END) AS period_credit', [$period->start_date])
            ->groupBy('lines.account_id');

        return Account::query()->withTrashed()
            ->joinSub($movement, 'movement', 'movement.account_id', '=', 'accounts.id')
            ->select([
                'accounts.id',
                'accounts.id as account_id',
                'accounts.code',
                'accounts.name',
                'accounts.control_account_type',
                'movement.opening_debit',
                'movement.opening_credit',
                'movement.period_debit',
                'movement.period_credit',
            ])
            ->selectRaw('GREATEST((movement.opening_debit - movement.opening_credit + movement.period_debit - movement.period_credit), 0) AS closing_debit')
            ->selectRaw('GREATEST((movement.opening_credit - movement.opening_debit - movement.period_debit + movement.period_credit), 0) AS closing_credit');
    }

    public function trialBalanceTotals(FiscalPeriod $period, Warehouse|array $warehouse): object
    {
        return DB::query()->fromSub($this->trialBalanceQuery($period, $warehouse)->toBase(), 'rows')
            ->selectRaw('COALESCE(SUM(opening_debit), 0) AS opening_debit')
            ->selectRaw('COALESCE(SUM(opening_credit), 0) AS opening_credit')
            ->selectRaw('COALESCE(SUM(period_debit), 0) AS period_debit')
            ->selectRaw('COALESCE(SUM(period_credit), 0) AS period_credit')
            ->selectRaw('COALESCE(SUM(closing_debit), 0) AS closing_debit')
            ->selectRaw('COALESCE(SUM(closing_credit), 0) AS closing_credit')
            ->first();
    }

    public function generalLedgerQuery(FiscalPeriod $period, Warehouse|array $warehouse, int $accountId): Builder
    {
        return JournalEntryLine::query()
            ->join('journal_entries as entries', 'entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('journal_books as books', 'books.id', '=', 'entries.journal_book_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->whereIn('entries.warehouse_id', $this->warehouseIds($warehouse))
            ->where('entries.status', 'POSTED')
            ->where('journal_entry_lines.account_id', $accountId)
            ->whereBetween('entries.entry_date', [$period->start_date, $period->end_date])
            ->select([
                'journal_entry_lines.id',
                'entries.id as journal_entry_id',
                'entries.entry_number',
                'entries.entry_date',
                'entries.source_reference',
                'entries.description as entry_description',
                'books.code as book_code',
                'journal_entry_lines.description as line_description',
                'journal_entry_lines.subledger_type',
                'journal_entry_lines.subledger_id',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
            ])
            ->orderBy('entries.entry_date')
            ->orderBy('entries.id')
            ->orderBy('journal_entry_lines.line_number');
    }

    public function generalLedgerSummary(FiscalPeriod $period, Warehouse|array $warehouse, int $accountId): object
    {
        $base = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->whereIn('entries.warehouse_id', $this->warehouseIds($warehouse))
            ->where('entries.status', 'POSTED')
            ->where('lines.account_id', $accountId);
        $opening = (clone $base)->where('entries.entry_date', '<', $period->start_date)->selectRaw('COALESCE(SUM(lines.debit - lines.credit), 0) AS balance')->value('balance');
        $movement = (clone $base)->whereBetween('entries.entry_date', [$period->start_date, $period->end_date])->selectRaw('COALESCE(SUM(lines.debit), 0) AS debit, COALESCE(SUM(lines.credit), 0) AS credit')->first();
        $opening = (string) ($opening ?? '0.00');
        $debit = (string) ($movement->debit ?? '0.00');
        $credit = (string) ($movement->credit ?? '0.00');

        return (object) [
            'opening_balance' => $opening,
            'period_debit' => $debit,
            'period_credit' => $credit,
            'closing_balance' => JournalBalance::subtract(JournalBalance::add($opening, $debit), $credit),
        ];
    }

    public function taxReportQuery(FiscalPeriod $period, Warehouse|array $warehouse, string $dateBasis = 'SETTLEMENT'): Builder
    {
        $dateColumn = $dateBasis === 'TAX_POINT' ? 'journal_entry_lines.tax_point_date' : 'journal_entry_lines.tax_settlement_date';

        return JournalEntryLine::query()
            ->join('journal_entries as entries', 'entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('tax_codes', 'tax_codes.id', '=', 'journal_entry_lines.tax_code_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->whereIn('entries.warehouse_id', $this->warehouseIds($warehouse))
            ->where('entries.status', 'POSTED')
            ->whereNotNull('journal_entry_lines.tax_code_id')
            ->whereNotNull($dateColumn)
            ->whereBetween($dateColumn, [$period->start_date, $period->end_date])
            ->select([
                'journal_entry_lines.id',
                'entries.id as journal_entry_id',
                'entries.entry_number',
                'accounts.code as account_code',
                'accounts.name as account_name',
                'tax_codes.code as tax_code',
                'tax_codes.name as tax_name',
                'tax_codes.kind as tax_kind',
                'journal_entry_lines.tax_base',
                'journal_entry_lines.tax_amount',
                'journal_entry_lines.tax_point_date',
                'journal_entry_lines.tax_settlement_date',
            ])
            ->orderBy($dateColumn)
            ->orderBy('entries.id')
            ->orderBy('journal_entry_lines.line_number');
    }

    public function taxReportTotals(FiscalPeriod $period, Warehouse|array $warehouse, string $dateBasis = 'SETTLEMENT'): object
    {
        $dateColumn = $dateBasis === 'TAX_POINT' ? 'lines.tax_point_date' : 'lines.tax_settlement_date';
        $row = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('tax_codes', 'tax_codes.id', '=', 'lines.tax_code_id')
            ->whereIn('entries.warehouse_id', $this->warehouseIds($warehouse))
            ->where('entries.status', 'POSTED')
            ->whereNotNull('lines.tax_code_id')
            ->whereNotNull($dateColumn)
            ->whereBetween($dateColumn, [$period->start_date, $period->end_date])
            ->selectRaw("COALESCE(SUM(CASE WHEN tax_codes.kind = 'VAT_IN' THEN lines.tax_amount ELSE 0 END), 0) AS vat_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN tax_codes.kind = 'VAT_OUT' THEN lines.tax_amount ELSE 0 END), 0) AS vat_out")
            ->selectRaw("COALESCE(SUM(CASE WHEN tax_codes.kind = 'WHT' THEN lines.tax_amount ELSE 0 END), 0) AS wht")
            ->first();

        return (object) ['vat_in' => (string) ($row->vat_in ?? '0.00'), 'vat_out' => (string) ($row->vat_out ?? '0.00'), 'wht' => (string) ($row->wht ?? '0.00')];
    }

    public function controlReconciliationQuery(FiscalPeriod $period, Warehouse|array $warehouse): Builder
    {
        $gl = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->whereIn('entries.warehouse_id', $this->warehouseIds($warehouse))
            ->where('entries.status', 'POSTED')
            ->where('entries.entry_date', '<=', $period->end_date)
            ->select('lines.account_id')
            ->selectRaw('SUM(lines.debit - lines.credit) AS gl_balance')
            ->groupBy('lines.account_id');
        $subledger = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->whereIn('entries.warehouse_id', $this->warehouseIds($warehouse))
            ->where('entries.status', 'POSTED')
            ->where('entries.entry_date', '<=', $period->end_date)
            ->whereNotNull('lines.subledger_type')
            ->whereNotNull('lines.subledger_id')
            ->select('lines.account_id')
            ->selectRaw('SUM(lines.debit - lines.credit) AS subledger_balance')
            ->groupBy('lines.account_id');

        return Account::query()->withTrashed()
            ->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->joinSub($gl, 'gl', 'gl.account_id', '=', 'accounts.id')
            ->leftJoinSub($subledger, 'subledger', 'subledger.account_id', '=', 'accounts.id')
            ->whereIn('accounts.control_account_type', ['AR', 'AP', 'INVENTORY'])
            ->select(['accounts.id', 'accounts.code', 'accounts.name', 'accounts.control_account_type', 'account_types.name as account_type_name', 'gl.gl_balance'])
            ->selectRaw('COALESCE(subledger.subledger_balance, 0) AS subledger_balance')
            ->selectRaw('gl.gl_balance - COALESCE(subledger.subledger_balance, 0) AS difference');
    }

    public function controlReconciliationTotals(FiscalPeriod $period, Warehouse|array $warehouse): object
    {
        $row = DB::query()->fromSub($this->controlReconciliationQuery($period, $warehouse)->toBase(), 'rows')
            ->selectRaw('COALESCE(SUM(gl_balance), 0) AS gl_balance')
            ->selectRaw('COALESCE(SUM(subledger_balance), 0) AS subledger_balance')
            ->selectRaw('COALESCE(SUM(difference), 0) AS difference')
            ->first();

        return (object) ['gl_balance' => (string) ($row->gl_balance ?? '0.00'), 'subledger_balance' => (string) ($row->subledger_balance ?? '0.00'), 'difference' => (string) ($row->difference ?? '0.00')];
    }

    public function profitLossQuery(FiscalPeriod $period, Warehouse|array $warehouse): Builder
    {
        $movement = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->whereIn('entries.warehouse_id', $this->warehouseIds($warehouse))
            ->where('entries.status', 'POSTED')
            ->whereBetween('entries.entry_date', [$period->start_date, $period->end_date])
            ->select('lines.account_id')
            ->selectRaw('SUM(lines.debit) AS debit')
            ->selectRaw('SUM(lines.credit) AS credit')
            ->groupBy('lines.account_id');

        return Account::query()->withTrashed()
            ->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->joinSub($movement, 'movement', 'movement.account_id', '=', 'accounts.id')
            ->where('accounts.statement_section', 'PROFIT_LOSS')
            ->select(['accounts.id', 'accounts.id as account_id', 'accounts.code', 'accounts.name', 'accounts.normal_balance', 'account_types.code as account_type_code', 'account_types.name as account_type_name', 'movement.debit', 'movement.credit'])
            ->selectRaw("CASE WHEN accounts.normal_balance = 'DEBIT' THEN movement.debit - movement.credit ELSE movement.credit - movement.debit END AS amount")
            ->selectRaw("CASE WHEN accounts.normal_balance = 'DEBIT' THEN 'ค่าใช้จ่าย' ELSE 'รายได้' END AS line_type");
    }

    public function profitLossTotals(FiscalPeriod $period, Warehouse|array $warehouse): object
    {
        $row = DB::query()->fromSub($this->profitLossQuery($period, $warehouse)->toBase(), 'rows')
            ->selectRaw("COALESCE(SUM(CASE WHEN normal_balance = 'CREDIT' THEN amount ELSE 0 END), 0) AS revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN normal_balance = 'DEBIT' THEN amount ELSE 0 END), 0) AS expense")
            ->first();
        $revenue = (string) ($row->revenue ?? '0.00');
        $expense = (string) ($row->expense ?? '0.00');

        return (object) ['revenue' => $revenue, 'expense' => $expense, 'net_profit' => JournalBalance::subtract($revenue, $expense)];
    }

    public function comparativeIncomeQuery(FiscalPeriod $period, FiscalPeriod $comparisonPeriod, Warehouse|array $warehouse): Builder
    {
        $current = $this->incomeMovementQuery($period, $warehouse);
        $comparison = $this->incomeMovementQuery($comparisonPeriod, $warehouse);

        return Account::query()->withTrashed()
            ->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->leftJoinSub($current, 'current_movement', 'current_movement.account_id', '=', 'accounts.id')
            ->leftJoinSub($comparison, 'comparison_movement', 'comparison_movement.account_id', '=', 'accounts.id')
            ->where('account_types.code', 'REVENUE')
            ->where(fn (Builder $query) => $query->whereNotNull('current_movement.account_id')->orWhereNotNull('comparison_movement.account_id'))
            ->select(['accounts.id', 'accounts.id as account_id', 'accounts.code', 'accounts.name'])
            ->selectRaw('COALESCE(current_movement.amount, 0) AS current_amount')
            ->selectRaw('COALESCE(comparison_movement.amount, 0) AS comparison_amount')
            ->selectRaw('COALESCE(current_movement.amount, 0) - COALESCE(comparison_movement.amount, 0) AS difference_amount')
            ->selectRaw('CASE WHEN COALESCE(comparison_movement.amount, 0) = 0 THEN NULL ELSE ((COALESCE(current_movement.amount, 0) - COALESCE(comparison_movement.amount, 0)) / ABS(comparison_movement.amount)) * 100 END AS change_percent');
    }

    public function comparativeIncomeTotals(FiscalPeriod $period, FiscalPeriod $comparisonPeriod, Warehouse|array $warehouse): object
    {
        $row = DB::query()->fromSub($this->comparativeIncomeQuery($period, $comparisonPeriod, $warehouse)->toBase(), 'rows')
            ->selectRaw('COALESCE(SUM(current_amount), 0) AS current_amount')
            ->selectRaw('COALESCE(SUM(comparison_amount), 0) AS comparison_amount')
            ->selectRaw('COALESCE(SUM(difference_amount), 0) AS difference_amount')
            ->first();

        return (object) [
            'current_amount' => (string) ($row->current_amount ?? '0.00'),
            'comparison_amount' => (string) ($row->comparison_amount ?? '0.00'),
            'difference_amount' => (string) ($row->difference_amount ?? '0.00'),
        ];
    }

    public function balanceSheetQuery(FiscalPeriod $period, Warehouse|array $warehouse): Builder
    {
        $movement = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->whereIn('entries.warehouse_id', $this->warehouseIds($warehouse))
            ->where('entries.status', 'POSTED')
            ->where('entries.entry_date', '<=', $period->end_date)
            ->select('lines.account_id')
            ->selectRaw('SUM(lines.debit) AS debit')
            ->selectRaw('SUM(lines.credit) AS credit')
            ->groupBy('lines.account_id');

        return Account::query()->withTrashed()
            ->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->joinSub($movement, 'movement', 'movement.account_id', '=', 'accounts.id')
            ->where('accounts.statement_section', 'BALANCE_SHEET')
            ->select(['accounts.id', 'accounts.id as account_id', 'accounts.code', 'accounts.name', 'accounts.normal_balance', 'account_types.code as account_type_code', 'account_types.name as account_type_name', 'movement.debit', 'movement.credit'])
            ->selectRaw("CASE WHEN accounts.normal_balance = 'DEBIT' THEN movement.debit - movement.credit ELSE movement.credit - movement.debit END AS amount");
    }

    public function balanceSheetTotals(FiscalPeriod $period, Warehouse|array $warehouse): object
    {
        $row = DB::query()->fromSub($this->balanceSheetQuery($period, $warehouse)->toBase(), 'rows')
            ->selectRaw("COALESCE(SUM(CASE WHEN account_type_code = 'ASSET' THEN amount ELSE 0 END), 0) AS assets")
            ->selectRaw("COALESCE(SUM(CASE WHEN account_type_code = 'LIABILITY' THEN amount ELSE 0 END), 0) AS liabilities")
            ->selectRaw("COALESCE(SUM(CASE WHEN account_type_code = 'EQUITY' THEN amount ELSE 0 END), 0) AS equity")
            ->first();
        $pnl = $this->profitLossTotalsThrough($period, $warehouse);
        $assets = (string) ($row->assets ?? '0.00');
        $liabilities = (string) ($row->liabilities ?? '0.00');
        $equity = JournalBalance::add((string) ($row->equity ?? '0.00'), $pnl->net_profit);
        $liabilitiesAndEquity = JournalBalance::add($liabilities, $equity);

        return (object) [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'liabilitiesAndEquity' => $liabilitiesAndEquity,
            'net_profit' => $pnl->net_profit,
            'difference' => JournalBalance::subtract($assets, $liabilitiesAndEquity),
        ];
    }

    private function profitLossTotalsThrough(FiscalPeriod $period, Warehouse|array $warehouse): object
    {
        $row = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->whereIn('entries.warehouse_id', $this->warehouseIds($warehouse))
            ->where('entries.status', 'POSTED')
            ->where('accounts.statement_section', 'PROFIT_LOSS')
            ->where('entries.entry_date', '<=', $period->end_date)
            ->selectRaw("COALESCE(SUM(CASE WHEN accounts.normal_balance = 'CREDIT' THEN lines.credit - lines.debit ELSE 0 END), 0) AS revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN accounts.normal_balance = 'DEBIT' THEN lines.debit - lines.credit ELSE 0 END), 0) AS expense")
            ->first();

        return (object) ['net_profit' => JournalBalance::subtract((string) ($row->revenue ?? '0.00'), (string) ($row->expense ?? '0.00'))];
    }

    private function incomeMovementQuery(FiscalPeriod $period, Warehouse|array $warehouse)
    {
        return DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->whereIn('entries.warehouse_id', $this->warehouseIds($warehouse))
            ->where('entries.status', 'POSTED')
            ->whereBetween('entries.entry_date', [$period->start_date, $period->end_date])
            ->select('lines.account_id')
            ->selectRaw('SUM(lines.credit - lines.debit) AS amount')
            ->groupBy('lines.account_id');
    }

    /** @return list<int> */
    private function warehouseIds(Warehouse|array $warehouse): array
    {
        $ids = $warehouse instanceof Warehouse ? [$warehouse->id] : $warehouse;

        return collect($ids)->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
    }
}
