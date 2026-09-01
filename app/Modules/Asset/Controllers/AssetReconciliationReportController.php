<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Asset\Services\AssetReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use App\Modules\Platform\Services\ModuleCapability;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class AssetReconciliationReportController extends Controller
{
    public function index(AssetReconciliationService $reports): View
    {
        return view('Asset::reports.reconciliation', ['periods' => $reports->periods()]);
    }

    public function data(Request $request, AssetReconciliationService $reports): JsonResponse
    {
        $filters = $request->validate([
            'period_id' => ['required', 'integer', 'exists:fiscal_periods,id'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'balance_type' => ['nullable', 'in:COST,ACCUMULATED_DEPRECIATION,ACCUMULATED_IMPAIRMENT'],
        ]);
        $period = FiscalPeriod::query()->findOrFail($filters['period_id']);
        $branch = $request->attributes->get('selectedBranch');

        return DataTables::query($reports->query($branch, $period, $filters['account_id'] ?? null, $filters['balance_type'] ?? null))
            ->addColumn('account_url', fn ($row) => route('asset.reports.reconciliation.accounting', ['period_id' => $period->id, 'account_id' => $row->account_id]))
            ->addColumn('drilldown_label', fn ($row) => abs((float) $row->difference) > 0.005 ? 'ตรวจสอบผลต่าง' : 'เปิด GL')
            ->with('totals', $reports->totals($branch, $period, $filters['account_id'] ?? null, $filters['balance_type'] ?? null))
            ->toJson();
    }

    public function accountOptions(Request $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());
        $page = max(1, $request->integer('page', 1));
        $rows = Account::query()->where('is_active', true)
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')->forPage($page, 31)->get(['id', 'code', 'name']);

        return response()->json(['results' => $rows->take(30)->map(fn (Account $account) => ['id' => $account->id, 'text' => $account->code.' · '.$account->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function handoffToAccounting(Request $request, ModuleCapability $capability): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('accounting.reports.view'), 403);
        $program = $request->user()->programs()->where('code', 'accounting')->where('is_enabled', true)->first();
        abort_if($program === null || ! $capability->isProgramAvailable('accounting'), 403, 'คุณไม่มีสิทธิ์ใช้งานโปรแกรม Accounting');

        $request->session()->put('selected_program_id', $program->id);

        return redirect()->route('accounting.reports.general-ledger.index', array_filter([
            'period_id' => $request->integer('period_id') ?: null,
            'account_id' => $request->integer('account_id') ?: null,
            'asset_scope' => 1,
        ]));
    }
}
