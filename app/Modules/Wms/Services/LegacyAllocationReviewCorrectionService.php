<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostAllocationCorrection;
use App\Modules\Wms\Models\CostAllocationReview;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Controlled repair for explicitly approved legacy reviews. */
final class LegacyAllocationReviewCorrectionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function resolve(CostAllocationReview $review, User $actor, Request $request, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'ต้องระบุเหตุผลอย่างน้อย 10 ตัวอักษร']);
        }

        return DB::transaction(function () use ($review, $actor, $request, $reason): array {
            $lockedReview = CostAllocationReview::query()->lockForUpdate()->findOrFail($review->id);
            if ($lockedReview->status !== 'OPEN') {
                throw ValidationException::withMessages(['review' => 'Review นี้ถูกดำเนินการแล้ว']);
            }

            $allocation = CostAllocation::query()->lockForUpdate()->findOrFail($lockedReview->allocation_id);
            $movement = $allocation->movement()->lockForUpdate()->firstOrFail();
            if (! in_array((string) $movement->source_type, ['POS', 'WMS_PRODUCTION_RECEIPT'], true)) {
                throw ValidationException::withMessages(['review' => 'เอกสารประเภทนี้ต้องกู้คืน Journal proof ก่อน จึงยังไม่สามารถทำ Legacy Correction ได้']);
            }
            $documentDate = $this->documentDate($movement);
            if ($documentDate === null) {
                throw ValidationException::withMessages(['document' => 'ไม่พบ document_date ของต้นทาง']);
            }

            $before = [
                'review' => $lockedReview->toArray(),
                'movement_date' => $movement->business_date?->format('Y-m-d'),
                'allocation_date' => $allocation->business_date?->format('Y-m-d'),
                'allocation_status' => $allocation->status,
            ];
            $correction = null;

            // Legacy date repair is explicit and audited. It is only allowed
            // here because the source review was quarantined for this purpose.
            if ($movement->business_date?->format('Y-m-d') !== $documentDate) {
                DB::table('wms_stock_movements')->where('id', $movement->id)->update([
                    'business_date' => $documentDate,
                    'updated_at' => now(),
                ]);
                DB::table('wms_cost_allocations')->where('id', $allocation->id)->update([
                    'business_date' => $documentDate,
                    'updated_at' => now(),
                ]);
            }

            if ($this->isProductionReceiptDuplicate($allocation, $movement)) {
                $canonical = CostAllocation::query()
                    ->where('stock_movement_id', $allocation->stock_movement_id)
                    ->where('id', '!=', $allocation->id)
                    ->where('status', '!=', 'REVERSED')
                    ->whereNotNull('journal_entry_id')
                    ->where('idempotency_key', 'like', 'allocation:production-receipt:%')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if (! $canonical) {
                    throw ValidationException::withMessages(['allocation' => 'ไม่พบ canonical Production Receipt allocation']);
                }
                $correction = CostAllocationCorrection::query()->firstOrCreate(
                    ['allocation_id' => $allocation->id],
                    [
                        'canonical_allocation_id' => $canonical->id,
                        'correction_type' => 'LEGACY_DUPLICATE',
                        'reason' => trim($reason),
                        'evidence' => [
                            'contract' => 'legacy-review-correction-v1',
                            'duplicate_id' => $allocation->id,
                            'canonical_id' => $canonical->id,
                            'movement_id' => $movement->id,
                            'document_date' => $documentDate,
                        ],
                        'created_by' => $actor->id,
                        'applied_at' => now(),
                    ],
                );
            }

            $evidence = is_array($lockedReview->evidence) ? $lockedReview->evidence : [];
            $evidence['correction'] = [
                'contract' => 'legacy-review-correction-v1',
                'approved_by' => $actor->id,
                'reason' => trim($reason),
                'document_date' => $documentDate,
                'movement_date_after' => $documentDate,
                'allocation_date_after' => $documentDate,
                'correction_id' => $correction?->id,
            ];
            $lockedReview->forceFill(['status' => 'RESOLVED', 'proposed_state' => 'CORRECTED', 'reason' => trim($reason), 'evidence' => $evidence])->save();

            $after = [
                'review' => $lockedReview->fresh()->toArray(),
                'movement_date' => $documentDate,
                'allocation_date' => $documentDate,
                'correction_id' => $correction?->id,
            ];
            $this->audit->record('wms.cost_allocation.legacy_review_corrected', $lockedReview, $before, $after, $actor, $request);

            return ['review_id' => $lockedReview->id, 'allocation_id' => $allocation->id, 'document_date' => $documentDate, 'correction_id' => $correction?->id, 'status' => 'RESOLVED'];
        }, 3);
    }

    private function documentDate($movement): ?string
    {
        $date = match ((string) $movement->source_type) {
            'POS' => PhysicalSale::query()->where('document_number', $movement->source_reference)->value('document_date'),
            'WMS_PRODUCTION_RECEIPT' => InventoryAdjustmentDocument::query()->whereKey((int) $movement->source_id)->value('document_date'),
            default => null,
        };

        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : ($date ? substr((string) $date, 0, 10) : null);
    }

    private function isProductionReceiptDuplicate(CostAllocation $allocation, $movement): bool
    {
        return $movement->source_type === 'WMS_PRODUCTION_RECEIPT'
            && $allocation->parent_allocation_id === null
            && $allocation->journal_entry_id === null
            && str_starts_with((string) $allocation->idempotency_key, 'movement:');
    }
}
