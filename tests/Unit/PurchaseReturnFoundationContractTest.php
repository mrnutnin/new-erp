<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PurchaseReturnFoundationContractTest extends TestCase
{
    public function test_return_schema_keeps_source_linkage_snapshot_and_safe_state_contract(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_04_000000_create_purchasing_purchase_returns.php'));
        $model = file_get_contents(base_path('app/Modules/Purchasing/Models/PurchaseReturn.php'));
        $line = file_get_contents(base_path('app/Modules/Purchasing/Models/PurchaseReturnLine.php'));

        foreach (['purchase_document_id', 'goods_receipt_id', 'credit_note_id', 'idempotency_key', 'return_date', "['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'VOID']", 'purchase_returns_number_uq'] as $contract) {
            self::assertStringContainsString($contract, $migration);
        }
        foreach (['goods_receipt_line_id', 'purchase_document_line_id', 'purchase_quantity', 'stock_quantity', 'total_cost', 'source_snapshot', 'purchase_return_lines_source_uq'] as $contract) {
            self::assertStringContainsString($contract, $migration);
        }
        self::assertStringContainsString('return $this->hasMany(PurchaseReturnLine::class)', $model);
        self::assertStringContainsString('return $this->belongsTo(GoodsReceiptLine::class)', $line);
        self::assertStringContainsString('return $this->belongsTo(PurchaseDocumentLine::class)', $line);
    }
}
