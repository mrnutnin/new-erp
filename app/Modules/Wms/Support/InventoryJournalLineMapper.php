<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Pure, deterministic mapping from one inventory allocation to its Journal
 * line. Position/order is never used as an identity.
 */
final class InventoryJournalLineMapper
{
    public static function map(mixed $purchaseLine, mixed $movement, mixed $allocation, iterable $journalLines, array $context = []): array
    {
        $accountId = (int) self::value($purchaseLine, 'account_id');
        $lineToken = self::value($purchaseLine, 'id') ? InventoryPurchasePostingContract::lineToken($purchaseLine) : null;
        $itemId = (int) self::value($movement, 'item_id');
        $allocationValue = BigDecimal::of((string) self::value($allocation, 'value'))->toScale(2, RoundingMode::HALF_UP);
        if ($accountId < 1 || $itemId < 1 || $allocationValue->isZero()
            || ! self::value($movement, 'id') || ! self::value($movement, 'warehouse_id') || ! self::value($movement, 'business_date')
            || (int) self::value($allocation, 'stock_movement_id') !== (int) self::value($movement, 'id')
            || (int) self::value($allocation, 'warehouse_id') !== (int) self::value($movement, 'warehouse_id')
            || (int) self::value($allocation, 'item_id') !== $itemId
            || (int) self::value($allocation, 'uom_id') !== (int) self::value($movement, 'uom_id')
            || (int) self::value($allocation, 'revision') < 0) {
            throw ValidationException::withMessages(['journal_line' => 'Inventory linkage ต้องมีบัญชีสินค้า Item และยอด allocation ที่ไม่เป็นศูนย์']);
        }
        if (isset($context['business_date']) && self::dateValue($movement, 'business_date') !== (string) $context['business_date']) {
            throw ValidationException::withMessages(['business_date' => 'Inventory Movement และ Journal business date ไม่ตรงกัน']);
        }
        if (isset($context['revision']) && (int) self::value($allocation, 'revision') !== (int) $context['revision']) {
            throw ValidationException::withMessages(['revision' => 'Cost allocation revision ไม่ตรงกับ transaction context']);
        }

        $direction = strtoupper((string) self::value($movement, 'direction'));
        $debit = $direction === 'OUT' ? BigDecimal::zero() : $allocationValue->abs();
        $credit = $direction === 'OUT' ? $allocationValue->abs() : BigDecimal::zero();
        $lines = $journalLines instanceof Collection ? $journalLines : collect($journalLines);
        $matches = $lines->filter(function ($line) use ($accountId, $itemId, $debit, $credit, $lineToken): bool {
            return (int) self::value($line, 'account_id') === $accountId
                && strtoupper((string) self::value($line, 'subledger_type')) === 'ITEM'
                && (string) self::value($line, 'subledger_id') === (string) $itemId
                && self::decimal(self::value($line, 'debit')) === self::decimal($debit)
                && self::decimal(self::value($line, 'credit')) === self::decimal($credit)
                && ($lineToken === null || str_contains((string) self::value($line, 'description'), $lineToken));
        })->values();
        if (isset($context['journal_entry_id'])) {
            $matches = $matches->filter(fn ($line): bool => (int) self::value($line, 'journal_entry_id') === (int) $context['journal_entry_id'])->values();
        }

        if ($matches->count() === 0) {
            throw ValidationException::withMessages(['journal_line' => 'ไม่พบ Journal line ที่ตรงกับบัญชี Item และยอด allocation']);
        }
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['journal_line' => 'พบ Journal line ที่ตรงกันหลายรายการ ไม่สามารถเลือกโดยเดาได้']);
        }

        $line = $matches->first();
        $lineId = self::value($line, 'id');
        if (! $lineId) {
            throw ValidationException::withMessages(['journal_line' => 'Journal line ที่ match ต้องมี ID']);
        }

        return [
            'journal_entry_line_id' => (int) $lineId,
            'allocation_id' => (int) self::value($allocation, 'id'),
            'revision' => (int) self::value($allocation, 'revision'),
            'identity_key' => hash('sha256', implode('|', [(string) self::value($allocation, 'id'), (string) $lineId, (string) self::value($allocation, 'revision')])),
        ];
    }

    private static function decimal(mixed $value): string
    {
        return BigDecimal::of((string) $value)->toScale(2, RoundingMode::HALF_UP)->__toString();
    }

    private static function value(mixed $value, string $key): mixed
    {
        if (is_array($value)) {
            return $value[$key] ?? null;
        }

        return is_object($value) ? ($value->{$key} ?? null) : null;
    }

    private static function dateValue(mixed $value, string $key): ?string
    {
        $date = self::value($value, $key);

        return $date instanceof DateTimeInterface ? $date->format('Y-m-d') : ($date === null ? null : (string) $date);
    }
}
