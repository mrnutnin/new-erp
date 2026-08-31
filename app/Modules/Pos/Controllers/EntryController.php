<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Models\BranchSalesTarget;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesOrder;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EntryController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('Pos::dashboard', [
            'branch' => $request->attributes->get('selectedBranch'),
            'canViewIntakes' => $request->user()->hasPermission('pos.sales-intakes.view'),
            'canViewRfqs' => $request->user()->hasPermission('pos.sales-rfqs.view'),
            'canViewOrders' => $request->user()->hasPermission('pos.sales-orders.view'),
            'canViewPhysicalSales' => $request->user()->hasPermission('pos.physical-sales.view'),
            'canViewAdvanceDeposits' => $request->user()->hasPermission('pos.advance-deposits.view'),
            'canViewReceivables' => $request->user()->hasPermission('pos.receivables.view'),
            'canViewReports' => $request->user()->hasPermission('pos.sales-reports.view'),
            'canManageBranchTargets' => $request->user()->hasPermission('pos.branch-sales-targets.create'),
        ]);
    }

    public function data(Request $request, string $section): JsonResponse
    {
        abort_unless(in_array($section, ['summary', 'trend', 'mix', 'work', 'recent', 'top-items', 'document-counts', 'receivable-alert'], true), 404);

        $branchId = (int) $request->attributes->get('selectedBranch')->id;

        if (in_array($section, ['summary', 'trend', 'mix', 'top-items'], true)) {
            abort_unless($request->user()->hasPermission('pos.sales-reports.view'), 403);
        }
        if ($section === 'recent') {
            abort_unless($request->user()->hasPermission('pos.physical-sales.view'), 403);
        }
        if ($section === 'receivable-alert') {
            abort_unless($request->user()->hasPermission('pos.receivables.view'), 403);
        }

        if ($section === 'work') {
            return response()->json($this->work($request, $branchId));
        }

        $data = Cache::remember("pos:dashboard:{$section}:branch:{$branchId}", now()->addSeconds(30), fn () => match ($section) {
            'summary' => $this->summary($branchId),
            'trend' => $this->trend($branchId),
            'mix' => $this->mix($branchId),
            'recent' => $this->recent($branchId),
            'top-items' => $this->topItems($branchId),
            'document-counts' => $this->documentCounts($branchId),
            'receivable-alert' => $this->receivableAlert($branchId),
        });

        return response()->json($section === 'document-counts' ? $this->visibleDocumentCounts($request, $data) : $data);
    }

    private function summary(int $branchId): array
    {
        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();
        $today = now()->toDateString();
        $sales = PhysicalSale::query()->where('branch_id', $branchId)->where('status', 'POSTED')->whereBetween('posting_date', [$start, $end])
            ->selectRaw("COALESCE(SUM(CASE WHEN document_type = 'HS' THEN total_amount ELSE 0 END), 0) AS hs_month, COALESCE(SUM(CASE WHEN document_type = 'IV' THEN total_amount ELSE 0 END), 0) AS iv_month, COALESCE(SUM(CASE WHEN posting_date = ? THEN total_amount ELSE 0 END), 0) AS sales_today", [$today])->first();
        $creditNotes = DB::table('pos_sales_returns as returns')->join('pos_physical_sales as sales', 'sales.id', '=', 'returns.physical_sale_id')
            ->where('returns.branch_id', $branchId)->where('returns.status', 'POSTED')->where('sales.status', 'POSTED')->whereBetween('returns.posting_date', [$start, $end])
            ->selectRaw('COALESCE(SUM(returns.total_amount), 0) AS month_amount, COALESCE(SUM(CASE WHEN returns.posting_date = ? THEN returns.total_amount ELSE 0 END), 0) AS today_amount', [$today])->first();
        $target = BranchSalesTarget::query()->where('branch_id', $branchId)->where('period_start', $start)->where('period_end', $end)->first();
        $targetSales = $this->targetNetSales($branchId, $start, $end);
        $hs = (float) ($sales?->hs_month ?? 0);
        $iv = (float) ($sales?->iv_month ?? 0);
        $creditNote = (float) ($creditNotes?->month_amount ?? 0);

        return [
            'sales_today' => (float) ($sales?->sales_today ?? 0) - (float) ($creditNotes?->today_amount ?? 0),
            'sales_month' => $hs + $iv - $creditNote,
            'hs_month' => $hs,
            'iv_month' => $iv,
            'credit_note_month' => $creditNote,
            'target_sales' => (float) ($target?->target_sales_amount ?? 0),
            'actual_target_sales' => $targetSales,
            'target_percent' => $target && (float) $target->target_sales_amount > 0 ? round(($targetSales / (float) $target->target_sales_amount) * 100, 2) : null,
        ];
    }

    private function trend(int $branchId): array
    {
        $today = now()->toDateString();
        $start = now()->subDays(6)->toDateString();
        $dailySales = DB::query()->fromSub(
            DB::table('pos_physical_sales')->where('branch_id', $branchId)->where('status', 'POSTED')->whereBetween('posting_date', [$start, $today])->selectRaw('posting_date AS report_date, SUM(total_amount) AS amount')->groupBy('posting_date')
                ->unionAll(DB::table('pos_sales_returns as returns')->join('pos_physical_sales as sales', 'sales.id', '=', 'returns.physical_sale_id')->where('returns.branch_id', $branchId)->where('returns.status', 'POSTED')->where('sales.status', 'POSTED')->whereBetween('returns.posting_date', [$start, $today])->selectRaw('returns.posting_date AS report_date, -SUM(returns.total_amount) AS amount')->groupBy('returns.posting_date')),
            'daily_sales'
        )->selectRaw('report_date, SUM(amount) AS amount')->groupBy('report_date')->pluck('amount', 'report_date');
        $period = collect(CarbonPeriod::create($start, $today));

        return [
            'labels' => $period->map(fn ($date) => $date->format('d/m'))->values(),
            'values' => $period->map(fn ($date) => (float) ($dailySales[$date->toDateString()] ?? 0))->values(),
        ];
    }

    private function mix(int $branchId): array
    {
        $mix = PhysicalSale::query()->where('branch_id', $branchId)->where('status', 'POSTED')->whereBetween('posting_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->selectRaw("SUM(document_type = 'HS') AS hs, SUM(document_type = 'IV') AS iv")->first();

        return ['values' => [(int) ($mix?->hs ?? 0), (int) ($mix?->iv ?? 0)]];
    }

    private function work(Request $request, int $branchId): array
    {
        $counts = Cache::remember("pos:dashboard:work:branch:{$branchId}", now()->addSeconds(30), function () use ($branchId): array {
            $orders = SalesOrder::query()->where('branch_id', $branchId)->selectRaw("SUM(status = 'CONFIRMED') AS confirmed_orders, SUM(status = 'DRAFT') AS draft_orders")->first();

            return [
                'confirmed_orders' => (int) ($orders?->confirmed_orders ?? 0),
                'draft_orders' => (int) ($orders?->draft_orders ?? 0),
                'draft_physical_sales' => PhysicalSale::query()->where('branch_id', $branchId)->where('status', 'DRAFT')->count(),
            ];
        });

        return [
            'confirmed_orders' => $request->user()->hasPermission('pos.sales-orders.view') ? $counts['confirmed_orders'] : null,
            'draft_orders' => $request->user()->hasPermission('pos.sales-orders.view') ? $counts['draft_orders'] : null,
            'draft_physical_sales' => $request->user()->hasPermission('pos.physical-sales.view') ? $counts['draft_physical_sales'] : null,
        ];
    }

    private function recent(int $branchId): array
    {
        return PhysicalSale::query()->where('branch_id', $branchId)->where('status', 'POSTED')->latest('posting_date')->latest('id')->limit(5)
            ->get(['id', 'document_type', 'document_number', 'posting_date', 'party_name', 'total_amount'])
            ->map(fn (PhysicalSale $sale) => ['document_number' => $sale->document_number, 'document_type' => $sale->document_type, 'posting_date' => $sale->posting_date?->format('d/m/Y'), 'party_name' => $sale->party_name, 'total_amount' => (float) $sale->total_amount, 'show_url' => route('pos.physical-sales.show', $sale)])
            ->all();
    }

    private function topItems(int $branchId): array
    {
        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();
        $returned = DB::table('pos_sales_return_lines as return_lines')
            ->join('pos_sales_returns as returns', 'returns.id', '=', 'return_lines.sales_return_id')
            ->join('pos_physical_sale_lines as returned_lines', 'returned_lines.id', '=', 'return_lines.physical_sale_line_id')
            ->where('returns.status', 'POSTED')
            ->selectRaw('return_lines.physical_sale_line_id, SUM(return_lines.quantity * returned_lines.uom_factor) AS returned_quantity')
            ->groupBy('return_lines.physical_sale_line_id');

        return DB::table('pos_physical_sale_lines as lines')
            ->join('pos_physical_sales as sales', 'sales.id', '=', 'lines.physical_sale_id')
            ->join('wms_items as items', 'items.id', '=', 'lines.item_id')
            ->leftJoinSub($returned, 'returned', 'returned.physical_sale_line_id', '=', 'lines.id')
            ->where('sales.branch_id', $branchId)
            ->where('sales.status', 'POSTED')
            ->whereBetween('sales.posting_date', [$start, $end])
            ->selectRaw('items.code AS item_code, items.name AS item_name, COALESCE(SUM(GREATEST(lines.stock_quantity - COALESCE(returned.returned_quantity, 0), 0)), 0) AS quantity, COALESCE(SUM(lines.tax_base * (CASE WHEN lines.stock_quantity = 0 THEN 0 ELSE GREATEST(lines.stock_quantity - COALESCE(returned.returned_quantity, 0), 0) / lines.stock_quantity END)), 0) AS net_sales')
            ->groupBy('items.id', 'items.code', 'items.name')
            ->orderByDesc('net_sales')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get()
            ->values()
            ->map(fn ($item, int $index) => [
                'rank' => $index + 1,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'quantity' => (float) $item->quantity,
                'net_sales' => (float) $item->net_sales,
            ])
            ->all();
    }

    private function documentCounts(int $branchId): array
    {
        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();
        $counts = [
            'intakes' => DB::table('sales_intakes')->where('branch_id', $branchId)->where('status', 'COMPLETED')->whereBetween('document_date', [$start, $end])->count(),
            'rfqs' => DB::table('sales_rfqs')->where('branch_id', $branchId)->where('status', 'APPROVED')->whereBetween('document_date', [$start, $end])->count(),
            'orders' => DB::table('sales_orders')->where('branch_id', $branchId)->where('status', 'CONFIRMED')->whereBetween('document_date', [$start, $end])->count(),
            'hs' => PhysicalSale::query()->where('branch_id', $branchId)->where('document_type', 'HS')->where('status', 'POSTED')->whereBetween('posting_date', [$start, $end])->count(),
            'iv' => PhysicalSale::query()->where('branch_id', $branchId)->where('document_type', 'IV')->where('status', 'POSTED')->whereBetween('posting_date', [$start, $end])->count(),
            'advance_deposits' => DB::table('finance_advance_deposits')->where('branch_id', $branchId)->where('party_type', 'CUSTOMER')->where('direction', 'RECEIPT')->whereIn('status', ['POSTED', 'PARTIAL', 'APPLIED'])->whereBetween('posting_date', [$start, $end])->count(),
        ];

        return [
            ['key' => 'intakes', 'label' => 'ใบรับข้อมูล (เสร็จสิ้น)', 'count' => $counts['intakes']],
            ['key' => 'rfqs', 'label' => 'ใบขอราคา (อนุมัติ)', 'count' => $counts['rfqs']],
            ['key' => 'orders', 'label' => 'ใบสั่งขาย (ยืนยัน)', 'count' => $counts['orders']],
            ['key' => 'hs', 'label' => 'HS · ขายสด (Post)', 'count' => $counts['hs']],
            ['key' => 'iv', 'label' => 'IV · ขายเชื่อ (Post)', 'count' => $counts['iv']],
            ['key' => 'advance_deposits', 'label' => 'ใบรับมัดจำ (บันทึกแล้ว)', 'count' => $counts['advance_deposits']],
        ];
    }

    private function visibleDocumentCounts(Request $request, array $counts): array
    {
        $permissions = [
            'intakes' => 'pos.sales-intakes.view',
            'rfqs' => 'pos.sales-rfqs.view',
            'orders' => 'pos.sales-orders.view',
            'hs' => 'pos.physical-sales.view',
            'iv' => 'pos.physical-sales.view',
            'advance_deposits' => 'pos.advance-deposits.view',
        ];

        return collect($counts)
            ->filter(fn (array $count) => $request->user()->hasPermission($permissions[$count['key']]))
            ->map(fn (array $count) => ['label' => $count['label'], 'count' => $count['count']])
            ->values()
            ->all();
    }

    private function receivableAlert(int $branchId): array
    {
        $today = now()->toDateString();
        $dueUntil = now()->addDays(3)->toDateString();
        $allocations = DB::table('finance_allocations')
            ->selectRaw('debit_open_item_id AS open_item_id, SUM(amount) AS amount')
            ->where('allocation_date', '<=', $today)
            ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $today))
            ->groupBy('debit_open_item_id');
        $advances = DB::table('finance_advance_deposit_applications')
            ->selectRaw('open_item_id, SUM(amount) AS amount')
            ->where('application_date', '<=', $today)
            ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $today))
            ->groupBy('open_item_id');
        $remaining = 'oi.original_amount - COALESCE(allocations.amount, 0) - COALESCE(advances.amount, 0)';
        $result = DB::table('finance_open_items as oi')
            ->join('pos_physical_sales as sales', fn ($join) => $join->on('sales.warehouse_id', '=', 'oi.warehouse_id')->on('sales.document_number', '=', 'oi.document_number')->where('sales.document_type', 'IV')->where('sales.status', 'POSTED'))
            ->leftJoinSub($allocations, 'allocations', 'allocations.open_item_id', '=', 'oi.id')
            ->leftJoinSub($advances, 'advances', 'advances.open_item_id', '=', 'oi.id')
            ->where('sales.branch_id', $branchId)
            ->where('oi.ledger_type', 'AR')
            ->where('oi.party_type', 'CUSTOMER')
            ->where('oi.balance_side', 'DEBIT')
            ->where('oi.document_type', 'INVOICE')
            ->whereBetween('oi.due_date', [$today, $dueUntil])
            ->whereRaw("{$remaining} > 0")
            ->selectRaw("COUNT(*) AS count, COALESCE(SUM({$remaining}), 0) AS total_amount, MIN(oi.due_date) AS nearest_due_date")
            ->first();

        return [
            'count' => (int) ($result?->count ?? 0),
            'total_amount' => (float) ($result?->total_amount ?? 0),
            'nearest_due_date' => $result?->nearest_due_date,
            'due_until' => $dueUntil,
            'url' => route('pos.receivables.index', ['due_from' => $today, 'due_to' => $dueUntil]),
        ];
    }

    private function targetNetSales(int $branchId, string $from, string $to): float
    {
        $returned = DB::table('pos_sales_return_lines as return_lines')->join('pos_sales_returns as returns', 'returns.id', '=', 'return_lines.sales_return_id')
            ->where('returns.status', 'POSTED')->selectRaw('return_lines.physical_sale_line_id, SUM(return_lines.quantity) AS returned_quantity')->groupBy('return_lines.physical_sale_line_id');

        return (float) DB::table('pos_physical_sale_lines as lines')->join('pos_physical_sales as sales', 'sales.id', '=', 'lines.physical_sale_id')
            ->leftJoinSub($returned, 'returned', 'returned.physical_sale_line_id', '=', 'lines.id')
            ->where('sales.branch_id', $branchId)->where('sales.status', 'POSTED')->whereBetween('sales.posting_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(lines.tax_base * (CASE WHEN lines.quantity = 0 THEN 0 ELSE GREATEST(lines.quantity - COALESCE(returned.returned_quantity, 0), 0) / lines.quantity END)), 0) AS amount')->value('amount');
    }
}
