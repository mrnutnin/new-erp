<?php

namespace App\Modules\Purchasing\Support;

use Illuminate\Validation\ValidationException;

final class PurchaseReturnPostingContract
{
    public static function plan(array $source): array
    {
        foreach (['purchase_return_id', 'purchase_document_id'] as $field) {
            if ((int) ($source[$field] ?? 0) < 1) {
                throw ValidationException::withMessages([$field => 'Purchase Return ต้องมี source identity ก่อนสร้าง Credit Note']);
            }
        }
        foreach (['return_status' => 'APPROVED', 'invoice_status' => 'POSTED', 'invoice_type' => 'INVOICE'] as $field => $expected) {
            if (($source[$field] ?? null) !== $expected) {
                throw ValidationException::withMessages([$field => "Purchase Return posting ต้องมี {$field} = {$expected}"]);
            }
        }
        if ((int) ($source['return_warehouse_id'] ?? 0) !== (int) ($source['invoice_warehouse_id'] ?? -1)
            || (int) ($source['return_supplier_id'] ?? 0) !== (int) ($source['invoice_supplier_id'] ?? -1)) {
            throw ValidationException::withMessages(['scope' => 'Purchase Return และ Invoice ต้องอยู่ Supplier/Warehouse เดียวกัน']);
        }
        if ((int) ($source['credit_note_id'] ?? 0) > 0) {
            throw ValidationException::withMessages(['credit_note_id' => 'Purchase Return ผูก Credit Note แล้ว']);
        }
        if (($source['gross_amount'] ?? '0.00') === '0.00') {
            throw ValidationException::withMessages(['gross_amount' => 'Purchase Return ต้องมียอดมากกว่า 0']);
        }

        $payload = [
            'purchase_return_id' => (int) $source['purchase_return_id'],
            'purchase_document_id' => (int) $source['purchase_document_id'],
            'gross_amount' => (string) $source['gross_amount'],
        ];

        return [
            'idempotency_key' => sprintf('purchase-return:%d:credit-note', $source['purchase_return_id']),
            'payload_hash' => hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
            'steps' => ['lock_return_and_invoice', 'create_credit_note_draft', 'link_credit_note', 'caller_posts_credit_note'],
            'immutability' => ['purchase_return' => 'LINK_ONLY', 'invoice' => 'NO_UPDATE'],
        ];
    }
}
