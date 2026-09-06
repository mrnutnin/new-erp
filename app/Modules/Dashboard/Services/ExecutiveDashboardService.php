<?php

namespace App\Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ExecutiveDashboardService
{
    public function __construct(private readonly DashboardScopeService $scope) {}

    public function snapshot(User $user, array $filters): array
    {
        $from = Carbon::parse($filters['date_from'] ?? today()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($filters['date_to'] ?? today()->toDateString())->endOfDay();
        $branchIds = $this->scope->branchIds($user, $filters['branch_id'] ?? 'all');
        $periodDays = $from->diffInDays($to) + 1;
        $comparisonTo = $from->copy()->subDay()->endOfDay();
        $comparisonFrom = $comparisonTo->copy()->subDays($periodDays - 1)->startOfDay();
        $key = 'executive-dashboard:'.sha1(json_encode([$user->id, $branchIds, $from->toDateString(), $to->toDateString(), $filters['business_unit_id'] ?? 'all']));

        return Cache::remember($key, now()->addSeconds(45), fn () => [
            'filters' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'branch_id' => $filters['branch_id'] ?? 'all', 'business_unit_id' => $filters['business_unit_id'] ?? 'all'],
            'refreshed_at' => now()->toIso8601String(),
            'kpis' => $this->withComparison(
                $this->kpis($branchIds, $from, $to),
                $this->kpis($branchIds, $comparisonFrom, $comparisonTo),
            ),
            'trend' => $this->trend($branchIds, $from, $to),
            'branches' => $this->branchPerformance($branchIds, $from, $to),
            'attention' => $this->attention($branchIds, $filters['branch_id'] ?? 'all'),
            'decisions' => $this->decisions($branchIds, $filters['branch_id'] ?? 'all'),
            'meta' => ['partial' => true, 'warnings' => ['ตัวกรองหน่วยธุรกิจจะเปิดใช้เมื่อมี Business Unit master ระดับองค์กร']],
        ]);
    }

    private function withComparison(array $current, array $comparison): array
    {
        foreach (['sales', 'gross_profit', 'cash_flow'] as $key) {
            $currentValue = $current[$key]['value'] ?? null;
            $comparisonValue = $comparison[$key]['value'] ?? null;
            $current[$key]['comparison'] = $comparisonValue;
            $current[$key]['change_percent'] = $currentValue === null || $comparisonValue === null || (float) $comparisonValue === 0.0
                ? null
                : round((((float) $currentValue - (float) $comparisonValue) / abs((float) $comparisonValue)) * 100, 1);
        }

        return $current;
    }

    private function kpis(array $branchIds, Carbon $from, Carbon $to): array
    {
        $sales = DB::table('pos_physical_sales as sales')->join('warehouses', 'warehouses.id', '=', 'sales.warehouse_id')
            ->whereIn('warehouses.branch_id', $branchIds)->where('sales.status', 'POSTED')->whereBetween('sales.posting_date', [$from->toDateString(), $to->toDateString()])->sum('sales.total_amount');
        $settlements = DB::table('finance_settlements as settlements')->join('finance_bank_accounts as accounts', 'accounts.id', '=', 'settlements.bank_account_id')->join('warehouses', 'warehouses.id', '=', 'accounts.warehouse_id')
            ->whereIn('warehouses.branch_id', $branchIds)->whereNull('settlements.deleted_at')->where('settlements.status', 'POSTED')->whereBetween('settlements.settlement_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN settlements.document_type = 'RECEIPT' THEN settlements.net_amount ELSE 0 END),0) as receipts, COALESCE(SUM(CASE WHEN settlements.document_type = 'PAYMENT' THEN settlements.net_amount ELSE 0 END),0) as payments")->first();
        $inventory = Schema::hasColumn('wms_stock_balances', 'inventory_value')
            ? DB::table('wms_stock_balances as balances')->join('warehouses', 'warehouses.id', '=', 'balances.warehouse_id')->whereIn('warehouses.branch_id', $branchIds)->sum('balances.inventory_value')
            : null;
        $grossProfit = $this->grossProfit($branchIds, $from, $to);
        $receivables = $this->openItemOutstanding($branchIds, 'AR', 'CUSTOMER');
        $payables = $this->openItemOutstanding($branchIds, 'AP', 'SUPPLIER');

        return [
            'sales' => ['value' => (float) $sales, 'comparison' => null, 'status' => 'positive'],
            'gross_profit' => ['value' => $grossProfit, 'comparison' => null, 'status' => $grossProfit === null ? 'neutral' : ($grossProfit >= 0 ? 'positive' : 'danger')],
            'cash_flow' => ['value' => (float) ($settlements->receipts ?? 0) - (float) ($settlements->payments ?? 0), 'comparison' => null, 'status' => 'positive'],
            'receivables' => ['value' => $receivables, 'comparison' => null, 'status' => 'neutral'],
            'payables' => ['value' => $payables, 'comparison' => null, 'status' => 'neutral'],
            'inventory' => ['value' => $inventory === null ? null : (float) $inventory, 'comparison' => null, 'status' => 'neutral'],
        ];
    }

    private function trend(array $branchIds, Carbon $from, Carbon $to): array
    {
        $labels = [];
        $cursor = $from->copy()->startOfMonth();
        while ($cursor->lte($to)) { $labels[] = $cursor->format('m/Y'); $cursor->addMonth(); }
        $sales = DB::table('pos_physical_sales as sales')->join('warehouses', 'warehouses.id', '=', 'sales.warehouse_id')->whereIn('warehouses.branch_id', $branchIds)->where('sales.status', 'POSTED')->whereBetween('sales.posting_date', [$from->toDateString(), $to->toDateString()])->selectRaw("DATE_FORMAT(sales.posting_date, '%Y-%m') period, SUM(sales.total_amount) amount")->groupBy('period')->pluck('amount', 'period');
        $money = DB::table('finance_settlements as settlements')->join('finance_bank_accounts as accounts', 'accounts.id', '=', 'settlements.bank_account_id')->join('warehouses', 'warehouses.id', '=', 'accounts.warehouse_id')->whereIn('warehouses.branch_id', $branchIds)->whereNull('settlements.deleted_at')->where('settlements.status', 'POSTED')->whereBetween('settlements.settlement_date', [$from->toDateString(), $to->toDateString()])->selectRaw("DATE_FORMAT(settlements.settlement_date, '%Y-%m') period, SUM(CASE WHEN settlements.document_type = 'RECEIPT' THEN settlements.net_amount ELSE 0 END) receipts, SUM(CASE WHEN settlements.document_type = 'PAYMENT' THEN settlements.net_amount ELSE 0 END) payments")->groupBy('period')->get()->keyBy('period');
        $keys = collect($labels)->map(fn ($label) => Carbon::createFromFormat('m/Y', $label)->format('Y-m'));

        return ['labels' => $labels, 'sales' => $keys->map(fn ($key) => (float) ($sales[$key] ?? 0))->all(), 'receipts' => $keys->map(fn ($key) => (float) ($money[$key]->receipts ?? 0))->all(), 'payments' => $keys->map(fn ($key) => (float) ($money[$key]->payments ?? 0))->all()];
    }

    private function branchPerformance(array $branchIds, Carbon $from, Carbon $to): array
    {
        return DB::table('pos_physical_sales as sales')->join('warehouses', 'warehouses.id', '=', 'sales.warehouse_id')->join('branches', 'branches.id', '=', 'warehouses.branch_id')->whereIn('branches.id', $branchIds)->where('sales.status', 'POSTED')->whereBetween('sales.posting_date', [$from->toDateString(), $to->toDateString()])->select('branches.id', 'branches.code', 'branches.name')->selectRaw('SUM(sales.total_amount) amount')->groupBy('branches.id', 'branches.code', 'branches.name')->orderByDesc('amount')->get()->map(fn ($row) => ['label' => $row->code.' · '.$row->name, 'value' => (float) $row->amount])->all();
    }

    private function grossProfit(array $branchIds, Carbon $from, Carbon $to): ?float
    {
        $saleCosts = DB::table('wms_cost_allocations as allocations')->join('wms_stock_movements as movements', 'movements.id', '=', 'allocations.stock_movement_id')
            ->where('movements.source_type', 'POS')->where('movements.direction', 'OUT')->where('movements.status', 'POSTED')->where('allocations.status', 'POSTED')->where('allocations.cost_status', 'FINAL')
            ->selectRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(movements.metadata, '$.physical_sale_line_id')) AS UNSIGNED) AS physical_sale_line_id, SUM(ABS(allocations.value)) AS cogs_amount")
            ->groupByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(movements.metadata, '$.physical_sale_line_id')) AS UNSIGNED)");
        $rows = DB::table('pos_physical_sale_lines as lines')->join('pos_physical_sales as sales', 'sales.id', '=', 'lines.physical_sale_id')->join('warehouses', 'warehouses.id', '=', 'sales.warehouse_id')->leftJoinSub($saleCosts, 'sale_costs', 'sale_costs.physical_sale_line_id', '=', 'lines.id')
            ->whereIn('warehouses.branch_id', $branchIds)->where('sales.status', 'POSTED')->whereBetween('sales.posting_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('SUM(lines.tax_base - COALESCE(sale_costs.cogs_amount, 0)) AS gross_profit')->first();

        return $rows?->gross_profit === null ? null : (float) $rows->gross_profit;
    }

    private function openItemOutstanding(array $branchIds, string $ledgerType, string $partyType): float
    {
        $allocationRows = DB::table('finance_allocations')->selectRaw('debit_open_item_id AS open_item_id, amount')->whereNull('reversal_date')
            ->unionAll(DB::table('finance_allocations')->selectRaw('credit_open_item_id AS open_item_id, amount')->whereNull('reversal_date'));
        $allocations = DB::query()->fromSub($allocationRows, 'allocation_rows')->select('open_item_id')->selectRaw('SUM(amount) AS allocated_amount')->groupBy('open_item_id');
        $applications = DB::table('finance_advance_deposit_applications')->selectRaw('open_item_id, SUM(amount) AS applied_amount')->whereNull('reversal_date')->groupBy('open_item_id');
        $positiveSide = $ledgerType === 'AR' ? 'DEBIT' : 'CREDIT';
        $remaining = "(oi.original_amount - COALESCE(a.allocated_amount, 0) - COALESCE(aa.applied_amount, 0)) * CASE WHEN oi.balance_side = '{$positiveSide}' THEN 1 ELSE -1 END";

        return (float) DB::table('finance_open_items as oi')->join('warehouses', 'warehouses.id', '=', 'oi.warehouse_id')->leftJoinSub($allocations, 'a', 'a.open_item_id', '=', 'oi.id')->leftJoinSub($applications, 'aa', 'aa.open_item_id', '=', 'oi.id')->whereIn('warehouses.branch_id', $branchIds)->where('oi.ledger_type', $ledgerType)->where('oi.party_type', $partyType)->whereRaw("{$remaining} > 0")->sum(DB::raw($remaining));
    }

    private function attention(array $branchIds, mixed $branchFilter): array
    {
        $items = [];
        $lowStock = DB::table('wms_stock_policies as policies')->join('wms_stock_balances as balances', function ($join) { $join->on('balances.warehouse_id', '=', 'policies.warehouse_id'); })->join('warehouses', 'warehouses.id', '=', 'policies.warehouse_id')->whereIn('warehouses.branch_id', $branchIds)->where('policies.is_active', true)->whereColumn('balances.available', '<', 'policies.min_quantity')->count();
        if ($lowStock > 0) $items[] = ['severity' => 'danger', 'title' => 'สินค้าต่ำกว่า Min', 'count' => $lowStock, 'href' => $this->drillDownUrl('wms.stock.index', $branchFilter)];
        $pending = DB::table('finance_settlements as settlements')->join('finance_bank_accounts as accounts', 'accounts.id', '=', 'settlements.bank_account_id')->join('warehouses', 'warehouses.id', '=', 'accounts.warehouse_id')->whereIn('warehouses.branch_id', $branchIds)->whereNull('settlements.deleted_at')->whereIn('settlements.status', ['DRAFT'])->count();
        if ($pending > 0) $items[] = ['severity' => 'warning', 'title' => 'เอกสารการเงินรอดำเนินการ', 'count' => $pending, 'href' => $this->drillDownUrl('finance.settlements.index', $branchFilter)];
        return $items;
    }

    private function decisions(array $branchIds, mixed $branchFilter): array
    {
        $items = [];
        $stockout = DB::table('wms_stock_balances as balances')->join('warehouses', 'warehouses.id', '=', 'balances.warehouse_id')->whereIn('warehouses.branch_id', $branchIds)->where('balances.available', '<=', 0)->count();
        if ($stockout > 0) $items[] = ['severity' => 'warning', 'title' => 'มีสินค้าที่พร้อมใช้เป็นศูนย์', 'detail' => $stockout.' รายการ ควรพิจารณาเติมสินค้า', 'href' => $this->drillDownUrl('wms.stock.index', $branchFilter)];
        return $items;
    }

    private function drillDownUrl(string $routeName, mixed $branchFilter): string
    {
        return route($routeName, $branchFilter !== null && $branchFilter !== 'all'
            ? ['branch_id' => $branchFilter]
            : []);
    }
}
