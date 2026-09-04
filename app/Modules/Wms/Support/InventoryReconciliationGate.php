<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;

/**
 * Read-only release gate for Inventory reconciliation.
 * Posting callers must refuse to continue when any proof is incomplete.
 */
final class InventoryReconciliationGate
{
    public static function evaluate(array $totals): array
    {
        $checks = [
            'allocation_vs_gl_zero' => self::isZero($totals['allocation_vs_gl_difference'] ?? null),
            'balance_vs_allocation_zero' => self::isZero($totals['balance_vs_allocation_difference'] ?? null),
            'no_unlinked_allocations' => (int) ($totals['unlinked_allocations'] ?? 0) === 0,
            'no_pending_allocations' => (int) ($totals['pending_allocations'] ?? 0) === 0,
            'no_unlinked_journal_lines' => (int) ($totals['line_unlinked'] ?? 0) === 0,
            'no_mismatched_journal_lines' => (int) ($totals['line_mismatched'] ?? 0) === 0,
            'rounding_difference_zero' => self::isZero($totals['rounding_difference'] ?? '0'),
            // Legacy review/quarantine rows are never silently ignored. An
            // isolated fixture must explicitly prove it has none.
            'no_unresolved_legacy_review' => (int) ($totals['unresolved_legacy_review'] ?? 0) === 0,
        ];

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'blockers' => array_keys(array_filter($checks, fn (bool $passed): bool => ! $passed)),
        ];
    }

    private static function isZero(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return BigDecimal::of((string) $value)->isZero();
    }
}
