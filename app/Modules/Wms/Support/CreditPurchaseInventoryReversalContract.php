<?php

namespace App\Modules\Wms\Support;

use Illuminate\Validation\ValidationException;

/**
 * Bounded full-line Credit Purchase -> GR inventory reversal plan.
 * This is a pure contract: it creates no rows and is closed until a caller
 * supplies one atomic transaction implementation.
 */
final class CreditPurchaseInventoryReversalContract
{
    public static function plan(array $source, array $request): array
    {
        foreach (['credit_document_id', 'original_document_id', 'credit_journal_id', 'movement_id', 'allocation_id', 'credit_journal_line_id'] as $field) {
            if (! isset($source[$field]) || (int) $source[$field] < 1) {
                throw ValidationException::withMessages([$field => 'Credit Purchase reversal source identity ไม่ครบ']);
            }
        }
        foreach (['credit_document_status' => 'POSTED', 'credit_document_type' => 'CREDIT_NOTE', 'original_document_status' => 'POSTED', 'movement_status' => 'POSTED', 'allocation_status' => 'POSTED', 'allocation_cost_status' => 'FINAL', 'credit_journal_status' => 'POSTED'] as $field => $expected) {
            if (($source[$field] ?? null) !== $expected) {
                throw ValidationException::withMessages([$field => "Credit Purchase reversal ต้องมี {$field} = {$expected}"]);
            }
        }
        if ((int) ($source['credit_warehouse_id'] ?? 0) !== (int) ($source['original_warehouse_id'] ?? -1)
            || (int) ($source['credit_warehouse_id'] ?? 0) !== (int) ($source['movement_warehouse_id'] ?? -2)
            || (int) ($source['credit_supplier_id'] ?? 0) !== (int) ($source['original_supplier_id'] ?? -1)) {
            throw ValidationException::withMessages(['scope' => 'Credit Purchase, Invoice ต้นทาง และ Movement ต้องอยู่ Supplier/Warehouse เดียวกัน']);
        }
        if ((int) ($source['credit_receipt_line_id'] ?? 0) < 1 || (int) ($source['original_receipt_line_id'] ?? 0) < 1
            || (int) $source['credit_receipt_line_id'] !== (int) $source['original_receipt_line_id']) {
            throw ValidationException::withMessages(['goods_receipt_line_id' => 'Credit Purchase ต้องอ้าง Goods Receipt line เดียวกับ Invoice ต้นทาง']);
        }
        if ((string) ($source['allocation_status'] ?? '') === 'REVERSED') {
            throw ValidationException::withMessages(['allocation_status' => 'Cost Allocation ต้นทางถูกกลับรายการแล้ว']);
        }
        if ((string) ($request['reason'] ?? '') === '') {
            throw ValidationException::withMessages(['reason' => 'ต้องระบุเหตุผลการกลับรายการจาก Credit Purchase']);
        }

        $revision = (int) ($source['revision'] ?? 0) + 1;
        $payload = [
            'source' => [
                'credit_document_id' => (int) $source['credit_document_id'],
                'original_document_id' => (int) $source['original_document_id'],
                'credit_journal_id' => (int) $source['credit_journal_id'],
                'movement_id' => (int) $source['movement_id'],
                'allocation_id' => (int) $source['allocation_id'],
                'credit_journal_line_id' => (int) $source['credit_journal_line_id'],
                'revision' => (int) ($source['revision'] ?? 0),
            ],
            'reversal' => ['reason' => trim((string) $request['reason']), 'date' => (string) ($request['date'] ?? '')],
            'revision' => $revision,
        ];

        return [
            'idempotency_key' => sprintf('reversal:credit-purchase:%d:movement:%d:revision:%d', $source['credit_document_id'], $source['movement_id'], $revision),
            'payload_hash' => hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
            'revision' => $revision,
            'steps' => ['lock_credit_and_original', 'validate_full_gr_line', 'create_reversal_movement', 'create_reversal_allocation', 'link_credit_journal_line', 'reconcile_zero', 'commit_or_rollback'],
            'immutability' => ['credit_document' => 'NO_UPDATE', 'original_document' => 'NO_UPDATE', 'original_journal' => 'NO_UPDATE', 'original_movement' => 'NO_UPDATE', 'original_allocation' => 'NO_UPDATE', 'reversal' => 'NEW_REVISION'],
            'idempotency' => ['same_key_same_hash' => 'REUSE', 'same_key_different_hash' => 'REJECT'],
            'journal_linkage' => 'credit_journal_line',
            'reconciliation_required' => true,
        ];
    }
}
