<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Support\PriceListSnapshot;
use App\Modules\Pos\Support\PromotionSnapshot;
use Carbon\CarbonInterface;

/** Resolves the Price List term for direct invoice entry; promotions are selected only in Sales Intake. */
final class PricingResolver
{
    public function __construct(
        private readonly PriceListResolver $priceLists,
    ) {}

    public function resolve(int $branchId, int $itemId, ?int $uomId, ?string $customerGroupCode, CarbonInterface $onDate, string $quantity = '0', string $currency = 'THB'): ?array
    {
        $priceList = $this->priceLists->resolve($branchId, $itemId, $uomId, $customerGroupCode, $onDate, $quantity, $currency);
        if (! $priceList) {
            return null;
        }

        return [
            'price_snapshot' => $priceList,
            'unit_price' => (string) $priceList['unit_price'],
            'discount_amount' => PriceListSnapshot::discountAmount($priceList, $quantity),
        ];
    }

    /** Rehydrates an approved commercial term; client-submitted snapshots are never trusted. */
    public function fromSnapshot(array $snapshot, string $quantity): ?array
    {
        if (($snapshot['source'] ?? null) === 'PROMOTION' && isset($snapshot['unit_price'])) {
            return [
                'price_snapshot' => $snapshot,
                'unit_price' => (string) ($snapshot['base_unit_price'] ?? $snapshot['unit_price']),
                'discount_amount' => PromotionSnapshot::discountAmount($snapshot, $quantity),
            ];
        }
        if (isset($snapshot['price_list_id'], $snapshot['unit_price'])) {
            return [
                'price_snapshot' => $snapshot,
                'unit_price' => (string) $snapshot['unit_price'],
                'discount_amount' => PriceListSnapshot::discountAmount($snapshot, $quantity),
            ];
        }

        return null;
    }
}
