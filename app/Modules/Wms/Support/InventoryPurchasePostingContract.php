<?php

namespace App\Modules\Wms\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Wms\Models\PurchaseDocument;
use Illuminate\Validation\ValidationException;

/**
 * Read-only contract for the future inventory Purchase Invoice event.
 * It is intentionally not connected to PurchaseDocumentPostingService yet.
 */
final class InventoryPurchasePostingContract
{
    public const EVENT_CODE = 'supplier_invoice.inventory';

    public function assertReady(PurchaseDocument $document): void
    {
        if ((string) $document->document_type !== 'INVOICE') {
            throw ValidationException::withMessages(['document_type' => 'Inventory Purchase ต้องเป็น Invoice เท่านั้น']);
        }
        if ((string) $document->tax_treatment !== 'NONE_VAT') {
            throw ValidationException::withMessages(['tax_treatment' => 'Inventory Purchase foundation รอบนี้รองรับเฉพาะ NONE VAT']);
        }
        if ($document->rounding_amount !== null && (string) $document->rounding_amount !== '0.00') {
            throw ValidationException::withMessages(['rounding_amount' => 'Inventory Purchase ต้องไม่มี rounding amount']);
        }
        if ($document->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Inventory Purchase ต้องมีรายการสินค้า']);
        }

        foreach ($document->lines as $index => $line) {
            if (! $line->item_id || ! $line->uom_id || ! $line->item?->is_active || ! $line->item?->is_stock_item || ! $line->uom?->is_active) {
                throw ValidationException::withMessages(["lines.{$index}.item_id" => 'ทุกบรรทัด Inventory Purchase ต้องมี Item/UOM active และเป็น stock item']);
            }
            if (! $line->account_id) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => 'ทุกบรรทัด Inventory Purchase ต้องมีบัญชี Inventory ที่เลือกไว้']);
            }
            $this->inventoryAccount($line);
        }
    }

    public function payload(PurchaseDocument $document, Account $purchaseAp): array
    {
        $this->assertReady($document);
        if ($purchaseAp->control_account_type !== 'AP' || ! $purchaseAp->is_active || ! $purchaseAp->is_postable) {
            throw ValidationException::withMessages(['purchase_ap' => 'บัญชี PURCHASE_AP ต้อง active, postable และเป็นบัญชีคุม AP']);
        }

        $lines = $document->lines->map(function ($line): array {
            $inventory = $this->inventoryAccount($line);

            return [
                'account_id' => (int) $inventory->id,
                'subledger_type' => 'ITEM',
                'subledger_id' => (string) $line->item_id,
                'description' => self::lineToken($line).' '.(string) $line->description,
                'debit' => (string) $line->gross_amount,
                'credit' => '0.00',
            ];
        })->all();
        $lines[] = [
            'account_id' => (int) $purchaseAp->id,
            'subledger_type' => 'SUPPLIER',
            'subledger_id' => (string) $document->supplier_id,
            'description' => $document->document_number,
            'debit' => '0.00',
            'credit' => (string) $document->gross_amount,
        ];

        return [
            'source_type' => 'PURCHASING',
            'source_id' => (string) $document->id,
            'source_reference' => $document->document_number,
            'event_code' => self::EVENT_CODE,
            'entry_date' => $document->posting_date?->format('Y-m-d') ?: $document->document_date->format('Y-m-d'),
            'document_date' => $document->document_date->format('Y-m-d'),
            'description' => 'ใบตั้งหนี้สินค้า '.$document->document_number,
            'lines' => $lines,
        ];
    }

    public function atomicPlan(PurchaseDocument $document): array
    {
        $this->assertReady($document);

        return [
            'source_type' => 'PURCHASING',
            'source_id' => (string) $document->id,
            'event_code' => self::EVENT_CODE,
            'idempotency_key' => "purchase:{$document->id}:".self::EVENT_CODE.':revision:0',
            'lock_order' => ['purchase_document', 'journal_book', 'fiscal_period', 'stock_movement', 'cost_allocations', 'cost_layers', 'stock_balance'],
            'steps' => [
                'validate_inventory_accounts_and_source',
                'post_purchase_journal',
                'create_or_reuse_receipt_movement',
                'create_cost_allocation_and_layer',
                'reconcile_allocation_to_journal',
                'commit_all_or_rollback',
            ],
            'creates_journal' => false,
            'idempotency' => [
                'same_key_same_hash' => 'REUSE',
                'same_key_different_hash' => 'REJECT',
                'source_revision' => 0,
            ],
            'rollback_gates' => [
                'purchase_document_status', 'journal_identity', 'movement_source_identity',
                'allocation_journal_linkage', 'reconciliation_zero',
            ],
            'reconciliation_gates' => [
                'allocation_value_equals_inventory_journal',
                'movement_quantity_equals_allocation_quantity',
                'allocation_has_immutable_journal_line_link',
                'no_pending_or_unlinked_allocation',
            ],
            'schema_gaps' => [
                'purchase_document_line_item_uom' => false,
                'movement_source_identity' => false,
                'allocation_journal_entry_id' => false,
                'allocation_journal_line_linkage' => false,
                'receipt_line_reference' => false,
                'receipt_journal_id' => false,
            ],
        ];
    }

    public static function lineToken(mixed $line): string
    {
        $id = is_array($line) ? ($line['id'] ?? null) : ($line->id ?? null);

        return '[purchase-line:'.(int) $id.']';
    }

    private function inventoryAccount($line): Account
    {
        $account = $line->item?->inventoryAccount ?: $line->account;
        if (! $account || ! $account->is_active || ! $account->is_postable || $account->control_account_type !== 'INVENTORY') {
            throw ValidationException::withMessages(['account_id' => 'ต้องระบุบัญชี Inventory แบบ explicit จาก Item หรือบรรทัดเอกสาร และต้องเป็นบัญชีคุม INVENTORY']);
        }

        return $account;
    }
}
