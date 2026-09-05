<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostAllocationCorrection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CostAllocationCorrectionService
{
    public function candidates(int $warehouseId): array
    {
        $duplicates = CostAllocation::query()->where('warehouse_id', $warehouseId)->where('status', '!=', 'REVERSED')
            ->whereNull('parent_allocation_id')->where('idempotency_key', 'like', 'movement:%:receipt')
            ->whereHas('movement', fn ($query) => $query->where('source_type', 'POS'))->orderBy('id')->get();

        return $duplicates->map(function (CostAllocation $duplicate): ?array {
            $canonical = CostAllocation::query()->where('stock_movement_id', $duplicate->stock_movement_id)
                ->where('item_id', $duplicate->item_id)->whereNotNull('parent_allocation_id')->where('status', '!=', 'REVERSED')
                ->where('value', $duplicate->value)->where('quantity', $duplicate->quantity)->where('unit_cost', $duplicate->unit_cost)
                ->where('journal_entry_id', $duplicate->journal_entry_id)->get();
            if ($canonical->count() !== 1 || CostAllocationCorrection::query()->where('allocation_id', $duplicate->id)->exists()) {
                return null;
            }

            $canonical = $canonical->sole();
            $duplicateLine = $duplicate->journalLineLinks()->first();
            $canonicalLine = $canonical->journalLineLinks()->first();
            if (! $duplicateLine || ! $canonicalLine || (int) $duplicateLine->journal_entry_line_id !== (int) $canonicalLine->journal_entry_line_id) {
                return null;
            }

            return ['duplicate' => $duplicate, 'canonical' => $canonical, 'journal_entry_line_id' => $duplicateLine->journal_entry_line_id];
        })->filter()->values()->all();
    }

    public function apply(int $warehouseId, ?User $actor, Request $request): array
    {
        return collect($this->candidates($warehouseId))->map(function (array $candidate) use ($actor, $request): array {
            $duplicate = $candidate['duplicate'];
            $canonical = $candidate['canonical'];
            $correction = DB::transaction(function () use ($duplicate, $canonical, $actor, $request): CostAllocationCorrection {
                $existing = CostAllocationCorrection::query()->where('allocation_id', $duplicate->id)->first();
                if ($existing) {
                    return $existing;
                }
                $correction = CostAllocationCorrection::query()->create([
                    'allocation_id' => $duplicate->id,
                    'canonical_allocation_id' => $canonical->id,
                    'correction_type' => 'LEGACY_DUPLICATE',
                    'reason' => 'Legacy POS reversal allocation ซ้ำกับ canonical allocation และใช้ Journal line เดียวกัน',
                    'evidence' => [
                        'duplicate_id' => $duplicate->id,
                        'canonical_id' => $canonical->id,
                        'stock_movement_id' => $duplicate->stock_movement_id,
                        'journal_entry_id' => $duplicate->journal_entry_id,
                        'journal_entry_line_id' => $duplicate->journalLineLinks()->value('journal_entry_line_id'),
                        'contract' => 'inventory-correction-v1',
                    ],
                    'created_by' => $actor?->id,
                    'applied_at' => now(),
                ]);
                app(AuditLogger::class)->record('wms.cost_allocation.legacy_duplicate_corrected', $correction, [], $correction->toArray(), $actor, $request);

                return $correction;
            }, 3);

            return ['allocation_id' => $duplicate->id, 'canonical_allocation_id' => $canonical->id, 'correction_id' => $correction->id, 'status' => 'CORRECTED'];
        })->all();
    }
}
