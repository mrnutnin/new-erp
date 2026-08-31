<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Atomic document-level reversal. Every posted line is reversed or none is. */
final class InventoryAdjustmentDocumentReversalService
{
    public function __construct(
        private readonly InventoryAdjustmentLiveReversalAdapter $lines,
        private readonly AuditLogger $audit,
    ) {}

    public function reverse(InventoryAdjustmentDocument $document, string $date, string $reason, User $actor, Request $request): InventoryAdjustmentDocument
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'ต้องระบุเหตุผลการกลับรายการอย่างน้อย 10 ตัวอักษร']);
        }

        return DB::transaction(function () use ($document, $date, $reason, $actor, $request): InventoryAdjustmentDocument {
            $locked = InventoryAdjustmentDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ((int) $locked->warehouse_id !== (int) $request->attributes->get('selectedWarehouse')->id) {
                abort(404);
            }
            if ($locked->reversal_status === 'REVERSED') {
                if ($locked->reversal_reason !== $reason || $locked->reversed_at?->format('Y-m-d') !== $date) {
                    throw ValidationException::withMessages(['reversal' => 'Reversal identity เดิมไม่ตรงกับคำขอใหม่']);
                }

                return $locked->fresh('lines');
            }
            if ($locked->status !== 'POSTED') {
                throw ValidationException::withMessages(['status' => 'กลับรายการได้เฉพาะเอกสาร Adjustment ที่ลงบัญชีแล้ว']);
            }

            $lines = $locked->lines()->with('item:id,code,name')->lockForUpdate()->get();
            if ($lines->isEmpty() || $lines->contains(fn ($line) => $line->status !== 'POSTED' || ! $line->stock_movement_id || ! $line->cost_allocation_id || $line->reversal_status === 'REVERSED')) {
                throw ValidationException::withMessages(['lines' => 'เอกสารต้องมีรายการ Posted ครบทุกบรรทัด และยังไม่ถูกกลับรายการ']);
            }

            $before = $locked->load('lines')->toArray();
            foreach ($lines as $line) {
                try {
                    $this->lines->reverse($line, $date, $reason, $actor, $request, true);
                } catch (ValidationException $exception) {
                    $item = $line->item;
                    $label = trim(($item?->code ?: 'สินค้า #'.$line->item_id).' · '.($item?->name ?: ''), ' ·');
                    $detail = $exception->errors()['available_quantity'][0] ?? $exception->getMessage();
                    throw ValidationException::withMessages(['reversal' => $label.' ย้อนกลับไม่ได้: '.$detail]);
                }
            }
            $locked->forceFill([
                'status' => 'REVERSED', 'reversal_status' => 'REVERSED', 'reversed_by' => $actor->id,
                'reversed_at' => CarbonImmutable::parse($date)->startOfDay(),
                'reversal_reason' => $reason, 'reversal_revision' => (int) $locked->reversal_revision + 1,
            ])->save();
            $this->audit->record('wms.inventory_adjustment.document_reversed', $locked, $before, $locked->fresh('lines')->toArray(), $actor, $request);

            return $locked->fresh('lines');
        }, 3);
    }
}
