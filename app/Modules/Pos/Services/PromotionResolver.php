<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\Promotion;
use App\Modules\Pos\Models\PromotionItem;
use App\Modules\Pos\Support\PromotionSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class PromotionResolver
{
    /** Resolve the highest-priority eligible line promotion, or one explicit selection. */
    public function resolve(int $itemId, ?int $uomId, ?string $customerGroupCode, CarbonInterface $onDate, string $quantity = '0', string $currency = 'THB', ?int $promotionId = null): ?array
    {
        $rule = $this->lineRules($itemId, $uomId, $customerGroupCode, $onDate, $quantity, $currency)
            ->when($promotionId, fn (Builder $query) => $query->where('pos_promotions.id', $promotionId))
            ->first();

        return $rule ? $this->lineSnapshot($rule, $onDate) : null;
    }

    /** @return array<int, array> */
    public function resolveAll(int $itemId, ?int $uomId, ?string $customerGroupCode, CarbonInterface $onDate, string $quantity = '0', string $currency = 'THB'): array
    {
        return $this->lineRules($itemId, $uomId, $customerGroupCode, $onDate, $quantity, $currency)
            ->get()
            ->unique('resolved_promotion_id')
            ->map(fn (PromotionItem $rule) => $this->lineSnapshot($rule, $onDate))
            ->values()
            ->all();
    }

    private function lineRules(int $itemId, ?int $uomId, ?string $customerGroupCode, CarbonInterface $onDate, string $quantity, string $currency): Builder
    {
        $date = $onDate->toDateString();
        $group = $customerGroupCode !== null && trim($customerGroupCode) !== '' ? trim($customerGroupCode) : null;

        return PromotionItem::query()
            ->join('pos_promotions', 'pos_promotions.id', '=', 'pos_promotion_items.promotion_id')
            ->where('pos_promotion_items.item_id', $itemId)
            ->where('pos_promotion_items.is_active', true)
            ->whereNull('pos_promotion_items.deleted_at')
            ->where('pos_promotions.is_active', true)
            ->where('pos_promotions.application_scope', 'LINE')
            ->whereNull('pos_promotions.deleted_at')
            ->where('pos_promotions.currency', $currency)
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('pos_promotions.effective_from')->orWhereDate('pos_promotions.effective_from', '<=', $date);
            })
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('pos_promotions.effective_to')->orWhereDate('pos_promotions.effective_to', '>=', $date);
            })
            ->when($uomId !== null, fn (Builder $query) => $query->where(function (Builder $query) use ($uomId) {
                $query->where('pos_promotion_items.uom_id', $uomId)->orWhereNull('pos_promotion_items.uom_id');
            }))
            ->when($uomId === null, fn (Builder $query) => $query->whereNull('pos_promotion_items.uom_id'))
            ->where('pos_promotion_items.minimum_quantity', '<=', $quantity)
            ->where(function (Builder $query) use ($group) {
                $query->whereNull('pos_promotions.customer_group_code');
                if ($group !== null) {
                    $query->orWhere('pos_promotions.customer_group_code', $group);
                }
            })
            ->select('pos_promotion_items.*')
            ->selectRaw('pos_promotions.id as resolved_promotion_id')
            ->selectRaw('pos_promotions.code as resolved_promotion_code')
            ->selectRaw('pos_promotions.currency as resolved_currency')
            ->selectRaw('pos_promotions.customer_group_code as resolved_customer_group_code')
            ->selectRaw('pos_promotions.stackable as resolved_stackable')
            ->orderByDesc('pos_promotions.priority')
            ->orderByDesc('pos_promotions.id')
            ->orderByRaw('pos_promotion_items.uom_id IS NOT NULL DESC')
            ->orderByDesc('pos_promotion_items.minimum_quantity');
    }

    private function lineSnapshot(PromotionItem $rule, CarbonInterface $onDate): array
    {
        return PromotionSnapshot::fromSelection([
            'id' => $rule->resolved_promotion_id,
            'code' => $rule->resolved_promotion_code,
            'currency' => $rule->resolved_currency,
            'customer_group_code' => $rule->resolved_customer_group_code,
            'stackable' => $rule->resolved_stackable,
        ], $rule->toArray(), $onDate);
    }

    /** Resolve the highest-priority document promotion, or one explicit selection. */
    public function resolveDocument(?string $customerGroupCode, CarbonInterface $onDate, string $currency = 'THB', ?int $promotionId = null): ?array
    {
        $promotion = $this->documentPromotions($customerGroupCode, $onDate, $currency)
            ->when($promotionId, fn (Builder $query) => $query->whereKey($promotionId))
            ->first();

        return $promotion ? PromotionSnapshot::documentFromSelection($promotion->toArray(), $onDate) : null;
    }

    /** @return array<int, array> */
    public function resolveDocumentAll(?string $customerGroupCode, CarbonInterface $onDate, string $currency = 'THB'): array
    {
        return $this->documentPromotions($customerGroupCode, $onDate, $currency)
            ->get()
            ->map(fn (Promotion $promotion) => PromotionSnapshot::documentFromSelection($promotion->toArray(), $onDate))
            ->all();
    }

    private function documentPromotions(?string $customerGroupCode, CarbonInterface $onDate, string $currency): Builder
    {
        $date = $onDate->toDateString();
        $group = $customerGroupCode !== null && trim($customerGroupCode) !== '' ? trim($customerGroupCode) : null;

        return Promotion::query()
            ->where('is_active', true)
            ->where('application_scope', 'DOCUMENT')
            ->where('currency', $currency)
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date);
            })
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->where(function (Builder $query) use ($group) {
                $query->whereNull('customer_group_code');
                if ($group !== null) {
                    $query->orWhere('customer_group_code', $group);
                }
            })
            ->orderByDesc('priority')
            ->orderByDesc('id');
    }
}
