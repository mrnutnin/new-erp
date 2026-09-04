<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseReturnLine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseReturnService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PurchaseReturnEligibilityService $eligibility,
    ) {}

    public function createDraft(array $attributes, User $actor, Request $request): PurchaseReturn
    {
        return DB::transaction(function () use ($attributes, $actor, $request): PurchaseReturn {
            $receipt = GoodsReceipt::query()->with(['warehouse.branch', 'supplier', 'lines'])->lockForUpdate()->findOrFail((int) $attributes['goods_receipt_id']);
            if ($receipt->status !== 'APPROVED') {
                throw ValidationException::withMessages(['goods_receipt_id' => 'คืนได้เฉพาะ Goods Receipt ที่อนุมัติแล้ว']);
            }
            $warehouse = $receipt->warehouse;
            if (! $warehouse?->branch) {
                throw ValidationException::withMessages(['warehouse_id' => 'คลังของ Goods Receipt ไม่มีสาขา']);
            }
            $purchaseDocument = ! empty($attributes['purchase_document_id'])
                ? PurchaseDocument::query()->with('lines')->lockForUpdate()->findOrFail((int) $attributes['purchase_document_id'])
                : null;
            if ($purchaseDocument && (! in_array($purchaseDocument->status, ['APPROVED', 'POSTED'], true) || (int) $purchaseDocument->warehouse_id !== (int) $receipt->warehouse_id || (int) $purchaseDocument->supplier_id !== (int) $receipt->supplier_id)) {
                throw ValidationException::withMessages(['purchase_document_id' => 'Invoice ต้องอนุมัติแล้วและอยู่ Supplier/Warehouse เดียวกับ Goods Receipt']);
            }

            $key = trim((string) ($attributes['idempotency_key'] ?? ''));
            if ($key === '') {
                throw ValidationException::withMessages(['idempotency_key' => 'ต้องระบุ idempotency key']);
            }
            $existing = PurchaseReturn::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $existing->load('lines');
            }

            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $attributes['return_date']);
            if (! $date || $date->format('Y-m-d') !== $attributes['return_date'] || $date->lt($receipt->business_date)) {
                throw ValidationException::withMessages(['return_date' => 'วันที่คืนต้องเป็นวันที่จริงและไม่ก่อนวันที่รับสินค้า']);
            }
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PURCHASE_RETURN')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['return_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร Purchase Return']);
            }

            $inputs = collect($attributes['lines'] ?? []);
            $receiptLines = $receipt->lines->keyBy('id');
            if ($inputs->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'ต้องมีรายการคืนอย่างน้อย 1 รายการ']);
            }
            $number = $this->sequences->issueAvailableForBranch($sequence, $warehouse->branch, $date, fn (string $candidate): bool => PurchaseReturn::query()->where('return_number', $candidate)->exists());
            $return = PurchaseReturn::create([
                'warehouse_id' => $receipt->warehouse_id, 'branch_id' => $warehouse->branch_id, 'supplier_id' => $receipt->supplier_id,
                'purchase_document_id' => $purchaseDocument?->id, 'goods_receipt_id' => $receipt->id, 'return_number' => $number,
                'idempotency_key' => $key, 'return_date' => $date->format('Y-m-d'), 'reason' => $attributes['reason'],
                'supplier_code' => $receipt->supplier?->code ?? '', 'supplier_name' => $receipt->supplier?->name ?? '',
                'supplier_tax_id' => $receipt->supplier?->tax_id, 'supplier_branch_code' => $receipt->supplier?->branch_code ?? '00000',
                'supplier_address' => $receipt->supplier?->address, 'status' => 'DRAFT', 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $this->sequences->recordIssued($sequence, $number, 'purchase_returns', $return->id, $date, $actor->id);

            $subtotal = BigDecimal::zero();
            foreach ($inputs as $lineInput) {
                $receiptLine = $receiptLines->get((int) ($lineInput['goods_receipt_line_id'] ?? 0));
                if (! $receiptLine instanceof GoodsReceiptLine) {
                    throw ValidationException::withMessages(['lines' => 'รายการคืนต้องอยู่ใน Goods Receipt ที่เลือก']);
                }
                $this->eligibility->assertLineQuantityAllowed($receiptLine, (string) $lineInput['purchase_quantity']);
                $quantity = BigDecimal::of((string) $lineInput['purchase_quantity']);
                $stockQuantity = $quantity->multipliedBy((string) $receiptLine->factor)->toScale(8);
                $totalCost = $stockQuantity->multipliedBy((string) $receiptLine->stock_unit_cost)->toScale(8);
                $amount = $totalCost->toScale(2);
                $subtotal = $subtotal->plus($amount);
                $return->lines()->create([
                    'goods_receipt_line_id' => $receiptLine->id, 'purchase_document_line_id' => $this->documentLineId($purchaseDocument, $receiptLine),
                    'item_id' => $receiptLine->item_id, 'purchase_uom_id' => $receiptLine->purchase_uom_id, 'stock_uom_id' => $receiptLine->stock_uom_id,
                    'purchase_quantity' => $quantity->toScale(8), 'stock_quantity' => $stockQuantity, 'factor' => $receiptLine->factor,
                    'unit_cost' => $receiptLine->stock_unit_cost, 'total_cost' => $totalCost, 'net_amount' => $amount, 'gross_amount' => $amount,
                    'reason' => $lineInput['reason'] ?? null, 'source_snapshot' => ['receipt_id' => $receipt->id, 'receipt_line_id' => $receiptLine->id, 'purchase_quantity' => $quantity->__toString(), 'stock_quantity' => $stockQuantity->__toString(), 'factor' => (string) $receiptLine->factor, 'unit_cost' => (string) $receiptLine->stock_unit_cost],
                ]);
            }
            $return->update(['subtotal' => $subtotal->toScale(2), 'gross_amount' => $subtotal->toScale(2)]);

            return $return->fresh('lines');
        }, 3);
    }

    public function submit(PurchaseReturn $return, User $actor): PurchaseReturn
    {
        return $this->transition($return, 'DRAFT', 'SUBMITTED', ['updated_by' => $actor->id]);
    }

    public function approve(PurchaseReturn $return, User $actor): PurchaseReturn
    {
        return $this->transition($return, 'SUBMITTED', 'APPROVED', ['approved_by' => $actor->id, 'approved_at' => now(), 'updated_by' => $actor->id]);
    }

    public function void(PurchaseReturn $return, string $reason, User $actor): PurchaseReturn
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร']);
        }
        return $this->transition($return, ['DRAFT', 'SUBMITTED', 'APPROVED'], 'VOID', ['voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => trim($reason), 'updated_by' => $actor->id]);
    }

    private function transition(PurchaseReturn $return, string|array $from, string $to, array $attributes): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $from, $to, $attributes): PurchaseReturn {
            $locked = PurchaseReturn::query()->lockForUpdate()->findOrFail($return->id);
            if (! in_array($locked->status, (array) $from, true)) {
                throw ValidationException::withMessages(['status' => "เปลี่ยนสถานะจาก {$locked->status} เป็น {$to} ไม่ได้"]);
            }
            $locked->update(['status' => $to, ...$attributes]);
            return $locked->fresh('lines');
        }, 3);
    }

    private function documentLineId(?PurchaseDocument $document, GoodsReceiptLine $receiptLine): ?int
    {
        if (! $document) return null;
        return $document->lines->first(fn ($line): bool => (int) $line->purchase_order_line_id === (int) $receiptLine->purchase_order_line_id)?->id;
    }
}
