<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\AccountingReportService;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Services\InventoryReconciliationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class AccountingReportController extends Controller
{
    public function trialBalanceIndex(AccountingReportService $reports): View
    {
        return view('Accounting::reports.trial-balance', ['periods' => $reports->periods()]);
    }

    public function trialBalanceData(Request $request, AccountingReportService $reports): JsonResponse
    {
        $period = $this->period($request);
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $query = $reports->trialBalanceQuery($period, $warehouseIds);

        return DataTables::eloquent($query)
            ->filter(fn (Builder $query) => $this->applyTrialSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyTrialOrder($query, $request))
            ->with('totals', $reports->trialBalanceTotals($period, $warehouseIds))
            ->toJson();
    }

    public function trialBalanceExport(Request $request, AccountingReportService $reports): StreamedResponse
    {
        $period = $this->period($request);
        $query = $reports->trialBalanceQuery($period, $this->authorizedWarehouseIds($request));
        $this->applyTrialSearch($query, $request);
        $this->applyTrialOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo $this->workbookStart('Trial Balance');
            echo $this->excelRow(['บัญชี', 'ชื่อบัญชี', 'ยอดยกมาเดบิต', 'ยอดยกมาเครดิต', 'เดบิตงวด', 'เครดิตงวด', 'ยอดปิดเดบิต', 'ยอดปิดเครดิต']);
            foreach ($query->lazy(500) as $row) {
                echo $this->excelRow([$row->code, $row->name, $row->opening_debit, $row->opening_credit, $row->period_debit, $row->period_credit, $row->closing_debit, $row->closing_credit]);
            }
            echo $this->workbookEnd();
        }, 'trial-balance-'.$period->name.'-'.now()->format('Ymd-His').'.xls', ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
    }

    public function generalLedgerIndex(AccountingReportService $reports): View
    {
        $selectedAccountId = (int) request('account_id');

        return view('Accounting::reports.general-ledger', [
            'periods' => $reports->periods(),
            'selectedAccount' => $selectedAccountId > 0
                ? Account::query()->withTrashed()->find($selectedAccountId)
                : Account::query()->withTrashed()->orderBy('code')->first(),
        ]);
    }

    public function generalLedgerAccountOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $page = max(1, $request->integer('page', 1));
        $accounts = Account::query()
            ->withTrashed()
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')
            ->forPage($page, 31)
            ->get(['id', 'code', 'name', 'deleted_at']);

        return response()->json([
            'results' => $accounts->take(30)->map(fn (Account $account) => [
                'id' => $account->id,
                'text' => $account->code.' · '.$account->name.($account->deleted_at ? ' (ปิด/ลบ)' : ''),
            ])->values(),
            'pagination' => ['more' => $accounts->count() > 30],
        ]);
    }

    public function generalLedgerData(Request $request, AccountingReportService $reports, GlobalSettings $settings): JsonResponse
    {
        $period = $this->period($request);
        $accountId = (int) $request->input('account_id');
        if ($accountId < 1) {
            throw ValidationException::withMessages(['account_id' => 'กรุณาเลือกบัญชี']);
        }
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $query = $reports->generalLedgerQuery($period, $warehouseIds, $accountId);

        $dateFormat = (string) $settings->value('date_format');

        return DataTables::eloquent($query)
            ->filter(fn (Builder $query) => $this->applyLedgerSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyLedgerOrder($query, $request))
            ->addColumn('entry_date_label', fn ($line) => Carbon::parse($line->entry_date)->format($dateFormat))
            ->addColumn('entry_url', fn ($line) => route('accounting.journal-entries.show', $line->journal_entry_id))
            ->with('summary', $reports->generalLedgerSummary($period, $warehouseIds, $accountId))
            ->toJson();
    }

    public function generalLedgerExport(Request $request, AccountingReportService $reports, GlobalSettings $settings): StreamedResponse
    {
        $period = $this->period($request);
        $accountId = (int) $request->input('account_id');
        if ($accountId < 1) {
            throw ValidationException::withMessages(['account_id' => 'กรุณาเลือกบัญชี']);
        }
        $query = $reports->generalLedgerQuery($period, $this->authorizedWarehouseIds($request), $accountId);
        $this->applyLedgerSearch($query, $request);
        $this->applyLedgerOrder($query, $request);

        $dateFormat = (string) $settings->value('date_format');

        return response()->streamDownload(function () use ($query, $dateFormat) {
            echo $this->workbookStart('General Ledger');
            echo $this->excelRow(['วันที่', 'เลขที่', 'สมุด', 'เอกสารอ้างอิง', 'คำอธิบาย', 'Subledger', 'เดบิต', 'เครดิต']);
            foreach ($query->lazy(500) as $line) {
                echo $this->excelRow([
                    Carbon::parse($line->entry_date)->format($dateFormat), $line->entry_number, $line->book_code,
                    $line->source_reference, $line->entry_description ?: $line->line_description,
                    $line->subledger_type && $line->subledger_id ? $line->subledger_type.' · '.$line->subledger_id : '',
                    $line->debit, $line->credit,
                ]);
            }
            echo $this->workbookEnd();
        }, 'general-ledger-'.now()->format('Ymd-His').'.xls', ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
    }

    public function profitLossIndex(AccountingReportService $reports): View
    {
        return view('Accounting::reports.profit-loss', ['periods' => $reports->periods()]);
    }

    public function profitLossData(Request $request, AccountingReportService $reports): JsonResponse
    {
        $period = $this->period($request);
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $query = $reports->profitLossQuery($period, $warehouseIds);

        return DataTables::eloquent($query)
            ->filter(fn (Builder $query) => $this->applyStatementSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyStatementOrder($query, $request))
            ->addColumn('account_url', fn ($row) => route('accounting.reports.general-ledger.index', ['period_id' => $period->id, 'account_id' => $row->account_id]))
            ->with('totals', $reports->profitLossTotals($period, $warehouseIds))
            ->toJson();
    }

    public function profitLossExport(Request $request, AccountingReportService $reports): StreamedResponse
    {
        $period = $this->period($request);
        $query = $reports->profitLossQuery($period, $this->authorizedWarehouseIds($request));
        $this->applyStatementSearch($query, $request);
        $this->applyStatementOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo $this->workbookStart('Profit Loss');
            echo $this->excelRow(['ประเภท', 'รหัสบัญชี', 'ชื่อบัญชี', 'เดบิต', 'เครดิต', 'จำนวนเงิน']);
            foreach ($query->lazy(500) as $row) {
                echo $this->excelRow([$row->line_type, $row->code, $row->name, $row->debit, $row->credit, $row->amount]);
            }
            echo $this->workbookEnd();
        }, 'profit-loss-'.$period->name.'-'.now()->format('Ymd-His').'.xls', ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
    }

    public function comparativeIncomeIndex(AccountingReportService $reports): View
    {
        return view('Accounting::reports.comparative-income', ['periods' => $reports->periods()]);
    }

    public function comparativeIncomeData(Request $request, AccountingReportService $reports): JsonResponse
    {
        $period = $this->period($request);
        $comparisonPeriod = $this->comparisonPeriod($request);
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $query = $reports->comparativeIncomeQuery($period, $comparisonPeriod, $warehouseIds);

        return DataTables::eloquent($query)
            ->filter(fn (Builder $query) => $this->applyComparativeIncomeSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyComparativeIncomeOrder($query, $request))
            ->addColumn('current_account_url', fn ($row) => route('accounting.reports.general-ledger.index', ['period_id' => $period->id, 'account_id' => $row->account_id]))
            ->addColumn('comparison_account_url', fn ($row) => route('accounting.reports.general-ledger.index', ['period_id' => $comparisonPeriod->id, 'account_id' => $row->account_id]))
            ->with('totals', $reports->comparativeIncomeTotals($period, $comparisonPeriod, $warehouseIds))
            ->toJson();
    }

    public function comparativeIncomeExport(Request $request, AccountingReportService $reports): StreamedResponse
    {
        $period = $this->period($request);
        $comparisonPeriod = $this->comparisonPeriod($request);
        $query = $reports->comparativeIncomeQuery($period, $comparisonPeriod, $this->authorizedWarehouseIds($request));
        $this->applyComparativeIncomeSearch($query, $request);
        $this->applyComparativeIncomeOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo $this->workbookStart('Comparative Income');
            echo $this->excelRow(['รหัสบัญชี', 'ชื่อบัญชี', 'รายได้งวดที่เลือก', 'รายได้งวดเปรียบเทียบ', 'ผลต่าง', 'เปลี่ยนแปลง (%)']);
            foreach ($query->lazy(500) as $row) {
                echo $this->excelRow([$row->code, $row->name, $row->current_amount, $row->comparison_amount, $row->difference_amount, $row->change_percent]);
            }
            echo $this->workbookEnd();
        }, 'comparative-income-'.$period->name.'-'.now()->format('Ymd-His').'.xls', ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
    }

    public function balanceSheetIndex(AccountingReportService $reports): View
    {
        return view('Accounting::reports.balance-sheet', ['periods' => $reports->periods()]);
    }

    public function balanceSheetData(Request $request, AccountingReportService $reports): JsonResponse
    {
        $period = $this->period($request);
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $query = $reports->balanceSheetQuery($period, $warehouseIds);

        return DataTables::eloquent($query)
            ->filter(fn (Builder $query) => $this->applyStatementSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyStatementOrder($query, $request))
            ->addColumn('account_url', fn ($row) => route('accounting.reports.general-ledger.index', ['period_id' => $period->id, 'account_id' => $row->account_id]))
            ->with('totals', $reports->balanceSheetTotals($period, $warehouseIds))
            ->toJson();
    }

    public function balanceSheetExport(Request $request, AccountingReportService $reports): StreamedResponse
    {
        $period = $this->period($request);
        $query = $reports->balanceSheetQuery($period, $this->authorizedWarehouseIds($request));
        $this->applyStatementSearch($query, $request);
        $this->applyStatementOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo $this->workbookStart('Balance Sheet');
            echo $this->excelRow(['หมวด', 'รหัสบัญชี', 'ชื่อบัญชี', 'เดบิต', 'เครดิต', 'ยอดคงเหลือ']);
            foreach ($query->lazy(500) as $row) {
                echo $this->excelRow([$row->account_type_name, $row->code, $row->name, $row->debit, $row->credit, $row->amount]);
            }
            echo $this->workbookEnd();
        }, 'balance-sheet-'.$period->name.'-'.now()->format('Ymd-His').'.xls', ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
    }

    public function taxReportIndex(AccountingReportService $reports): View
    {
        return view('Accounting::reports.tax', ['periods' => $reports->periods()]);
    }

    public function taxReportData(Request $request, AccountingReportService $reports, GlobalSettings $settings): JsonResponse
    {
        $period = $this->period($request);
        $dateBasis = $request->input('date_basis') === 'TAX_POINT' ? 'TAX_POINT' : 'SETTLEMENT';
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $query = $reports->taxReportQuery($period, $warehouseIds, $dateBasis);

        $dateFormat = (string) $settings->value('date_format');

        return DataTables::eloquent($query)
            ->filter(fn (Builder $query) => $this->applyTaxSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyTaxOrder($query, $request, $dateBasis))
            ->addColumn('tax_point_date_label', fn ($row) => filled($row->tax_point_date) ? Carbon::parse($row->tax_point_date)->format($dateFormat) : '—')
            ->addColumn('tax_settlement_date_label', fn ($row) => filled($row->tax_settlement_date) ? Carbon::parse($row->tax_settlement_date)->format($dateFormat) : '—')
            ->addColumn('entry_url', fn ($row) => route('accounting.journal-entries.show', $row->journal_entry_id))
            ->with('totals', $reports->taxReportTotals($period, $warehouseIds, $dateBasis))
            ->toJson();
    }

    public function taxReportExport(Request $request, AccountingReportService $reports, GlobalSettings $settings): StreamedResponse
    {
        $period = $this->period($request);
        $dateBasis = $request->input('date_basis') === 'TAX_POINT' ? 'TAX_POINT' : 'SETTLEMENT';
        $query = $reports->taxReportQuery($period, $this->authorizedWarehouseIds($request), $dateBasis);
        $this->applyTaxSearch($query, $request);
        $this->applyTaxOrder($query, $request, $dateBasis);
        $dateFormat = (string) $settings->value('date_format');

        return response()->streamDownload(function () use ($query, $dateFormat) {
            echo $this->workbookStart('Tax Report');
            echo $this->excelRow(['เลขที่ Journal', 'บัญชี', 'Tax Code', 'ประเภท', 'Tax Point', 'รับ/จ่ายจริง', 'ฐานภาษี', 'ภาษี']);
            foreach ($query->lazy(500) as $row) {
                echo $this->excelRow([
                    $row->entry_number,
                    $row->account_code.' · '.$row->account_name,
                    $row->tax_code.' · '.$row->tax_name,
                    $row->tax_kind,
                    filled($row->tax_point_date) ? Carbon::parse($row->tax_point_date)->format($dateFormat) : '—',
                    filled($row->tax_settlement_date) ? Carbon::parse($row->tax_settlement_date)->format($dateFormat) : '—',
                    $row->tax_base,
                    $row->tax_amount,
                ]);
            }
            echo $this->workbookEnd();
        }, 'tax-report-'.$period->name.'-'.now()->format('Ymd-His').'.xls', ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
    }

    public function withholdingExpenseIndex(): View
    {
        return view('Accounting::reports.withholding', ['direction' => 'PAYABLE', 'title' => 'รายงานภาษีหัก ณ ที่จ่าย ค่าใช้จ่าย']);
    }

    public function withholdingReceivedIndex(): View
    {
        return view('Accounting::reports.withholding', ['direction' => 'RECEIVABLE', 'title' => 'รายงานภาษีถูกหัก ณ ที่จ่าย']);
    }

    public function withholdingData(Request $request): JsonResponse
    {
        $direction = $request->input('direction') === 'RECEIVABLE' ? 'RECEIVABLE' : 'PAYABLE';
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $query = \DB::table('finance_withholding_realizations as wr')
            ->join('finance_open_items as oi', 'oi.id', '=', 'wr.open_item_id')
            ->join('parties as p', 'p.id', '=', 'oi.party_id')
            ->join('tax_codes as tc', 'tc.id', '=', 'wr.tax_code_id')
            ->leftJoin('finance_settlements as s', 's.id', '=', 'wr.settlement_id')
            ->where('wr.direction', $direction)->whereIn('oi.warehouse_id', $warehouseIds)
            ->when($request->filled('date_from'), fn ($q) => $q->where('wr.settlement_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->where('wr.settlement_date', '<=', $request->input('date_to')))
            ->select(['wr.id', 'wr.settlement_date', 'wr.tax_base', 'wr.tax_amount', 'oi.document_number', 'p.code as party_code', 'p.name as party_name', 'tc.code as tax_code', 'tc.name as tax_name', 's.document_number as settlement_number']);

        return DataTables::query($query)
            ->filter(fn ($q) => $this->applyWithholdingSearch($q, $request))
            ->order(fn ($q) => $q->orderBy('wr.settlement_date', 'desc')->orderBy('wr.id', 'desc'))
            ->editColumn('settlement_date', fn ($row) => Carbon::parse($row->settlement_date)->format((string) app(GlobalSettings::class)->value('date_format')))
            ->addColumn('party_label', fn ($row) => $row->party_code.' · '.$row->party_name)
            ->addColumn('tax_label', fn ($row) => $row->tax_code.' · '.$row->tax_name)
            ->addColumn('settlement_label', fn ($row) => $row->settlement_number ?: '—')
            ->toJson();
    }

    private function applyWithholdingSearch($query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('oi.document_number', 'like', "%{$search}%")->orWhere('s.document_number', 'like', "%{$search}%")->orWhere('p.code', 'like', "%{$search}%")->orWhere('p.name', 'like', "%{$search}%")->orWhere('tc.code', 'like', "%{$search}%"));
        }
    }

    public function reconciliationIndex(AccountingReportService $reports): View
    {
        return view('Accounting::reports.reconciliation', ['periods' => $reports->periods()]);
    }

    public function reconciliationData(Request $request, AccountingReportService $reports, InventoryReconciliationService $inventory): JsonResponse
    {
        $period = $this->period($request);
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $query = $reports->controlReconciliationQuery($period, $warehouseIds);
        $inventoryTotals = $inventory->totals($period->end_date->format('Y-m-d'), $warehouseIds, $request->integer('item_id') ?: null);

        return DataTables::eloquent($query)
            ->filter(fn (Builder $query) => $this->applyReconciliationSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyReconciliationOrder($query, $request))
            ->addColumn('account_url', fn ($row) => route('accounting.reports.general-ledger.index', ['period_id' => $period->id, 'account_id' => $row->id]))
            ->with('totals', $reports->controlReconciliationTotals($period, $warehouseIds))
            ->with('inventory_reconciliation', $inventoryTotals)
            ->toJson();
    }

    public function reconciliationExport(Request $request, AccountingReportService $reports): StreamedResponse
    {
        $period = $this->period($request);
        $query = $reports->controlReconciliationQuery($period, $this->authorizedWarehouseIds($request));
        $this->applyReconciliationSearch($query, $request);
        $this->applyReconciliationOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo $this->workbookStart('Control Reconciliation');
            echo $this->excelRow(['ประเภท', 'รหัสบัญชี', 'ชื่อบัญชี', 'ยอด GL', 'ยอด Subledger', 'ผลต่าง']);
            foreach ($query->lazy(500) as $row) {
                echo $this->excelRow([$row->control_account_type, $row->code, $row->name, $row->gl_balance, $row->subledger_balance, $row->difference]);
            }
            echo $this->workbookEnd();
        }, 'control-reconciliation-'.$period->name.'-'.now()->format('Ymd-His').'.xls', ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
    }

    private function period(Request $request): FiscalPeriod
    {
        $period = FiscalPeriod::query()->with('fiscalYear')->find((int) $request->input('period_id'));
        if (! $period) {
            throw ValidationException::withMessages(['period_id' => 'กรุณาเลือกงวดบัญชี']);
        }

        return $period;
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function comparisonPeriod(Request $request): FiscalPeriod
    {
        $period = FiscalPeriod::query()->with('fiscalYear')->find((int) $request->input('comparison_period_id'));
        if (! $period) {
            throw ValidationException::withMessages(['comparison_period_id' => 'กรุณาเลือกงวดเปรียบเทียบ']);
        }

        return $period;
    }

    private function applyTrialSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('accounts.code', 'like', "%{$search}%")->orWhere('accounts.name', 'like', "%{$search}%"));
        }
    }

    private function applyTrialOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'accounts.code', 1 => 'accounts.name', 2 => 'opening_debit', 3 => 'opening_credit', 4 => 'period_debit', 5 => 'period_credit', 6 => 'closing_debit', 7 => 'closing_credit'];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'accounts.code';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($column, $direction)->orderBy('accounts.code');
    }

    private function applyLedgerSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('entries.entry_number', 'like', "%{$search}%")
                ->orWhere('entries.source_reference', 'like', "%{$search}%")
                ->orWhere('entries.description', 'like', "%{$search}%")
                ->orWhere('journal_entry_lines.description', 'like', "%{$search}%"));
        }
    }

    private function applyLedgerOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'entries.entry_date', 1 => 'entries.entry_number', 2 => 'books.code', 3 => 'entries.source_reference', 4 => 'entries.description', 6 => 'journal_entry_lines.debit', 7 => 'journal_entry_lines.credit'];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'entries.entry_date';
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';
        $query->reorder($column, $direction)->orderBy('entries.id')->orderBy('journal_entry_lines.line_number');
    }

    private function applyStatementSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('accounts.code', 'like', "%{$search}%")
                ->orWhere('accounts.name', 'like', "%{$search}%")
                ->orWhere('account_types.name', 'like', "%{$search}%"));
        }
    }

    private function applyStatementOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'account_types.code', 1 => 'accounts.code', 2 => 'accounts.name', 3 => 'movement.debit', 4 => 'movement.credit', 5 => 'amount'];
        $column = $columns[(int) $request->input('order.0.column', 1)] ?? 'accounts.code';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($column, $direction)->orderBy('accounts.code');
    }

    private function applyComparativeIncomeSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('accounts.code', 'like', "%{$search}%")->orWhere('accounts.name', 'like', "%{$search}%"));
        }
    }

    private function applyComparativeIncomeOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'accounts.code', 1 => 'accounts.name', 2 => 'current_amount', 3 => 'comparison_amount', 4 => 'difference_amount', 5 => 'change_percent'];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'accounts.code';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($column, $direction)->orderBy('accounts.code');
    }

    private function applyTaxSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('entries.entry_number', 'like', "%{$search}%")->orWhere('tax_codes.code', 'like', "%{$search}%")->orWhere('tax_codes.name', 'like', "%{$search}%")->orWhere('accounts.code', 'like', "%{$search}%"));
        }
    }

    private function applyTaxOrder(Builder $query, Request $request, string $dateBasis): void
    {
        $dateColumn = $dateBasis === 'TAX_POINT' ? 'journal_entry_lines.tax_point_date' : 'journal_entry_lines.tax_settlement_date';
        $columns = [0 => $dateColumn, 1 => 'entries.entry_number', 2 => 'tax_codes.code', 3 => 'tax_codes.kind', 4 => 'accounts.code', 5 => 'journal_entry_lines.tax_base', 6 => 'journal_entry_lines.tax_amount'];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? $dateColumn;
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $query->reorder($column, $direction)->orderBy('entries.id')->orderBy('journal_entry_lines.line_number');
    }

    private function applyReconciliationSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('accounts.code', 'like', "%{$search}%")->orWhere('accounts.name', 'like', "%{$search}%")->orWhere('accounts.control_account_type', 'like', "%{$search}%"));
        }
    }

    private function applyReconciliationOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'accounts.control_account_type', 1 => 'accounts.code', 2 => 'accounts.name', 3 => 'gl_balance', 4 => 'subledger_balance', 5 => 'difference'];
        $column = $columns[(int) $request->input('order.0.column', 1)] ?? 'accounts.code';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($column, $direction)->orderBy('accounts.code');
    }

    private function workbookStart(string $sheet): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="'.$sheet.'"><Table>';
    }

    private function workbookEnd(): string
    {
        return '</Table></Worksheet></Workbook>';
    }

    private function excelRow(array $values): string
    {
        return '<Row>'.implode('', array_map(fn ($value) => '<Cell><Data ss:Type="String">'.htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8').'</Data></Cell>', $values)).'</Row>';
    }
}
