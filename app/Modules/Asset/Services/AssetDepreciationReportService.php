<?php

namespace App\Modules\Asset\Services;

use App\Models\Branch;
use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Reads posted run snapshots so reports never recalculate historical depreciation. */
final class AssetDepreciationReportService
{
    public function scheduleQuery(Branch $branch, FiscalPeriod $period, string $bookType, ?int $categoryId = null): Builder
    {
        return DB::table('asset_depreciation_lines as lines')->join('asset_depreciation_runs as runs', 'runs.id', '=', 'lines.asset_depreciation_run_id')
            ->where('runs.branch_id', $branch->id)->where('runs.fiscal_period_id', $period->id)->where('runs.book_type', $bookType)->where('runs.status', 'POSTED')
            ->when($categoryId, fn (Builder $query) => $query->whereIn('lines.asset_id', DB::table('assets')->where('asset_category_id', $categoryId)->select('id')))
            ->select([
                'lines.id', 'runs.document_number', 'runs.run_through_date', 'lines.asset_number', 'lines.category_name',
                'lines.opening_cost', 'lines.opening_accumulated_depreciation', 'lines.period_depreciation', 'lines.catch_up_adjustment',
                'lines.closing_accumulated_depreciation',
            ])
            ->selectRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(lines.calculation_explanation, '$.closing_value')) AS DECIMAL(18,2)) AS closing_value");
    }

    public function scheduleTotals(Branch $branch, FiscalPeriod $period, string $bookType, ?int $categoryId = null): object
    {
        $row = DB::query()->fromSub($this->scheduleQuery($branch, $period, $bookType, $categoryId), 'rows')
            ->selectRaw('COALESCE(SUM(period_depreciation), 0) AS period_depreciation')
            ->selectRaw('COALESCE(SUM(catch_up_adjustment), 0) AS catch_up_adjustment')
            ->selectRaw('COALESCE(SUM(closing_value), 0) AS closing_value')->first();

        return (object) [
            'period_depreciation' => (string) $row->period_depreciation,
            'catch_up_adjustment' => (string) $row->catch_up_adjustment,
            'closing_value' => (string) $row->closing_value,
        ];
    }

    public function comparisonQuery(Branch $branch, FiscalPeriod $period, ?int $categoryId = null): Builder
    {
        $lines = fn (string $bookType): Builder => DB::table('asset_depreciation_lines as lines')->join('asset_depreciation_runs as runs', 'runs.id', '=', 'lines.asset_depreciation_run_id')
            ->where('runs.branch_id', $branch->id)->where('runs.fiscal_period_id', $period->id)->where('runs.book_type', $bookType)->where('runs.status', 'POSTED')
            ->groupBy('lines.asset_id')->select('lines.asset_id')
            ->selectRaw('SUM(lines.period_depreciation + lines.catch_up_adjustment) AS depreciation')
            ->selectRaw("MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(lines.calculation_explanation, '$.closing_value')) AS DECIMAL(18,2))) AS closing_value");

        $book = $lines('BOOK');
        $tax = $lines('TAX');

        return DB::table('assets')->leftJoinSub($book, 'book_lines', 'book_lines.asset_id', '=', 'assets.id')
            ->leftJoinSub($tax, 'tax_lines', 'tax_lines.asset_id', '=', 'assets.id')
            ->leftJoin('asset_categories as categories', 'categories.id', '=', 'assets.asset_category_id')
            ->where('assets.branch_id', $branch->id)->when($categoryId, fn (Builder $query) => $query->where('assets.asset_category_id', $categoryId))->where(fn (Builder $query) => $query->whereNotNull('book_lines.asset_id')->orWhereNotNull('tax_lines.asset_id'))
            ->select(['assets.id', 'assets.asset_number', 'assets.name', 'categories.name as category_name'])
            ->selectRaw('COALESCE(book_lines.depreciation, 0) AS book_depreciation')
            ->selectRaw('COALESCE(tax_lines.depreciation, 0) AS tax_depreciation')
            ->selectRaw('book_lines.closing_value AS book_closing_value')
            ->selectRaw('tax_lines.closing_value AS tax_closing_value')
            ->selectRaw('COALESCE(book_lines.depreciation, 0) - COALESCE(tax_lines.depreciation, 0) AS difference');
    }

    public function comparisonTotals(Branch $branch, FiscalPeriod $period, ?int $categoryId = null): object
    {
        $row = DB::query()->fromSub($this->comparisonQuery($branch, $period, $categoryId), 'rows')
            ->selectRaw('COALESCE(SUM(book_depreciation), 0) AS book_depreciation')
            ->selectRaw('COALESCE(SUM(tax_depreciation), 0) AS tax_depreciation')
            ->selectRaw('COALESCE(SUM(difference), 0) AS difference')->first();

        return (object) [
            'book_depreciation' => (string) $row->book_depreciation,
            'tax_depreciation' => (string) $row->tax_depreciation,
            'difference' => (string) $row->difference,
        ];
    }
}
