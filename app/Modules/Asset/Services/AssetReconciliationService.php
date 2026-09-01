<?php

namespace App\Modules\Asset\Services;

use App\Models\Branch;
use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Reads immutable Asset events against posted GL; it never changes either ledger. */
final class AssetReconciliationService
{
    public function periods(): Collection
    {
        return FiscalPeriod::query()->orderByDesc('start_date')->get(['id', 'name', 'start_date', 'end_date']);
    }

    public function query(Branch $branch, FiscalPeriod $period, ?int $accountId = null, ?string $balanceType = null): Builder
    {
        $events = fn (): Builder => DB::table('asset_value_events as events')
            ->join('assets', 'assets.id', '=', 'events.asset_id')
            ->join('asset_categories as categories', 'categories.id', '=', 'assets.asset_category_id')
            ->where('events.branch_id', $branch->id)
            ->whereDate('events.event_date', '<=', $period->end_date);

        $cost = $events()->leftJoin('asset_capitalization_lines as capitalization_lines', 'capitalization_lines.id', '=', 'events.source_line_id')
            ->selectRaw("COALESCE(capitalization_lines.asset_account_id, categories.asset_account_id) AS account_id, 'COST' AS balance_type")
            ->selectRaw("SUM(CASE WHEN events.event_type = 'OPENING' THEN 0 ELSE events.cost_delta END) AS subledger_balance")
            ->selectRaw("SUM(CASE WHEN events.event_type = 'OPENING' THEN events.cost_delta ELSE 0 END) AS opening_balance")
            ->groupBy('account_id');

        $depreciation = $events()->leftJoin('asset_depreciation_lines as depreciation_lines', 'depreciation_lines.id', '=', 'events.source_line_id')
            ->selectRaw("COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(depreciation_lines.calculation_input_snapshot, '$.accumulated_depreciation_account_id')), 'null') AS UNSIGNED), categories.accumulated_depreciation_account_id) AS account_id, 'ACCUMULATED_DEPRECIATION' AS balance_type")
            ->selectRaw("SUM(CASE WHEN events.event_type = 'OPENING' THEN 0 ELSE events.depreciation_delta END) AS subledger_balance")
            ->selectRaw("SUM(CASE WHEN events.event_type = 'OPENING' THEN events.depreciation_delta ELSE 0 END) AS opening_balance")
            ->groupBy('account_id');

        $impairment = $events()->selectRaw("categories.accumulated_impairment_account_id AS account_id, 'ACCUMULATED_IMPAIRMENT' AS balance_type")
            ->selectRaw("SUM(CASE WHEN events.event_type = 'OPENING' THEN 0 ELSE events.impairment_delta END) AS subledger_balance")
            ->selectRaw("SUM(CASE WHEN events.event_type = 'OPENING' THEN events.impairment_delta ELSE 0 END) AS opening_balance")
            ->groupBy('account_id');

        $assetBalances = DB::query()->fromSub($cost->unionAll($depreciation)->unionAll($impairment), 'asset_rows')
            ->whereNotNull('account_id')->groupBy('account_id', 'balance_type')
            ->select(['account_id', 'balance_type'])
            ->selectRaw('SUM(subledger_balance) AS subledger_balance')
            ->selectRaw('SUM(opening_balance) AS opening_balance');

        $glBalances = DB::table('journal_entry_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            // A reversed entry remains part of the immutable GL and is neutralized by its posted reversal.
            ->where('entries.branch_id', $branch->id)->whereIn('entries.status', ['POSTED', 'REVERSED'])->whereDate('entries.entry_date', '<=', $period->end_date)
            ->groupBy('lines.account_id')->select('lines.account_id')->selectRaw('SUM(lines.debit - lines.credit) AS gl_balance');

        return DB::query()->fromSub($assetBalances, 'asset_balances')->join('accounts', 'accounts.id', '=', 'asset_balances.account_id')
            ->leftJoinSub($glBalances, 'gl_balances', 'gl_balances.account_id', '=', 'asset_balances.account_id')
            ->when($accountId, fn (Builder $query) => $query->where('asset_balances.account_id', $accountId))
            ->when($balanceType, fn (Builder $query) => $query->where('asset_balances.balance_type', $balanceType))
            ->select(['accounts.id as account_id', 'accounts.code', 'accounts.name', 'asset_balances.balance_type', 'asset_balances.subledger_balance', 'asset_balances.opening_balance'])
            ->selectRaw("CASE WHEN asset_balances.balance_type = 'COST' THEN COALESCE(gl_balances.gl_balance, 0) ELSE -COALESCE(gl_balances.gl_balance, 0) END AS gl_balance")
            ->selectRaw("(CASE WHEN asset_balances.balance_type = 'COST' THEN COALESCE(gl_balances.gl_balance, 0) ELSE -COALESCE(gl_balances.gl_balance, 0) END) - asset_balances.subledger_balance AS difference");
    }

    public function totals(Branch $branch, FiscalPeriod $period, ?int $accountId = null, ?string $balanceType = null): object
    {
        $row = DB::query()->fromSub($this->query($branch, $period, $accountId, $balanceType), 'rows')
            ->selectRaw('COALESCE(SUM(subledger_balance), 0) AS subledger_balance')
            ->selectRaw('COALESCE(SUM(opening_balance), 0) AS opening_balance')
            ->selectRaw('COALESCE(SUM(gl_balance), 0) AS gl_balance')
            ->selectRaw('COALESCE(SUM(ABS(difference)), 0) AS variance')->first();

        return (object) [
            'subledger_balance' => (string) $row->subledger_balance,
            'opening_balance' => (string) $row->opening_balance,
            'gl_balance' => (string) $row->gl_balance,
            'variance' => (string) $row->variance,
        ];
    }
}
