<?php

namespace App\Modules\Wms\Support;

use Illuminate\Validation\ValidationException;

/** Pure plan for immutable reversal of a Posted Inventory Purchase chain. */
final class InventoryPurchaseReversalContract
{
    /** Multi-line reversal stays closed until per-line allocation/linkage resolver exists. */
    public static function assertSingleLine(int $movementCount, int $allocationCount): void
    {
        if ($movementCount !== 1 || $allocationCount !== 1) {
            throw ValidationException::withMessages(['reversal' => 'Inventory Purchase หลายบรรทัดยังไม่เปิดใช้งาน ต้องมี Movement และ Cost Allocation เพียงหนึ่งรายการ']);
        }
    }

    public static function plan(array $source, array $request): array
    {
        foreach (['document_id', 'journal_id', 'movement_id', 'allocation_id'] as $field) {
            if (! isset($source[$field]) || (int) $source[$field] < 1) {
                throw ValidationException::withMessages([$field => 'Reversal source identity ไม่ครบ']);
            }
        }
        if (! isset($source['revision']) || (int) $source['revision'] < 0) {
            throw ValidationException::withMessages(['revision' => 'Reversal revision ไม่ถูกต้อง']);
        }
        if (($source['document_status'] ?? null) !== 'POSTED' || ($source['movement_status'] ?? null) !== 'POSTED') {
            throw ValidationException::withMessages(['status' => 'Reversal ทำได้เฉพาะเอกสารและ Movement ที่ POSTED']);
        }
        if (($source['allocation_status'] ?? null) === 'REVERSED') {
            throw ValidationException::withMessages(['allocation_status' => 'Allocation นี้ถูก Reversal แล้ว']);
        }
        if (trim((string) ($request['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => 'ต้องระบุเหตุผลการ Reversal']);
        }

        $revision = (int) $source['revision'] + 1;
        $key = sprintf('reversal:purchase:%d:movement:%d:revision:%d', $source['document_id'], $source['movement_id'], $revision);
        $payload = [
            'source' => [
                'document_id' => (int) $source['document_id'], 'journal_id' => (int) $source['journal_id'],
                'movement_id' => (int) $source['movement_id'], 'allocation_id' => (int) $source['allocation_id'],
                'revision' => (int) $source['revision'],
            ],
            'reversal' => ['reason' => trim((string) $request['reason']), 'date' => (string) ($request['date'] ?? '')],
            'revision' => $revision,
        ];

        return [
            'idempotency_key' => $key,
            'payload_hash' => hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
            'revision' => $revision,
            'lock_order' => ['purchase_document', 'journal_entry', 'stock_movement', 'cost_allocation', 'journal_line_linkage'],
            'steps' => ['validate_posted_source', 'create_reversal_journal', 'create_reversal_movement', 'create_reversal_allocation', 'create_reversal_linkage', 'reconcile_zero', 'commit_or_rollback'],
            'immutability' => ['source_document' => 'NO_UPDATE', 'source_journal' => 'NO_UPDATE', 'source_movement' => 'NO_UPDATE', 'source_allocation' => 'NO_UPDATE', 'reversal' => 'NEW_REVISION'],
            'reconciliation_impact' => ['journal_delta' => 'REVERSE_SOURCE', 'stock_delta' => 'REVERSE_SOURCE', 'allocation_delta' => 'REVERSE_SOURCE', 'source_revision_remains' => true],
            'idempotency' => ['same_key_same_hash' => 'REUSE', 'same_key_different_hash' => 'REJECT'],
        ];
    }
}
