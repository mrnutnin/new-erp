<?php

namespace App\Modules\Wms\Support;

final class InventoryPostingPreflight
{
    public static function evaluate(array $input): array
    {
        $checks = [
            'movement_posted' => ($input['movement_status'] ?? null) === 'POSTED',
            'inventory_account_ready' => (bool) ($input['inventory_account_ready'] ?? false),
            'cogs_account_ready' => ($input['direction'] ?? null) !== 'OUT' || (bool) ($input['cogs_account_ready'] ?? false),
            'allocation_exists' => (int) ($input['allocation_count'] ?? 0) > 0,
            'no_pending_cost' => (int) ($input['pending_count'] ?? 0) === 0,
            'all_allocations_linked' => (int) ($input['unlinked_count'] ?? 0) === 0,
            'line_proof_ready' => (bool) ($input['line_proof_ready'] ?? false),
        ];

        // Optional until callers can provide source identity. Once provided,
        // every posting candidate must remain traceable to its source document.
        if (array_key_exists('source_ready', $input)) {
            $checks['source_ready'] = (bool) $input['source_ready'];
        }

        return ['ready' => ! in_array(false, $checks, true), 'checks' => $checks, 'blockers' => array_keys(array_filter($checks, fn (bool $passed): bool => ! $passed))];
    }
}
