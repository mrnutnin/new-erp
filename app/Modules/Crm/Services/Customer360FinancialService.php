<?php

namespace App\Modules\Crm\Services;

use App\Models\PartyRole;
use Illuminate\Support\Facades\DB;

final class Customer360FinancialService
{
    public function summary(int $partyId, int $branchId): array
    {
        $from = today()->subMonths(12)->toDateString();
        $sales = DB::table('pos_physical_sales')->where(['party_id' => $partyId, 'branch_id' => $branchId, 'status' => 'POSTED'])
            ->whereDate('posting_date', '>=', $from)->sum('total_amount');
        $returns = DB::table('pos_sales_returns as returns')->join('pos_physical_sales as sales', 'sales.id', '=', 'returns.physical_sale_id')
            ->where('sales.party_id', $partyId)->where('returns.branch_id', $branchId)->where('returns.status', 'POSTED')
            ->whereDate('returns.posting_date', '>=', $from)->sum('returns.total_amount');
        $role = PartyRole::query()->where(['party_id' => $partyId, 'role' => 'CUSTOMER', 'is_active' => true])->first();
        $creditLimit = (float) ($role?->credit_limit ?? 0);
        $outstanding = $this->outstanding($partyId);
        $usagePercent = $creditLimit > 0 ? round(($outstanding / $creditLimit) * 100, 1) : null;
        $creditStatus = $creditLimit <= 0 ? 'UNLIMITED' : ($outstanding > $creditLimit ? 'OVER_LIMIT' : (($usagePercent ?? 0) >= 80 ? 'NEAR_LIMIT' : 'AVAILABLE'));

        return [
            'net_sales_12m' => max(0, (float) $sales - (float) $returns),
            'sales_returns_12m' => (float) $returns,
            'outstanding' => $outstanding,
            'credit_limit' => $creditLimit,
            'available_credit' => $creditLimit > 0 ? max(0, $creditLimit - $outstanding) : null,
            'credit_usage_percent' => $usagePercent,
            'credit_status' => $creditStatus,
        ];
    }

    private function outstanding(int $partyId): float
    {
        $asOf = today()->toDateString();
        $allocationRows = DB::table('finance_allocations')->selectRaw('debit_open_item_id AS open_item_id, amount')
            ->where('allocation_date', '<=', $asOf)->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))
            ->unionAll(DB::table('finance_allocations')->selectRaw('credit_open_item_id AS open_item_id, amount')
                ->where('allocation_date', '<=', $asOf)->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf)));
        $allocations = DB::query()->fromSub($allocationRows, 'allocation_rows')->selectRaw('open_item_id, SUM(amount) allocated_amount')->groupBy('open_item_id');
        $applications = DB::table('finance_advance_deposit_applications')->selectRaw('open_item_id, SUM(amount) applied_amount')
            ->where('application_date', '<=', $asOf)->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))->groupBy('open_item_id');
        $remaining = "(oi.original_amount - COALESCE(a.allocated_amount,0) - COALESCE(aa.applied_amount,0)) * CASE WHEN oi.balance_side = 'DEBIT' THEN 1 ELSE -1 END";
        $amount = DB::table('finance_open_items as oi')->leftJoinSub($allocations, 'a', 'a.open_item_id', '=', 'oi.id')
            ->leftJoinSub($applications, 'aa', 'aa.open_item_id', '=', 'oi.id')
            ->where(['oi.ledger_type' => 'AR', 'oi.party_type' => 'CUSTOMER', 'oi.party_id' => $partyId])
            ->where('oi.posting_date', '<=', $asOf)->sum(DB::raw($remaining));

        return max(0, (float) $amount);
    }
}
