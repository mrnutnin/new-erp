<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostAllocationJournalLine;
use App\Modules\Wms\Support\RecostGlPostingContract;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transactional RECOST -> GL adapter. It is intentionally not exposed by a
 * route; callers must explicitly opt in after the Inventory release gate.
 */
final class RecostGlPostingService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly AccountMappingService $mappings,
        private readonly InventoryCostAllocationService $allocations,
    ) {}

    public function post(CostAllocation $allocation, Warehouse $warehouse, array $gate, ?User $actor = null): mixed
    {
        return DB::transaction(function () use ($allocation, $warehouse, $gate, $actor) {
            $locked = CostAllocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            $input = [
                'warehouse_id' => $locked->warehouse_id, 'item_id' => $locked->item_id,
                'recost_request_id' => $locked->recost_request_id, 'parent_allocation_id' => $locked->parent_allocation_id,
                'revision' => $locked->revision, 'delta_value' => (string) $locked->value,
                'business_date' => $locked->business_date?->format('Y-m-d'), 'source_type' => 'WMS_RECOST',
                'period_open' => (bool) ($gate['period_open'] ?? false),
                'reconciliation_ready' => (bool) ($gate['reconciliation_ready'] ?? false),
                'status' => $locked->status, 'journal_entry_id' => $locked->journal_entry_id,
            ];
            // Build the immutable identity before checking lifecycle state so
            // a retry can verify/reuse the exact Journal already linked.
            $plan = RecostGlPostingContract::preflight([...$input, 'status' => 'PENDING', 'journal_entry_id' => null]);
            if ($locked->journal_entry_id !== null) {
                $existing = $locked->journalEntry()->lockForUpdate()->first();
                if (! $existing || $existing->status !== 'POSTED'
                    || $existing->source_type !== 'WMS_RECOST'
                    || $existing->source_event !== $plan['event_code']
                    || (string) $existing->source_id !== $plan['source_identity']
                    || (int) $existing->warehouse_id !== (int) $locked->warehouse_id) {
                    throw ValidationException::withMessages(['journal_entry_id' => 'Journal Recost เดิมไม่ตรงกับ source identity']);
                }
                CostAllocationJournalLine::query()
                    ->where('allocation_id', $locked->id)
                    ->where('revision', $locked->revision)
                    ->lockForUpdate()->firstOrFail();

                return $existing;
            }
            $plan = RecostGlPostingContract::preflight($input);
            if ((int) $locked->warehouse_id !== (int) $warehouse->id) {
                throw ValidationException::withMessages(['warehouse_id' => 'Warehouse ของ Recost ไม่ตรงกับ Journal']);
            }
            $inventory = $this->mappings->resolve('INVENTORY_DEFAULT');
            $variance = $this->mappings->resolve($plan['mapping_keys'][1]);
            // Journal lines follow Global Setting's accounting precision;
            // Recost layers retain eight decimals, so normalize only at the
            // GL boundary with the same half-up policy as other postings.
            $amount = BigDecimal::of($plan['delta_value'])->toScale(2, RoundingMode::HALF_UP)->__toString();

            $journal = $this->journals->postWithinTransaction([
                'source_type' => 'WMS_RECOST', 'source_id' => $plan['source_identity'],
                'source_reference' => 'WMS Recost #'.$locked->recost_request_id,
                'event_code' => 'inventory.recost', 'entry_date' => $locked->business_date?->format('Y-m-d'),
                'document_date' => $locked->business_date?->format('Y-m-d'), 'description' => 'ปรับต้นทุนสินค้าอัตโนมัติ',
                'lines' => $locked->value > 0 ? [
                    ['account_id' => $inventory->id, 'subledger_type' => 'ITEM', 'subledger_id' => (string) $locked->item_id, 'description' => 'เพิ่มมูลค่าสินค้าจาก Recost', 'debit' => $amount, 'credit' => '0.00'],
                    ['account_id' => $variance->id, 'subledger_type' => null, 'subledger_id' => null, 'description' => 'กำไรจาก Recost', 'debit' => '0.00', 'credit' => $amount],
                ] : [
                    ['account_id' => $variance->id, 'subledger_type' => null, 'subledger_id' => null, 'description' => 'ขาดทุนจาก Recost', 'debit' => $amount, 'credit' => '0.00'],
                    ['account_id' => $inventory->id, 'subledger_type' => 'ITEM', 'subledger_id' => (string) $locked->item_id, 'description' => 'ลดมูลค่าสินค้าจาก Recost', 'debit' => '0.00', 'credit' => $amount],
                ],
            ], $warehouse, $actor);
            $inventoryLine = $journal->lines()->where('account_id', $inventory->id)
                ->where('subledger_type', 'ITEM')->where('subledger_id', (string) $locked->item_id)
                ->where(function ($query) use ($locked, $amount): void {
                    if ($locked->value > 0) {
                        $query->where('debit', $amount)->where('credit', '0.00');
                    } else {
                        $query->where('debit', '0.00')->where('credit', $amount);
                    }
                })->lockForUpdate()->sole();
            $this->allocations->linkJournalLineWithinTransaction($locked, $inventoryLine);

            return $journal;
        }, 3);
    }
}
