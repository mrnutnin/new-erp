<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Services\AssetDepreciationReportService;
use App\Modules\Asset\Services\AssetReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class AssetDepreciationReportController extends Controller
{
    public function index(AssetReconciliationService $reconciliation): View
    {
        return view('Asset::reports.depreciation', ['periods' => $reconciliation->periods(), 'categories' => AssetCategory::query()->orderBy('name')->get(['id', 'code', 'name'])]);
    }

    public function scheduleData(Request $request, AssetDepreciationReportService $reports): JsonResponse
    {
        [$period, $bookType, $categoryId] = $this->filters($request);
        $branch = $request->attributes->get('selectedBranch');

        return DataTables::query($reports->scheduleQuery($branch, $period, $bookType, $categoryId))
            ->with('totals', $reports->scheduleTotals($branch, $period, $bookType, $categoryId))->toJson();
    }

    public function comparisonData(Request $request, AssetDepreciationReportService $reports): JsonResponse
    {
        [$period, , $categoryId] = $this->filters($request);
        $branch = $request->attributes->get('selectedBranch');

        return DataTables::query($reports->comparisonQuery($branch, $period, $categoryId))
            ->with('totals', $reports->comparisonTotals($branch, $period, $categoryId))->toJson();
    }

    /** @return array{FiscalPeriod, string, ?int} */
    private function filters(Request $request): array
    {
        $values = $request->validate([
            'period_id' => ['required', 'integer', 'exists:fiscal_periods,id'],
            'book_type' => ['nullable', Rule::in(['BOOK', 'TAX'])],
            'asset_category_id' => ['nullable', 'integer', 'exists:asset_categories,id'],
        ]);

        return [FiscalPeriod::query()->findOrFail($values['period_id']), $values['book_type'] ?? 'BOOK', $values['asset_category_id'] ?? null];
    }
}
