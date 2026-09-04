<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Support\PurchaseReturnWmsPostingContract;
use App\Modules\Wms\Services\CreditPurchaseInventoryReversalAdapter;
use App\Modules\Wms\Services\PurchaseReturnPartialInventoryAdapter;
use App\Modules\Wms\Services\PurchaseDocumentPostingService;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseReturnPostingService
{
    public function __construct(
        private readonly PurchaseReturnCreditNoteService $creditNotes,
        private readonly PurchaseDocumentPostingService $documents,
        private readonly CreditPurchaseInventoryReversalAdapter $reversal,
        private readonly PurchaseReturnPartialInventoryAdapter $partialInventory,
    ) {}

    public function post(PurchaseReturn $purchaseReturn, string $postingDate, User $actor, Request $request, bool $inventoryFeatureEnabled = false): PurchaseReturn
    {
        if (! $inventoryFeatureEnabled) {
            throw ValidationException::withMessages(['posting' => 'Purchase Return Stock/Cost posting ยังไม่เปิดใช้งาน']);
        }

        return DB::transaction(function () use ($purchaseReturn, $postingDate, $actor, $request): PurchaseReturn {
            $return = PurchaseReturn::query()->with(['purchaseDocument', 'goodsReceipt.lines', 'lines.goodsReceiptLine'])->lockForUpdate()->findOrFail($purchaseReturn->id);
            if ($return->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'Post ได้เฉพาะ Purchase Return ที่อนุมัติแล้ว']);
            }
            $this->assertFullLine($return);
            $credit = $return->creditNote;
            if (! $credit) {
                $credit = $this->creditNotes->createDraft($return, $actor, $request);
                $credit->forceFill(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_by' => $actor->id])->save();
            } elseif ($credit->credit_note_mode !== 'RETURN') {
                throw ValidationException::withMessages(['credit_note_id' => 'Purchase Return ต้องผูก Credit Note mode RETURN']);
            }
            $credit = $credit->fresh();
            if ($credit->status === 'DRAFT') {
                $credit->forceFill(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_by' => $actor->id])->save();
                $credit->refresh();
            }
            if ($credit->status === 'APPROVED') {
                $credit = $this->documents->post($credit, $postingDate, $actor, $request);
            }
            $this->reversal->reverse($credit, $postingDate, $return->reason, $actor, true);
            $return->update(['status' => 'POSTED', 'posted_by' => $actor->id, 'posted_at' => now(), 'updated_by' => $actor->id]);

            return $return->fresh(['creditNote', 'lines']);
        }, 3);
    }

    public function postPartial(PurchaseReturn $purchaseReturn, string $postingDate, User $actor, Request $request, bool $inventoryFeatureEnabled = false): PurchaseReturn
    {
        if (! $inventoryFeatureEnabled) {
            throw ValidationException::withMessages(['posting' => 'Partial Purchase Return Stock/Cost posting ยังไม่เปิดใช้งาน']);
        }

        return DB::transaction(function () use ($purchaseReturn, $postingDate, $actor, $request): PurchaseReturn {
            $return = PurchaseReturn::query()->with(['purchaseDocument', 'goodsReceipt.lines', 'lines.goodsReceiptLine'])->lockForUpdate()->findOrFail($purchaseReturn->id);
            if ($return->status === 'POSTED') {
                return $return->fresh(['creditNote', 'lines']);
            }
            if ($return->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'Post ได้เฉพาะ Purchase Return ที่อนุมัติแล้ว']);
            }
            $credit = $return->creditNote;
            if (! $credit) {
                $credit = $this->creditNotes->createDraft($return, $actor, $request);
                $credit->forceFill(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_by' => $actor->id])->save();
            } elseif ($credit->credit_note_mode !== 'RETURN') {
                throw ValidationException::withMessages(['credit_note_id' => 'Purchase Return ต้องผูก Credit Note mode RETURN']);
            }
            $credit = $credit->fresh();
            if ($credit->status === 'DRAFT') {
                $credit->forceFill(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_by' => $actor->id])->save();
                $credit->refresh();
            }
            if ($credit->status === 'APPROVED') {
                $credit = $this->documents->post($credit, $postingDate, $actor, $request);
            }
            $movement = $this->partialInventory->post($return, $actor, true);
            $this->partialInventory->linkCostJournal($return->fresh('creditNote'), $movement);
            $return->update(['status' => 'POSTED', 'posted_by' => $actor->id, 'posted_at' => now(), 'updated_by' => $actor->id]);

            return $return->fresh(['creditNote', 'lines']);
        }, 3);
    }

    private function assertFullLine(PurchaseReturn $return): void
    {
        if ($return->lines->count() !== 1 || ! $return->goodsReceipt || ! $return->purchase_document_id || ! $return->purchaseDocument) {
            throw ValidationException::withMessages(['lines' => 'Return posting MVP ต้องมีหนึ่ง line, Goods Receipt และ Invoice ต้นทาง']);
        }
        $line = $return->lines->sole();
        $receiptLine = $line->goodsReceiptLine;
        if (! $receiptLine || ! BigDecimal::of((string) $line->purchase_quantity)->isEqualTo(BigDecimal::of((string) $receiptLine->purchase_quantity))) {
            throw ValidationException::withMessages(['quantity' => 'Return posting MVP รองรับเฉพาะการคืนเต็มจำนวนของ GR line']);
        }
    }
}
