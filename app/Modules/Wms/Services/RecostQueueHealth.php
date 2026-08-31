<?php

namespace App\Modules\Wms\Services;

use App\Models\CompanySetting;
use App\Modules\Wms\Models\CostRecalculationRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecostQueueHealth
{
    public function staleMinutes(?int $override = null): int
    {
        if ($override !== null) {
            return max(1, $override);
        }

        return max(1, (int) (CompanySetting::query()->whereKey(1)->value('recost_sla_minutes') ?: 60));
    }

    /** Mark work that stopped moving as STALE; no ledger rows are changed. */
    public function markStale(?int $minutes = null): int
    {
        $cutoff = Carbon::now()->subMinutes($this->staleMinutes($minutes));

        // Use one conditional update per bounded chunk. A worker can resolve a
        // request between the read and write; the status predicate prevents a
        // late scheduler pass from turning RESOLVED work back into STALE.
        $count = 0;
        CostRecalculationRequest::query()
            ->whereIn('status', ['PENDING', 'PROCESSING'])
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(250, function ($requests) use (&$count, $cutoff): void {
                $ids = $requests->pluck('id')->all();
                if ($ids === []) {
                    return;
                }

                $count += CostRecalculationRequest::query()
                    ->whereIn('id', $ids)
                    ->whereIn('status', ['PENDING', 'PROCESSING'])
                    ->where('updated_at', '<', $cutoff)
                    ->update([
                        'status' => 'STALE',
                        'last_error' => 'เกิน SLA การคำนวณต้นทุนใหม่',
                        'updated_at' => now(),
                    ]);
            });

        return $count;
    }

    public function summary(?int $warehouseId = null): array
    {
        $counts = CostRecalculationRequest::query()
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(['PENDING', 'PROCESSING', 'FAILED', 'STALE', 'RESOLVED'])
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
    }

    /** A bounded operational view; the queue remains server-side and paged. */
    public function recentOpen(?int $warehouseId = null, int $limit = 50): array
    {
        return CostRecalculationRequest::query()
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->whereIn('status', ['PENDING', 'PROCESSING', 'FAILED', 'STALE'])
            ->orderByDesc('updated_at')
            ->limit(max(1, min($limit, 100)))
            ->get(['id', 'item_id', 'status', 'attempts', 'last_error', 'updated_at'])
            ->map(fn (CostRecalculationRequest $request): array => [
                'id' => $request->id,
                'item_id' => $request->item_id,
                'status' => $request->status,
                'attempts' => (int) $request->attempts,
                'last_error' => $request->last_error ?: '-',
                'updated_at' => $request->updated_at?->format('d/m/Y H:i:s') ?: '-',
            ])->all();
    }

    public function retry(int $requestId, ?int $warehouseId = null): void
    {
        DB::transaction(function () use ($requestId, $warehouseId): void {
            $request = CostRecalculationRequest::query()
                ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
                ->lockForUpdate()
                ->findOrFail($requestId);

            // Never reset active work. The receipt-triggered dispatcher will
            // discover a PENDING request; FAILED/STALE are the only safe
            // operator recovery states.
            if (! in_array($request->status, ['FAILED', 'STALE'], true)) {
                throw ValidationException::withMessages([
                    'request_id' => $request->status === 'RESOLVED'
                        ? 'รายการนี้แก้ไขเสร็จแล้ว ไม่ต้อง Retry'
                        : 'รายการกำลังรอหรือกำลังประมวลผลอยู่ ไม่ต้อง Retry ซ้ำ',
                ]);
            }

            $request->retry();
        });
    }
}
