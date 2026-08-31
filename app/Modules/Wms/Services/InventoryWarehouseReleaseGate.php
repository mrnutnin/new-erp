<?php

namespace App\Modules\Wms\Services;

use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/**
 * Warehouse-scoped release gate for Inventory -> GL posting.
 *
 * This gate is deliberately read-only: it never changes the global feature
 * flag. A caller must explicitly pass the selected warehouse through this
 * gate before allowing a posting request to continue.
 */
final class InventoryWarehouseReleaseGate
{
    public function __construct(private readonly InventoryPostingPreflightReader $preflight) {}

    /** @return array<string, mixed> */
    public function inspect(Warehouse|int $warehouse): array
    {
        $warehouseId = $warehouse instanceof Warehouse ? (int) $warehouse->id : $warehouse;
        if ($warehouseId < 1) {
            return [
                'warehouse_id' => $warehouseId,
                'ready' => false,
                'posting_enabled' => (bool) config('erp.inventory.purchase_posting_enabled', false),
                'blockers' => ['warehouse_scope_required'],
            ];
        }

        $summary = $this->preflight->summary($warehouseId);
        $blockers = [];

        foreach ([
            'pending' => 'pending_cost_allocations',
            'unlinked' => 'unlinked_allocations',
            'missingInventory' => 'missing_inventory_mapping',
            'missingCogs' => 'missing_cogs_mapping',
            'missingSource' => 'missing_source_identity',
            'lineUnlinked' => 'unlinked_journal_line_proof',
            'lineMismatched' => 'mismatched_journal_line_proof',
            'lineProofMissing' => 'journal_line_proof_unavailable',
            'unresolvedLegacyReview' => 'unresolved_legacy_review',
        ] as $field => $blocker) {
            if ((int) ($summary[$field] ?? 0) > 0) {
                $blockers[] = $blocker;
            }
        }

        if (($summary['reconciliation_ready'] ?? false) !== true) {
            $blockers = [...$blockers, ...($summary['reconciliation_blockers'] ?? ['reconciliation_not_ready'])];
        }

        $blockers = array_values(array_unique($blockers));

        return [
            ...$summary,
            'warehouse_id' => $warehouseId,
            'ready' => $blockers === [],
            'release_scope' => 'WAREHOUSE_ONLY',
            'blockers' => $blockers,
        ];
    }

    /** @return array<string, mixed> */
    public function assertReady(Warehouse|int $warehouse): array
    {
        $result = $this->inspect($warehouse);
        if (($result['ready'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'warehouse_release' => 'Warehouse ยังไม่ผ่าน Inventory → GL release gate: '.implode(', ', $result['blockers'] ?? ['ตรวจสอบ readiness']),
            ]);
        }

        return $result;
    }

    /**
     * Feature activation remains an explicit application concern. This
     * method only proves the selected warehouse is eligible and never writes
     * config or enables posting globally.
     */
    public function assertPostingAllowed(Warehouse|int $warehouse): array
    {
        $result = $this->assertReady($warehouse);
        if (($result['posting_enabled'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'posting' => 'Inventory → GL posting ยังปิดอยู่ ต้องเปิด feature flag โดยผู้ดูแลก่อน',
            ]);
        }

        return $result;
    }
}
