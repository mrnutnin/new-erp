<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PriceListItem;
use App\Modules\Pos\Support\PriceListSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class PriceListResolver
{
    /**
     * Resolve one effective price without loading the entire price catalog.
     * Exact customer-group lists win, then explicit priority, then newest id.
     */
    public function resolve(int $branchId, int $itemId, ?int $uomId, ?string $customerGroupCode, CarbonInterface $onDate, string $quantity = '0', string $currency = 'THB'): ?array
    {
        $date = $onDate->toDateString();
        $group = $customerGroupCode !== null && trim($customerGroupCode) !== '' ? trim($customerGroupCode) : null;

        $line = PriceListItem::query()
            ->join('pos_price_lists', 'pos_price_lists.id', '=', 'pos_price_list_items.price_list_id')
            ->where('pos_price_list_items.item_id', $itemId)
            ->where('pos_price_list_items.is_active', true)
            ->whereNull('pos_price_list_items.deleted_at')
            ->where('pos_price_lists.is_active', true)
            ->whereNull('pos_price_lists.deleted_at')
            ->where('pos_price_lists.branch_id', $branchId)
            ->where('pos_price_lists.currency', $currency)
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('pos_price_lists.effective_from')->orWhereDate('pos_price_lists.effective_from', '<=', $date);
            })
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('pos_price_lists.effective_to')->orWhereDate('pos_price_lists.effective_to', '>=', $date);
            })
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('pos_price_list_items.effective_from')->orWhereDate('pos_price_list_items.effective_from', '<=', $date);
            })
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('pos_price_list_items.effective_to')->orWhereDate('pos_price_list_items.effective_to', '>=', $date);
            })
            ->when($uomId !== null, fn (Builder $query) => $query->where(function (Builder $query) use ($uomId) {
                $query->where('pos_price_list_items.uom_id', $uomId)->orWhereNull('pos_price_list_items.uom_id');
            }))
            ->when($uomId === null, fn (Builder $query) => $query->whereNull('pos_price_list_items.uom_id'))
            ->where('pos_price_list_items.minimum_quantity', '<=', $quantity)
            ->where(function (Builder $query) use ($group) {
                $query->whereNull('pos_price_lists.customer_group_code');
                if ($group !== null) {
                    $query->orWhere('pos_price_lists.customer_group_code', $group);
                }
            })
            ->select('pos_price_list_items.*')
            ->selectRaw('pos_price_lists.id as resolved_price_list_id')
            ->selectRaw('pos_price_lists.code as resolved_price_list_code')
            ->selectRaw('pos_price_lists.currency as resolved_currency')
            ->selectRaw('pos_price_lists.customer_group_code as resolved_customer_group_code')
            ->orderByRaw('CASE WHEN pos_price_lists.customer_group_code IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN pos_price_list_items.uom_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('pos_price_lists.priority')
            ->orderByDesc('pos_price_list_items.minimum_quantity')
            ->orderByDesc('pos_price_lists.id')
            ->first();

        if (! $line) {
            return null;
        }

        return PriceListSnapshot::fromSelection([
            'id' => $line->resolved_price_list_id,
            'code' => $line->resolved_price_list_code,
            'currency' => $line->resolved_currency,
            'branch_id' => $branchId,
            'customer_group_code' => $line->resolved_customer_group_code,
        ], $line->toArray(), $onDate);
    }
}
