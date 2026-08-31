<?php

namespace App\Modules\Pos\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Immutable price evidence captured on a sales line.
 *
 * The sales document must store this array when a price is selected. Future
 * edits to a price list must never change an issued document's amount.
 */
final class PriceListSnapshot
{
    public static function discountAmount(array $snapshot, string $quantity): string
    {
        return BigDecimal::of($quantity)
            ->multipliedBy((string) $snapshot['unit_price'])
            ->multipliedBy((string) ($snapshot['discount_percent'] ?? '0'))
            ->dividedBy(100, 2, RoundingMode::HALF_UP)
            ->__toString();
    }

    public static function fromSelection(array $priceList, array $priceLine, DateTimeInterface $effectiveOn, ?DateTimeInterface $capturedAt = null): array
    {
        foreach (['id', 'code', 'currency'] as $key) {
            if (! array_key_exists($key, $priceList)) {
                throw new InvalidArgumentException("Price list snapshot missing {$key}");
            }
        }
        foreach (['id', 'item_id', 'unit_price'] as $key) {
            if (! array_key_exists($key, $priceLine)) {
                throw new InvalidArgumentException("Price line snapshot missing {$key}");
            }
        }

        return [
            'price_list_id' => (int) $priceList['id'],
            'price_list_code' => (string) $priceList['code'],
            'price_list_item_id' => (int) $priceLine['id'],
            'item_id' => (int) $priceLine['item_id'],
            'uom_id' => isset($priceLine['uom_id']) ? (int) $priceLine['uom_id'] : null,
            'customer_group_code' => $priceList['customer_group_code'] ?? null,
            'branch_id' => isset($priceList['branch_id']) ? (int) $priceList['branch_id'] : null,
            'currency' => (string) $priceList['currency'],
            'unit_price' => (string) $priceLine['unit_price'],
            'discount_percent' => (string) ($priceLine['discount_percent'] ?? '0.0000'),
            'effective_on' => $effectiveOn->format('Y-m-d'),
            'captured_at' => ($capturedAt ?? now())->format(DATE_ATOM),
        ];
    }
}
