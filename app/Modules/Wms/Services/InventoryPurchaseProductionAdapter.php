<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostAllocationJournalLine;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\PurchaseLineMovementAdapter;
use App\Modules\Purchasing\Support\PurchaseThreeWayMatchGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Production boundary for the future Inventory Purchase flow.
 * It prepares every line deterministically but remains closed until Journal
 * posting can join the same outer transaction without nested ownership.
 */
final class InventoryPurchaseProductionAdapter
{
    public const CALLBACK_SEQUENCE = ['journal', 'movement_intent', 'movement_post', 'cost_allocation', 'journal_line_linkage', 'reconciliation'];

    public function __construct(
        private readonly InventoryPurchasePostingService $posting,
        private readonly AccountMappingService $mappings,
        private readonly JournalPostingService $journals,
        private readonly StockMovementService $movements,
        private readonly InventoryCostAllocationService $allocations,
        private readonly InventoryPurchaseAtomicWorkflow $workflow,
        private readonly InventoryReconciliationService $reconciliationService,
        private readonly ?PurchaseThreeWayMatchGate $matchGate = null,
    ) {}

    public function journalCallback(Warehouse $warehouse, ?User $actor = null): callable
    {
        return fn (array $payload): mixed => $this->journals->postWithinTransaction($payload, $warehouse, $actor);
    }

    public function resolveReceiptAllocationWithinTransaction(StockMovement $movement): CostAllocation
    {
        $allocations = CostAllocation::query()->where('stock_movement_id', $movement->id)
            ->where('idempotency_key', "movement:{$movement->id}:receipt")
            ->where('status', '!=', 'REVERSED')->lockForUpdate()->get();
        if ($allocations->count() !== 1) {
            throw ValidationException::withMessages(['allocation' => 'Receipt Movement ต้องมี Cost Allocation receipt exactly 1 รายการ']);
        }

        return $allocations->sole();
    }

    /** Default gate cannot be bypassed by a custom callback. */
    public function defaultReconciliationGate(Warehouse $warehouse, string $asOfDate): array
    {
        $totals = $this->reconciliationService->totals($asOfDate, (int) $warehouse->id);
        $pending = CostAllocation::query()->where('warehouse_id', $warehouse->id)
            ->where('business_date', '<=', $asOfDate)->where('cost_status', 'PENDING')->where('status', '!=', 'REVERSED')->count();
        $result = [...$totals, 'pending_allocations' => $pending];
        if (($result['status'] ?? 'ต้องตรวจสอบ') !== 'ตรงกัน' || $pending !== 0) {
            throw ValidationException::withMessages(['reconciliation' => 'Inventory reconciliation ต้องไม่มีผลต่าง, pending หรือ unlinked allocation']);
        }

        return $result;
    }

    /**
     * Full callback path, intentionally unreachable until preflight and the
     * explicit feature flag are both open. The caller owns no partial writes:
     * every Journal/Movement/allocation/linkage update shares this transaction.
     */
    public function postWithCallbacks(
        PurchaseDocument $document,
        Warehouse $warehouse,
        User $actor,
        ?Account $purchaseAp = null,
        bool $featureEnabled = false,
        ?callable $reconciliation = null,
        ?string $postingDate = null,
    ): PurchaseDocument {
        if (! $featureEnabled) {
            throw ValidationException::withMessages(['posting' => 'Inventory Purchase callback path ยังไม่เปิดใช้งาน']);
        }
        if ($postingDate !== null) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $postingDate) || $postingDate < $document->document_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['posting_date' => 'วันที่ Post ต้องเป็นรูปแบบ Y-m-d และไม่ก่อนวันที่เอกสาร']);
            }
            $document->posting_date = $postingDate;
        }
        $preflight = $this->preflight($document, $purchaseAp, $featureEnabled);
        if (($preflight['ready'] ?? false) !== true || ($preflight['production_ready'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'posting' => 'Inventory Purchase callback path ยังไม่ผ่าน feature/preflight/reconciliation gate',
            ]);
        }

        return DB::transaction(function () use ($document, $warehouse, $actor, $purchaseAp, $featureEnabled, $reconciliation, $postingDate): PurchaseDocument {
            $lockedDocument = PurchaseDocument::query()
                ->with(['lines.item.baseUom.fromConversions', 'lines.item.inventoryAccount', 'lines.account', 'lines.uom.fromConversions'])
                ->lockForUpdate()->findOrFail($document->id);
            if ((int) $lockedDocument->warehouse_id !== (int) $warehouse->id) {
                throw ValidationException::withMessages(['warehouse_id' => 'Purchase document ไม่อยู่ใน Warehouse scope เดียวกัน']);
            }
            if ($postingDate !== null) {
                if ($lockedDocument->posting_date !== null && $lockedDocument->posting_date->format('Y-m-d') !== $postingDate) {
                    throw ValidationException::withMessages(['posting_date' => 'เอกสารนี้ Post แล้วด้วยวันที่อื่น']);
                }
                $lockedDocument->posting_date = $postingDate;
            }
            if ((string) $lockedDocument->status === 'POSTED') {
                $this->verifyPostedRetry($lockedDocument, $lockedPreflight = $this->preflight($lockedDocument, $purchaseAp, $featureEnabled), $warehouse);

                return $lockedDocument;
            }
            if ((string) $lockedDocument->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'Inventory Purchase ต้องอยู่สถานะ Approved ก่อน Post']);
            }
            $lockedPreflight = $this->preflight($lockedDocument, $purchaseAp, $featureEnabled);
            if (($lockedPreflight['ready'] ?? false) !== true || ($lockedPreflight['production_ready'] ?? false) !== true) {
                throw ValidationException::withMessages(['posting' => 'Inventory Purchase preflight เปลี่ยนแปลงหรือยังไม่ผ่าน gate']);
            }

            // Verify the warehouse is already reconciled before introducing
            // this transaction's Journal/Movement delta.
            $this->defaultReconciliationGate($warehouse, $lockedDocument->document_date->format('Y-m-d'));
            $journal = $this->journals->postWithinTransaction($lockedPreflight['payload'], $warehouse, $actor);
            $journalLines = JournalEntryLine::query()->where('journal_entry_id', $journal->id)->lockForUpdate()->get();
            $sourceLines = $lockedDocument->lines->keyBy('id');

            foreach ($lockedPreflight['movement_intents'] as $intent) {
                $lineId = (int) ($intent['metadata']['purchase_line_id'] ?? 0);
                $line = $sourceLines->get($lineId);
                if (! $line) {
                    throw ValidationException::withMessages(['lines' => 'ไม่พบ Purchase line ของ Movement intent']);
                }
                $movement = $this->movements->recordIntent([...$intent, 'created_by' => $actor->id]);
                $movement = $this->movements->postWithinTransaction($movement);
                $allocation = $this->resolveReceiptAllocationWithinTransaction($movement);
                $this->workflow->linkAllocationJournalLineWithinTransaction(
                    $this->allocations, $line, $movement, $allocation, $journalLines,
                    ['business_date' => $movement->business_date?->format('Y-m-d'), 'revision' => $allocation->revision],
                );
            }

            $defaultReconciliation = $this->defaultReconciliationGate($warehouse, $journal->entry_date->format('Y-m-d'));
            if ($reconciliation && $reconciliation($lockedDocument, $journal, $defaultReconciliation) !== true) {
                throw ValidationException::withMessages(['reconciliation' => 'Inventory Purchase reconciliation ไม่เป็นศูนย์']);
            }

            $lockedDocument->forceFill([
                'status' => 'POSTED', 'journal_entry_id' => $journal->id,
                'posting_date' => $journal->entry_date, 'posted_by' => $actor->id, 'posted_at' => now(),
            ])->save();

            return $lockedDocument->fresh();
        }, 3);
    }

    public function preflight(PurchaseDocument $document, ?Account $purchaseAp = null, bool $featureEnabled = false): array
    {
        try {
            $apResolution = $purchaseAp ? null : $this->mappings->resolveForEvent('supplier_invoice.inventory', 'ACCOUNTS_PAYABLE');
            $purchaseAp ??= $apResolution['account'];
        } catch (ValidationException $exception) {
            return ['ready' => false, 'production_ready' => false, 'blockers' => ['purchase_ap_mapping'], 'errors' => $exception->errors()];
        }
        $preflight = $this->posting->preflight($document, $purchaseAp, $featureEnabled);
        if (! ($preflight['plan'] ?? null) || ! ($preflight['payload'] ?? null)) {
            return [...$preflight, 'production_ready' => false];
        }
        if ($apResolution) {
            $preflight['payload']['posting_metadata'] = [
                'contract_version' => 1,
                'event_code' => 'supplier_invoice.inventory',
                'accounts' => [$apResolution['provenance']],
            ];
        }

        $document->loadMissing([
            'lines.purchaseOrderLine.purchaseOrder.lines',
            'lines.receiptAllocations.goodsReceiptLine.goodsReceipt.lines',
        ]);
        $match = ($this->matchGate ?? new PurchaseThreeWayMatchGate)->preview($document);
        if ($match === null || ($match['ready'] ?? false) !== true) {
            return [
                ...$preflight,
                'production_ready' => false,
                'blockers' => ['three_way_match'],
                'errors' => ['lines' => $match['blockers'] ?? ['inventory_purchase_requires_po_gr_allocation']],
            ];
        }

        $intents = [];
        foreach ($document->lines as $line) {
            try {
                $intents[] = PurchaseLineMovementAdapter::map($document, (int) $line->id);
            } catch (ValidationException $exception) {
                return [
                    ...$preflight,
                    'production_ready' => false,
                    'blockers' => ['purchase_line_movement_contract'],
                    'errors' => $exception->errors(),
                ];
            }
        }

        $productionReady = $featureEnabled;

        return [
            ...$preflight,
            'ready' => $productionReady,
            'production_ready' => $productionReady,
            'feature_enabled' => $featureEnabled,
            'movement_intents' => $intents,
            'blockers' => array_values(array_unique([
                ...($productionReady ? [] : ($preflight['blockers'] ?? ['feature_gate'])),
            ])),
        ];
    }

    private function verifyPostedRetry(PurchaseDocument $document, array $preflight, Warehouse $warehouse): void
    {
        $journal = JournalEntry::query()->whereKey($document->journal_entry_id)->where('status', 'POSTED')
            ->where('source_type', 'PURCHASING')->where('source_event', 'supplier_invoice.inventory')
            ->where('source_id', (string) $document->id)->where('source_reference', $document->document_number)
            ->whereDate('entry_date', $document->posting_date?->format('Y-m-d'))->lockForUpdate()->first();
        if (! $journal) {
            throw ValidationException::withMessages(['journal_entry_id' => 'Inventory Purchase ที่ Post แล้วมี Journal identity ไม่ตรงกัน']);
        }
        foreach ($preflight['movement_intents'] ?? [] as $intent) {
            $movement = StockMovement::query()->where('idempotency_key', $intent['idempotency_key'])->where('warehouse_id', $warehouse->id)
                ->where('status', 'POSTED')->where('source_type', 'PURCHASING')->where('source_id', (string) $document->id)->lockForUpdate()->first();
            if (! $movement) {
                throw ValidationException::withMessages(['movement' => 'Inventory Purchase retry ไม่พบ Movement identity เดิม']);
            }
            $allocations = CostAllocation::query()->where('stock_movement_id', $movement->id)->where('idempotency_key', "movement:{$movement->id}:receipt")
                ->where('status', '!=', 'REVERSED')->lockForUpdate()->get();
            if ($allocations->count() !== 1 || (int) $allocations->sole()->journal_entry_id !== (int) $journal->id) {
                throw ValidationException::withMessages(['allocation' => 'Inventory Purchase retry มี Cost Allocation/linkage ไม่ตรงกับ Journal เดิม']);
            }
            $link = CostAllocationJournalLine::query()->where('allocation_id', $allocations->sole()->id)->where('revision', $allocations->sole()->revision)
                ->whereHas('journalEntryLine', fn ($query) => $query->where('journal_entry_id', $journal->id))->lockForUpdate()->get();
            if ($link->count() !== 1) {
                throw ValidationException::withMessages(['journal_line' => 'Inventory Purchase retry ไม่พบ immutable Journal-line linkage เดิม']);
            }
        }
        $this->defaultReconciliationGate($warehouse, $document->posting_date->format('Y-m-d'));
    }

    public function post(PurchaseDocument $document, Warehouse $warehouse, User $actor, ?Account $purchaseAp = null, bool $featureEnabled = false, ?string $postingDate = null): PurchaseDocument
    {
        if (! $featureEnabled) {
            throw ValidationException::withMessages(['posting' => 'Inventory Purchase production adapter ยังไม่เปิดใช้งาน']);
        }

        return $this->postWithCallbacks($document, $warehouse, $actor, $purchaseAp, $featureEnabled, null, $postingDate);
    }
}
