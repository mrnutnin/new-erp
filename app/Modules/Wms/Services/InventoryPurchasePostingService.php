<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Support\InventoryPurchasePostingContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closed-by-default transaction boundary for the future inventory Purchase
 * Invoice flow. Preflight is safe to call; post remains gated until the
 * source event, linkage and reconciliation contracts are wired together.
 */
final class InventoryPurchasePostingService
{
    public function __construct(private readonly InventoryPurchasePostingContract $contract) {}

    public function preflight(PurchaseDocument $document, ?Account $purchaseAp = null, bool $featureEnabled = false): array
    {
        try {
            // Contract validation walks every purchase line and its Item/UOM/GL
            // relations. Load them once so a large invoice cannot trigger an
            // N+1 query chain during the future posting transaction.
            $needsLineRelations = ! $document->relationLoaded('lines') || $document->lines->contains(
                fn ($line): bool => ! $line->relationLoaded('item')
                    || ! $line->relationLoaded('uom')
                    || ($line->item && (! $line->item->relationLoaded('inventoryAccount')
                        || ($line->item->base_uom_id > 0 && (! $line->item->relationLoaded('baseUom')
                            || ($line->item->baseUom && ! $line->item->baseUom->relationLoaded('fromConversions'))))))
                    || ($line->item && $line->item->base_uom_id > 0 && $line->uom_id !== $line->item->base_uom_id
                        && $line->uom && ! $line->uom->relationLoaded('fromConversions'))
                    || (! $line->item && ! $line->relationLoaded('account')),
            );
            if ($needsLineRelations) {
                // `lines` can already be partially eager-loaded by the
                // three-way-match preview.  `loadMissing()` then retains
                // those partial nested relations, which makes a valid item
                // look unavailable to the posting contract. Reload this
                // small, bounded graph so validation is deterministic.
                $document->load([
                    'lines.item.inventoryAccount',
                    'lines.item.baseUom.fromConversions',
                    'lines.account',
                    'lines.uom.fromConversions',
                ]);
            }
            $plan = $this->contract->atomicPlan($document);
            $payload = $purchaseAp ? $this->contract->payload($document, $purchaseAp) : null;
        } catch (ValidationException $exception) {
            return [
                'ready' => false,
                'creates_journal' => false,
                'blockers' => ['source_or_account_validation'],
                'errors' => $exception->errors(),
            ];
        }

        $blockers = $featureEnabled ? [] : [
            'inventory_purchase_event_wiring',
            'atomic_journal_movement_allocation_linkage',
            'reconciliation_zero_gate',
        ];

        return [
            'ready' => $featureEnabled,
            'creates_journal' => $featureEnabled,
            'feature_enabled' => $featureEnabled,
            'blockers' => $blockers,
            'plan' => $plan,
            'payload' => $payload,
        ];
    }

    public function assertPostGate(array $preflight): void
    {
        if (($preflight['ready'] ?? false) !== true || ($preflight['creates_journal'] ?? true) !== false) {
            throw ValidationException::withMessages([
                'posting' => 'ยังไม่เปิด Inventory Purchase posting: ต้องผ่าน source event, atomic linkage และ reconciliation gate ก่อน',
            ]);
        }
    }

    /**
     * Transaction skeleton only. The gate intentionally fails before any
     * Journal, Movement, allocation or layer write is reachable.
     */
    public function post(PurchaseDocument $document, Warehouse $warehouse, User $actor, ?Account $purchaseAp = null): never
    {
        DB::transaction(function () use ($document, $purchaseAp): never {
            $preflight = $this->preflight($document, $purchaseAp);
            $this->assertPostGate($preflight);

            throw ValidationException::withMessages(['posting' => 'Inventory Purchase posting implementationยังไม่เปิดใช้งาน']);
        }, 3);
    }
}
