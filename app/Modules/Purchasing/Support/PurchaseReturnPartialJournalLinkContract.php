<?php

namespace App\Modules\Purchasing\Support;

use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

final class PurchaseReturnPartialJournalLinkContract
{
    public static function plan(array $source): array
    {
        foreach (['purchase_return_id', 'credit_note_id', 'journal_entry_id', 'allocation_id', 'journal_line_id'] as $field) {
            if ((int) ($source[$field] ?? 0) < 1) {
                throw ValidationException::withMessages([$field => 'Partial Return Journal linkage identity ไม่ครบ']);
            }
        }
        foreach (['return_status' => 'APPROVED', 'credit_status' => 'POSTED', 'journal_status' => 'POSTED', 'journal_event' => 'purchase_credit_note'] as $field => $expected) {
            if (($source[$field] ?? null) !== $expected) {
                throw ValidationException::withMessages([$field => "Partial Return Journal linkage ต้องมี {$field} = {$expected}"]);
            }
        }
        if ((int) ($source['credit_note_id'] ?? 0) !== (int) ($source['journal_source_id'] ?? -1)
            || (int) ($source['credit_warehouse_id'] ?? 0) !== (int) ($source['return_warehouse_id'] ?? -1)
            || (int) ($source['credit_supplier_id'] ?? 0) !== (int) ($source['return_supplier_id'] ?? -1)) {
            throw ValidationException::withMessages(['scope' => 'Partial Return Journal ต้องอ้าง Credit Note และ scope เดียวกัน']);
        }
        if ((int) ($source['allocation_account_id'] ?? 0) !== (int) ($source['journal_account_id'] ?? -1)
            || ! BigDecimal::of((string) ($source['allocation_value'] ?? '0'))->abs()->isEqualTo(BigDecimal::of((string) ($source['journal_line_credit'] ?? '0')))) {
            throw ValidationException::withMessages(['journal_line_id' => 'Partial Return Cost Allocation ต้องตรงกับ Credit Note Journal line']);
        }

        return [
            'idempotency_key' => sprintf('purchase-return:%d:partial-journal:%d', $source['purchase_return_id'], $source['journal_line_id']),
            'steps' => ['lock_partial_allocation', 'lock_credit_journal_line', 'assert_amount_and_scope', 'create_immutable_link'],
            'atomic' => true,
        ];
    }
}
