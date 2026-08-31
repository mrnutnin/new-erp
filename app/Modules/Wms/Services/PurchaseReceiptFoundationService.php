<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\PurchaseDocumentLine;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Support\PurchaseReceiptSourceValidator;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft receipt intent from a posted inventory Purchase Invoice.
 * It deliberately stops before movement posting/cost allocation/GL so the
 * missing inventory invoice posting path cannot be bypassed accidentally.
 */
final class PurchaseReceiptFoundationService
{
    public function __construct(
        private readonly PurchaseReceiptSourceValidator $source,
        private readonly StockMovementService $movements,
    ) {}

    public function recordIntent(
        PurchaseDocument $purchaseDocument,
        PurchaseDocumentLine $line,
        string $quantity,
        string $businessDate,
        string $receiptReference,
        ?User $actor = null,
    ): StockMovement {
        return DB::transaction(function () use ($purchaseDocument, $line, $quantity, $businessDate, $receiptReference, $actor): StockMovement {
            $document = PurchaseDocument::query()->with('journalEntry')->lockForUpdate()->findOrFail($purchaseDocument->id);
            $line = PurchaseDocumentLine::query()->lockForUpdate()->findOrFail($line->id);
            $this->assertLineBelongsToDocument($document, $line);
            $line->setRelation('item', Item::query()->whereKey($line->item_id)->sharedLock()->first());
            $line->setRelation('uom', Uom::query()->whereKey($line->uom_id)->sharedLock()->first());
            $this->source->assertDocumentReady($document, $document->journalEntry);
            $this->assertReceiptLine($line, $document, $businessDate, $quantity);

            $receiptReference = trim($receiptReference);
            if ($receiptReference === '' || strlen($receiptReference) > 80) {
                throw ValidationException::withMessages(['receipt_reference' => 'ต้องระบุเลขอ้างอิง Receipt ไม่เกิน 80 ตัวอักษร']);
            }
            $idempotencyKey = "purchase-receipt:{$document->id}:line:{$line->id}:{$receiptReference}";
            $existing = $this->findByIdempotencyKey($idempotencyKey);
            if ($existing) {
                if (! BigDecimal::of((string) $existing->quantity)->isEqualTo(BigDecimal::of($quantity)) || (string) $existing->business_date !== $businessDate
                    || (int) $existing->item_id !== (int) $line->item_id || (int) $existing->uom_id !== (int) $line->uom_id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Receipt idempotency key นี้ถูกใช้กับข้อมูลอื่นแล้ว']);
                }

                return $existing;
            }
            $received = StockMovement::query()
                ->where('source_type', 'PURCHASING')->where('source_id', (string) $document->id)
                ->where('movement_type', 'RECEIPT')->where('status', '!=', 'VOID')
                ->where('metadata->purchase_document_line_id', $line->id)
                ->lockForUpdate()->sum('quantity');
            if (BigDecimal::of((string) $received)->plus($quantity)->isGreaterThan(BigDecimal::of((string) $line->quantity))) {
                throw ValidationException::withMessages(['quantity' => 'จำนวน Receipt สะสมเกินจำนวนใน Purchase line']);
            }

            return $this->movements->recordIntent([
                'warehouse_id' => $document->warehouse_id,
                'item_id' => $line->item_id,
                'uom_id' => $line->uom_id,
                'movement_type' => 'RECEIPT',
                'direction' => 'IN',
                'status' => 'DRAFT',
                'quantity' => $quantity,
                'base_quantity' => $quantity,
                'business_date' => $businessDate,
                'source_type' => 'PURCHASING',
                'source_id' => (string) $document->id,
                'source_reference' => $document->document_number,
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'purchase_document_line_id' => $line->id,
                    'receipt_reference' => $receiptReference,
                    'created_by' => $actor?->id,
                ],
                'created_by' => $actor?->id,
            ]);
        }, 3);
    }

    public function updateIntent(StockMovement $movement, string $quantity, string $businessDate, string $receiptReference, ?User $actor = null): StockMovement
    {
        return DB::transaction(function () use ($movement, $quantity, $businessDate, $receiptReference, $actor): StockMovement {
            $current = StockMovement::query()->lockForUpdate()->findOrFail($movement->id);
            if ($current->status !== 'DRAFT' || $current->movement_type !== 'RECEIPT' || $current->source_type !== 'PURCHASING') {
                throw ValidationException::withMessages(['status' => 'แก้ได้เฉพาะ Draft Receipt เท่านั้น']);
            }
            $metadata = is_array($current->metadata) ? $current->metadata : [];
            $document = PurchaseDocument::query()->with('journalEntry')->lockForUpdate()->findOrFail((int) $current->source_id);
            $line = PurchaseDocumentLine::query()->lockForUpdate()->findOrFail((int) ($metadata['purchase_document_line_id'] ?? 0));
            $this->assertLineBelongsToDocument($document, $line);
            $line->setRelation('item', Item::query()->whereKey($line->item_id)->sharedLock()->first());
            $line->setRelation('uom', Uom::query()->whereKey($line->uom_id)->sharedLock()->first());
            $this->source->assertDocumentReady($document, $document->journalEntry);
            $this->assertReceiptLine($line, $document, $businessDate, $quantity);
            $receiptReference = trim($receiptReference);
            if ($receiptReference === '' || strlen($receiptReference) > 80) {
                throw ValidationException::withMessages(['receipt_reference' => 'ต้องระบุเลขอ้างอิง Receipt ไม่เกิน 80 ตัวอักษร']);
            }
            $newKey = "purchase-receipt:{$document->id}:line:{$line->id}:{$receiptReference}";
            $conflict = $this->findByIdempotencyKey($newKey);
            if ($conflict && (int) $conflict->id !== (int) $current->id) {
                throw ValidationException::withMessages(['idempotency_key' => 'เลขอ้างอิง Receipt นี้ถูกใช้แล้ว']);
            }
            $received = StockMovement::query()->where('source_type', 'PURCHASING')->where('source_id', (string) $document->id)
                ->where('movement_type', 'RECEIPT')->where('status', '!=', 'VOID')->where('metadata->purchase_document_line_id', $line->id)
                ->where('id', '!=', $current->id)->lockForUpdate()->sum('quantity');
            if (BigDecimal::of((string) $received)->plus($quantity)->isGreaterThan(BigDecimal::of((string) $line->quantity))) {
                throw ValidationException::withMessages(['quantity' => 'จำนวน Receipt สะสมเกินจำนวนใน Purchase line']);
            }
            $history = array_values(array_unique([...(is_array($metadata['idempotency_key_history'] ?? null) ? $metadata['idempotency_key_history'] : []), (string) $current->idempotency_key]));
            $current->update([
                'quantity' => $quantity, 'base_quantity' => $quantity, 'business_date' => $businessDate,
                'idempotency_key' => $newKey, 'metadata' => [...$metadata, 'receipt_reference' => $receiptReference, 'idempotency_key_history' => $history, 'updated_by' => $actor?->id],
            ]);

            return $current->fresh(['item', 'uom']);
        }, 3);
    }

    private function findByIdempotencyKey(string $key): ?StockMovement
    {
        return StockMovement::query()->where(fn ($query) => $query->where('idempotency_key', $key)->orWhereJsonContains('metadata->idempotency_key_history', $key))->lockForUpdate()->first();
    }

    private function assertLineBelongsToDocument(PurchaseDocument $document, PurchaseDocumentLine $line): void
    {
        if ((int) $line->purchase_document_id !== (int) $document->id) {
            throw ValidationException::withMessages(['line_id' => 'บรรทัดสินค้าไม่อยู่ใน Purchase Invoice นี้']);
        }
    }

    private function assertReceiptLine(PurchaseDocumentLine $line, PurchaseDocument $document, string $businessDate, string $quantity): void
    {
        if (! $line->item_id || ! $line->uom_id || ! $line->item?->is_active || ! $line->item?->is_stock_item || ! $line->uom?->is_active) {
            throw ValidationException::withMessages(['line_id' => 'Purchase line ต้องมี Item/UOM ที่ active และเป็น stock item ก่อนสร้าง Receipt']);
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate) || $businessDate < $document->document_date->format('Y-m-d')) {
            throw ValidationException::withMessages(['business_date' => 'วันที่ Receipt ต้องเป็น Y-m-d และไม่ก่อนวันที่ Purchase Invoice']);
        }
        $amount = preg_match('/^\d+(?:\.\d{1,4})?$/', $quantity) ? BigDecimal::of($quantity) : null;
        if (! $amount || $amount->isLessThanOrEqualTo(0) || $amount->isGreaterThan(BigDecimal::of((string) $line->quantity))) {
            throw ValidationException::withMessages(['quantity' => 'จำนวน Receipt ต้องมากกว่าศูนย์และไม่เกินจำนวนใน Purchase line']);
        }
    }
}
