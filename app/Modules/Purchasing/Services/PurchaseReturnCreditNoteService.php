<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Support\PurchaseDocumentCalculator;
use App\Modules\Purchasing\Support\PurchaseReturnPostingContract;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseReturnCreditNoteService
{
    public function __construct(private readonly DocumentSequenceService $sequences) {}

    public function preflight(PurchaseReturn $return): array
    {
        $return->loadMissing(['purchaseDocument', 'lines']);
        return PurchaseReturnPostingContract::plan([
            'purchase_return_id' => $return->id,
            'purchase_document_id' => $return->purchase_document_id,
            'return_status' => $return->status,
            'invoice_status' => $return->purchaseDocument?->status,
            'invoice_type' => $return->purchaseDocument?->document_type,
            'return_warehouse_id' => $return->warehouse_id,
            'invoice_warehouse_id' => $return->purchaseDocument?->warehouse_id,
            'return_supplier_id' => $return->supplier_id,
            'invoice_supplier_id' => $return->purchaseDocument?->supplier_id,
            'credit_note_id' => $return->credit_note_id,
            'gross_amount' => (string) $return->gross_amount,
        ]);
    }

    public function createDraft(PurchaseReturn $purchaseReturn, User $actor, Request $request): PurchaseDocument
    {
        return DB::transaction(function () use ($purchaseReturn, $actor, $request): PurchaseDocument {
            $return = PurchaseReturn::query()->with(['purchaseDocument.lines', 'lines.goodsReceiptLine'])->lockForUpdate()->findOrFail($purchaseReturn->id);
            $invoice = $return->purchaseDocument;
            $this->preflight($return);
            if ($return->gross_amount !== $return->subtotal || $return->tax_amount !== '0.00' || $invoice->tax_treatment !== 'NONE_VAT') {
                throw ValidationException::withMessages(['gross_amount' => 'ยอด Purchase Return ต้องเป็น NONE VAT และยอดรวมต้องตรงกัน']);
            }
            $invoiceLines = $invoice->lines->keyBy('id');
            $lineInputs = [];
            foreach ($return->lines as $returnLine) {
                $invoiceLine = $invoiceLines->get($returnLine->purchase_document_line_id);
                if (! $invoiceLine || (int) $invoiceLine->item_id !== (int) $returnLine->item_id || (int) $invoiceLine->uom_id !== (int) $returnLine->purchase_uom_id) {
                    throw ValidationException::withMessages(['lines' => 'Purchase Return line ต้องผูกกับ Invoice line ที่ตรงกัน']);
                }
                $quantity = (string) $returnLine->purchase_quantity;
                $unitPrice = (string) BigDecimal::of((string) $invoiceLine->unit_price)->toScale(4, RoundingMode::HALF_UP);
                $lineInputs[] = [
                    'description' => $invoiceLine->description,
                    'item_id' => $invoiceLine->item_id, 'uom_id' => $invoiceLine->uom_id,
                    'purchase_order_line_id' => $invoiceLine->purchase_order_line_id,
                    'account_id' => $invoiceLine->account_id, 'tax_code_id' => null,
                    'quantity' => $quantity, 'unit_price' => $unitPrice,
                    'discount_amount' => '0.00',
                    'receipt_allocations' => [['goods_receipt_line_id' => $returnLine->goods_receipt_line_id, 'allocated_quantity' => $quantity]],
                ];
            }
            $calculation = PurchaseDocumentCalculator::calculate($lineInputs, 'NONE_VAT', false, 2, 2);
            if ($calculation['gross_amount'] !== $return->gross_amount) {
                throw ValidationException::withMessages(['gross_amount' => 'ยอด Credit Note ที่คำนวณได้ไม่ตรงกับ Purchase Return']);
            }
            $date = CarbonImmutable::parse($return->return_date);
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PURCHASE_CREDIT_NOTE')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_type' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร Credit Note']);
            }
            $warehouse = $invoice->warehouse()->with('branch')->first();
            if (! $warehouse?->branch) {
                throw ValidationException::withMessages(['warehouse_id' => 'คลังของ Invoice ไม่มีสาขา']);
            }
            $number = $this->sequences->issueForBranch($sequence, $warehouse->branch, $date);
            $credit = PurchaseDocument::query()->create([
                'warehouse_id' => $return->warehouse_id, 'branch_id' => $return->branch_id,
                'document_type' => 'CREDIT_NOTE', 'credit_note_mode' => 'RETURN', 'original_document_id' => $invoice->id,
                'document_number' => $number, 'document_date' => $return->return_date,
                'supplier_id' => $invoice->supplier_id, 'supplier_code' => $invoice->supplier_code,
                'supplier_name' => $invoice->supplier_name, 'supplier_tax_id' => $invoice->supplier_tax_id,
                'supplier_branch_code' => $invoice->supplier_branch_code, 'supplier_address' => $invoice->supplier_address,
                'tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false, 'tax_decimal_places' => 2,
                'subtotal' => $calculation['subtotal'], 'tax_amount' => $calculation['tax_amount'], 'gross_amount' => $calculation['gross_amount'],
                'rounding_amount' => '0.00', 'status' => 'DRAFT', 'description' => 'จาก Purchase Return '.$return->return_number,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            foreach ($lineInputs as $index => $line) {
                $allocations = $line['receipt_allocations']; unset($line['receipt_allocations']);
                $saved = $credit->lines()->create([...$line, 'line_number' => $index + 1, 'net_amount' => $calculation['lines'][$index]['net_amount'], 'tax_amount' => '0.00', 'gross_amount' => $calculation['lines'][$index]['gross_amount']]);
                $saved->receiptAllocations()->create(['goods_receipt_line_id' => $allocations[0]['goods_receipt_line_id'], 'allocated_quantity' => $allocations[0]['allocated_quantity'], 'allocated_amount' => $saved->gross_amount, 'idempotency_key' => 'pdl-'.$saved->id.'-grl-'.$allocations[0]['goods_receipt_line_id']]);
            }
            $this->sequences->recordIssued($sequence, $number, 'purchase_documents', $credit->id, $date, $actor->id);
            $return->update(['credit_note_id' => $credit->id, 'updated_by' => $actor->id]);
            return $credit->fresh('lines.receiptAllocations');
        }, 3);
    }
}
