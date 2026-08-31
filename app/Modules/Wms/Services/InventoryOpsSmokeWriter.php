<?php

namespace App\Modules\Wms\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\StockMovement;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Explicit, idempotent writer for the development-only Inventory -> GL smoke chain.
 * The caller must opt in with the OPS-SMOKE prefix and --confirm.
 */
final class InventoryOpsSmokeWriter
{
    public function __construct(
        private readonly InventoryOpsSmokeContract $contract,
        private readonly ProcurementSourceBuilder $sources,
        private readonly InventoryPurchaseProductionAdapter $posting,
        private readonly InventoryReconciliationService $reconciliation,
    ) {}

    /** @return array<string, int|float|bool|string|array<string, mixed>> */
    public function run(string $prefix, int $actorId, bool $confirm): array
    {
        $actor = User::query()->whereKey($actorId)->where('is_active', true)->first();
        $this->contract->validate($prefix, $actor?->id, $confirm);

        $previousFlag = (bool) config('erp.inventory.purchase_posting_enabled', false);
        if ($previousFlag) {
            throw ValidationException::withMessages(['posting' => 'ต้องปิด Inventory posting ก่อนเริ่ม OPS smoke']);
        }

        try {
            return DB::transaction(function () use ($actor, $prefix): array {
                $source = $this->sources->build(
                    $actor,
                    $prefix,
                    static fn (Closure $callback): mixed => $callback(),
                    true,
                );
                $warehouse = Warehouse::query()->findOrFail((int) $source['warehouse_id']);
                $document = PurchaseDocument::query()->findOrFail((int) $source['purchase_document_id']);

                // Runtime-only opt-in; config is restored even when posting fails.
                config(['erp.inventory.purchase_posting_enabled' => true]);
                $posted = $this->posting->post($document, $warehouse, $actor, null, true);

                $journal = JournalEntry::query()->where('source_type', 'PURCHASING')
                    ->where('source_event', 'supplier_invoice.inventory')->where('source_id', (string) $posted->id)
                    ->where('status', 'POSTED')->sole();
                $movements = StockMovement::query()->where('source_type', 'PURCHASING')
                    ->where('source_id', (string) $posted->id)->where('status', 'POSTED')->get();
                if ($movements->count() !== 1) {
                    throw new RuntimeException('OPS smoke ต้องสร้าง Stock Movement POSTED เพียง 1 รายการ');
                }
                $allocations = CostAllocation::query()->where('warehouse_id', $warehouse->id)
                    ->where('stock_movement_id', $movements->sole()->id)->where('journal_entry_id', $journal->id)
                    ->where('status', '!=', 'REVERSED')->get();
                if ($allocations->count() !== 1 || $allocations->sole()->journalLineLinks()->count() !== 1) {
                    throw new RuntimeException('OPS smoke ต้องมี Cost Allocation และ Journal linkage เพียง 1 ชุด');
                }

                $reconciliation = $this->reconciliation->totals($posted->document_date->format('Y-m-d'), $warehouse->id);
                if (($reconciliation['status'] ?? null) !== 'ตรงกัน') {
                    throw ValidationException::withMessages(['reconciliation' => 'OPS smoke reconciliation ไม่ตรงกัน']);
                }
                // Retries are idempotent reads; never append another audit
                // event for the same posted source chain.
                if (! AuditLog::query()->where('action', 'wms.inventory.ops_smoke.posted')->where('subject_type', $posted->getMorphClass())->where('subject_id', $posted->id)->exists()) {
                    AuditLog::query()->create([
                        'user_id' => $actor->id,
                        'action' => 'wms.inventory.ops_smoke.posted',
                        'subject_type' => $posted->getMorphClass(),
                        'subject_id' => $posted->id,
                        'old_values' => [],
                        'new_values' => ['prefix' => $prefix, 'source_hash' => $source['source_hash'], 'warehouse_id' => $warehouse->id, 'journal_id' => $journal->id, 'movement_id' => $movements->sole()->id, 'allocation_id' => $allocations->sole()->id, 'reconciliation' => $reconciliation],
                        'ip_address' => null,
                        'user_agent' => 'cli:wms-inventory-ops-smoke',
                    ]);
                }

                return ['prefix' => $prefix, 'source_hash' => $source['source_hash'], 'warehouse_id' => $warehouse->id, 'purchase_document_id' => $posted->id, 'journal_id' => $journal->id, 'movement_id' => $movements->sole()->id, 'allocation_id' => $allocations->sole()->id, 'reconciliation' => $reconciliation];
            });
        } finally {
            config(['erp.inventory.purchase_posting_enabled' => $previousFlag]);
        }
    }
}
