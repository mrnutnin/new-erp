<?php

namespace App\Modules\Pos\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use InvalidArgumentException;

/** Immutable promotion evidence captured on one sales line. */
final class PromotionSnapshot
{
    public static function documentDiscountAmount(array $snapshot, string $documentAmount): string
    {
        $amount = BigDecimal::of($documentAmount);

        if (($snapshot['bill_discount_amount'] ?? null) !== null) {
            return BigDecimal::of((string) $snapshot['bill_discount_amount'])
                ->isGreaterThan($amount) ? $amount->toScale(2, RoundingMode::HALF_UP)->__toString() : BigDecimal::of((string) $snapshot['bill_discount_amount'])->toScale(2, RoundingMode::HALF_UP)->__toString();
        }
        if (($snapshot['bill_discount_percent'] ?? null) !== null) {
            return $amount
                ->multipliedBy((string) $snapshot['bill_discount_percent'])
                ->dividedBy(100, 2, RoundingMode::HALF_UP)
                ->__toString();
        }

        return '0.00';
    }

    public static function discountAmount(array $snapshot, string $quantity): string
    {
        if (($snapshot['base_unit_price'] ?? null) === null || ($snapshot['discount_percent'] ?? null) === null) {
            return '0.00';
        }

        return BigDecimal::of($quantity)
            ->multipliedBy((string) $snapshot['base_unit_price'])
            ->multipliedBy((string) $snapshot['discount_percent'])
            ->dividedBy(100, 2, RoundingMode::HALF_UP)
            ->__toString();
    }

    public static function fromSelection(array $promotion, array $rule, DateTimeInterface $effectiveOn, ?DateTimeInterface $capturedAt = null): array
    {
        foreach (['id', 'code', 'currency'] as $key) {
            if (! array_key_exists($key, $promotion)) {
                throw new InvalidArgumentException("Promotion snapshot missing {$key}");
            }
        }
        foreach (['id', 'item_id'] as $key) {
            if (! array_key_exists($key, $rule)) {
                throw new InvalidArgumentException("Promotion rule snapshot missing {$key}");
            }
        }

        $baseUnitPrice = $rule['base_unit_price'] ?? null;
        $discountPercent = $rule['discount_percent'] ?? null;
        $hasUnitPrice = ($rule['unit_price'] ?? null) !== null;
        $hasPercentRule = $baseUnitPrice !== null && $discountPercent !== null;
        if ($hasUnitPrice === $hasPercentRule
            || ($hasUnitPrice && ($baseUnitPrice !== null || $discountPercent !== null))) {
            throw new InvalidArgumentException('Promotion rule must have exactly one price rule.');
        }

        $unitPrice = $hasUnitPrice
            ? (string) $rule['unit_price']
            : BigDecimal::of((string) $baseUnitPrice)
                ->multipliedBy(BigDecimal::of(100)->minus((string) $discountPercent))
                ->dividedBy(100, 4, RoundingMode::HALF_UP)
                ->__toString();

        return [
            'source' => 'PROMOTION',
            'application_scope' => 'LINE',
            'promotion_id' => (int) $promotion['id'],
            'promotion_code' => (string) $promotion['code'],
            'stackable' => (bool) ($promotion['stackable'] ?? false),
            'promotion_item_id' => (int) $rule['id'],
            'item_id' => (int) $rule['item_id'],
            'uom_id' => isset($rule['uom_id']) ? (int) $rule['uom_id'] : null,
            'customer_group_code' => $promotion['customer_group_code'] ?? null,
            'currency' => (string) $promotion['currency'],
            'unit_price' => $unitPrice,
            'base_unit_price' => $hasPercentRule ? (string) $baseUnitPrice : null,
            'discount_percent' => $hasPercentRule ? (string) $discountPercent : null,
            'effective_on' => $effectiveOn->format('Y-m-d'),
            'captured_at' => ($capturedAt ?? now())->format(DATE_ATOM),
        ];
    }

    /** Immutable promotion evidence captured at document level. */
    public static function documentFromSelection(array $promotion, DateTimeInterface $effectiveOn, ?DateTimeInterface $capturedAt = null): array
    {
        foreach (['id', 'code', 'currency'] as $key) {
            if (! array_key_exists($key, $promotion)) {
                throw new InvalidArgumentException("Promotion snapshot missing {$key}");
            }
        }
        if (($promotion['application_scope'] ?? null) !== 'DOCUMENT') {
            throw new InvalidArgumentException('Document promotion must have DOCUMENT scope.');
        }

        $hasAmount = ($promotion['bill_discount_amount'] ?? null) !== null;
        $hasPercent = ($promotion['bill_discount_percent'] ?? null) !== null;
        if ($hasAmount === $hasPercent) {
            throw new InvalidArgumentException('Document promotion must have exactly one discount rule.');
        }

        return [
            'source' => 'PROMOTION',
            'application_scope' => 'DOCUMENT',
            'promotion_id' => (int) $promotion['id'],
            'promotion_code' => (string) $promotion['code'],
            'stackable' => (bool) ($promotion['stackable'] ?? false),
            'customer_group_code' => $promotion['customer_group_code'] ?? null,
            'currency' => (string) $promotion['currency'],
            'bill_discount_amount' => $hasAmount ? (string) $promotion['bill_discount_amount'] : null,
            'bill_discount_percent' => $hasPercent ? (string) $promotion['bill_discount_percent'] : null,
            'effective_on' => $effectiveOn->format('Y-m-d'),
            'captured_at' => ($capturedAt ?? now())->format(DATE_ATOM),
        ];
    }
}
