<?php

namespace App\Modules\Wms\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Wms\Support\InventoryPurchasePostingContract;

/**
 * Read-only bridge for the purchase posting event boundary.
 *
 * PurchaseDocumentPostingService keeps its existing expense event by default.
 * Inventory is only a candidate when the caller explicitly supplies every
 * readiness flag; the downstream posting service remains closed-by-default.
 */
final class PurchaseInventoryEventAdapter
{
    public function __construct(private readonly InventoryPurchasePostingService $inventoryPosting) {}

    public function preflight(
        PurchaseDocument $document,
        ?Account $purchaseAp = null,
        bool $inventoryMode = false,
        bool $mappingReady = false,
        bool $receiptReady = false,
    ): array {
        $expenseEvent = 'supplier_invoice.expense';
        $inventoryEvent = InventoryPurchasePostingContract::EVENT_CODE;
        $explicitlyReady = $inventoryMode && $mappingReady && $receiptReady;

        if (! $explicitlyReady) {
            return [
                'accepted' => false,
                'event_code' => $expenseEvent,
                'candidate_event_code' => $inventoryEvent,
                'source_identity' => $this->sourceIdentity($document, $expenseEvent),
                'blockers' => $this->blockers($inventoryMode, $mappingReady, $receiptReady),
            ];
        }

        $preflight = $this->inventoryPosting->preflight($document, $purchaseAp);
        $payload = $preflight['payload'] ?? null;

        return [
            'accepted' => false,
            'event_code' => $inventoryEvent,
            'candidate_event_code' => $inventoryEvent,
            'source_identity' => $this->sourceIdentity($document, $inventoryEvent),
            'payload_hash' => $payload ? hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null,
            'preflight' => $preflight,
            'blockers' => $preflight['blockers'] ?? ['inventory_purchase_posting_gate'],
        ];
    }

    private function sourceIdentity(PurchaseDocument $document, string $event): array
    {
        return [
            'source_type' => 'PURCHASING',
            'source_id' => (string) $document->id,
            'source_reference' => (string) $document->document_number,
            'event_code' => $event,
        ];
    }

    private function blockers(bool $inventoryMode, bool $mappingReady, bool $receiptReady): array
    {
        return array_values(array_filter([
            $inventoryMode ? null : 'explicit_inventory_mode_required',
            $mappingReady ? null : 'inventory_account_mapping_required',
            $receiptReady ? null : 'receipt_readiness_required',
        ]));
    }
}
