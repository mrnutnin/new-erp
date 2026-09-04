<?php

namespace App\Modules\Purchasing\Support;

use Illuminate\Validation\ValidationException;

final class PurchaseReturnWmsPostingContract
{
    public static function plan(array $source): array
    {
        foreach (['purchase_return_id', 'credit_note_id', 'credit_document_id'] as $field) {
            if ((int) ($source[$field] ?? 0) < 1) {
                throw ValidationException::withMessages([$field => 'Return posting ต้องมี Purchase Return และ Credit Note linkage']);
            }
        }
        foreach (['return_status' => 'APPROVED', 'credit_status' => 'POSTED', 'credit_mode' => 'RETURN'] as $field => $expected) {
            if (($source[$field] ?? null) !== $expected) {
                throw ValidationException::withMessages([$field => "Return posting ต้องมี {$field} = {$expected}"]);
            }
        }
        if ((int) ($source['return_warehouse_id'] ?? 0) !== (int) ($source['credit_warehouse_id'] ?? -1)
            || (int) ($source['return_supplier_id'] ?? 0) !== (int) ($source['credit_supplier_id'] ?? -1)) {
            throw ValidationException::withMessages(['scope' => 'Return, Credit Note และ Stock ต้องอยู่ Supplier/Warehouse เดียวกัน']);
        }
        if (($source['full_line'] ?? false) !== true) {
            throw ValidationException::withMessages(['quantity' => 'WMS Return posting MVP รองรับเฉพาะการคืนเต็ม line; Partial Return รอ movement contract ใหม่']);
        }

        return [
            'idempotency_key' => sprintf('purchase-return:%d:wms-post', $source['purchase_return_id']),
            'steps' => ['lock_return_and_credit', 'assert_full_line', 'post_credit_inventory_reversal', 'mark_return_posted'],
            'side_effects' => ['stock_movement' => 'OUT', 'cost_allocation' => 'REVERSAL', 'credit_note' => 'POSTED'],
            'atomic' => true,
        ];
    }
}
