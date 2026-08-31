<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Services\CreditPurchaseInventoryReversalAdapter;
use App\Modules\Wms\Services\InventoryPurchaseProductionAdapter;
use App\Modules\Wms\Services\InventoryReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InventoryPurchaseIntegrationFixture;
use Tests\TestCase;

/** Opt-in, rollback-only local MySQL evidence for Gate 2. */
final class CreditPurchaseInventoryMySqlIntegrationReadinessTest extends TestCase
{
    public function test_persistent_credit_purchase_gr_reversal_operational_evidence(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_OPERATIONAL') !== '1') {
            $this->markTestSkipped('ต้องรัน persistent operational evidence ด้วย ERP_RUN_MYSQL_OPERATIONAL=1 เท่านั้น');
        }
        $actor = User::query()->first();
        if (! $actor) {
            $this->markTestSkipped('ต้องมี User ใน local MySQL');
        }
        InventoryPurchaseIntegrationFixture::assertReady();
        $previous = config('erp.inventory.purchase_posting_enabled');
        DB::beginTransaction();
        try {
            $invoice = InventoryPurchaseIntegrationFixture::createApprovedPurchase($actor);
            config(['erp.inventory.purchase_posting_enabled' => true]);
            $warehouse = $invoice->warehouse()->firstOrFail();
            $invoice = app(InventoryPurchaseProductionAdapter::class)->post($invoice, $warehouse, $actor, null, true);
            $credit = $this->createPostedCredit($invoice, $actor, 'CN-OPS-GATE2-20260824-');
            $adapter = app(CreditPurchaseInventoryReversalAdapter::class);
            $reason = 'Persistent Gate 2 operational evidence';
            $reversed = $adapter->reverse($credit, $credit->document_date->format('Y-m-d'), $reason, $actor, true);
            $movement = DB::table('wms_stock_movements')->find($reversed->inventory_reversal_movement_id);
            $allocation = DB::table('wms_cost_allocations')->find($reversed->inventory_reversal_allocation_id);
            $totals = app(InventoryReconciliationService::class)->totals($credit->document_date->format('Y-m-d'), (int) $credit->warehouse_id, (int) $movement->item_id);
            $this->assertSame('REVERSED', $reversed->reversal_status);
            $this->assertSame('OUT', $movement->direction);
            $this->assertSame('POSTED', $allocation->status);
            $this->assertSame((int) $allocation->parent_allocation_id, (int) DB::table('wms_cost_allocations')->where('stock_movement_id', $movement->source_id)->value('id'));
            $this->assertSame(1, DB::table('wms_cost_allocation_journal_lines')->where('allocation_id', $allocation->id)->count());
            $this->assertSame('ตรงกัน', $totals['status']);
            $this->assertSame('0.00000000', (string) $totals['allocation_vs_gl_difference']);
            $this->assertSame('0.00000000', (string) $totals['balance_vs_allocation_difference']);
            $retry = $adapter->reverse($reversed, $credit->document_date->format('Y-m-d'), $reason, $actor, true);
            $this->assertSame((int) $reversed->inventory_reversal_movement_id, (int) $retry->inventory_reversal_movement_id);
            $this->assertSame((int) $reversed->inventory_reversal_allocation_id, (int) $retry->inventory_reversal_allocation_id);
            DB::commit();
            fwrite(STDOUT, PHP_EOL.json_encode([
                'prefix' => 'CN-OPS-GATE2-20260824-', 'invoice_id' => $invoice->id, 'credit_document_id' => $credit->id,
                'credit_journal_id' => $credit->journal_entry_id, 'reversal_movement_id' => $reversed->inventory_reversal_movement_id,
                'reversal_allocation_id' => $reversed->inventory_reversal_allocation_id,
                'credit_journal_link_count' => DB::table('wms_cost_allocation_journal_lines')->where('allocation_id', $reversed->inventory_reversal_allocation_id)->count(),
                'reconciliation' => ['status' => $totals['status'], 'allocation_vs_gl_difference' => $totals['allocation_vs_gl_difference'], 'balance_vs_allocation_difference' => $totals['balance_vs_allocation_difference']],
                'persistent' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        } finally {
            config(['erp.inventory.purchase_posting_enabled' => $previous]);
        }
    }

    public function test_credit_purchase_gr_reversal_is_atomic_idempotent_and_rollback_safe(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        $actor = User::query()->first();
        if (! $actor) {
            $this->markTestSkipped('ต้องมี User ใน local MySQL');
        }
        InventoryPurchaseIntegrationFixture::assertReady();
        $before = $this->counts();
        $previous = config('erp.inventory.purchase_posting_enabled');
        DB::beginTransaction();
        try {
            $invoice = InventoryPurchaseIntegrationFixture::createApprovedPurchase($actor);
            config(['erp.inventory.purchase_posting_enabled' => true]);
            $warehouse = $invoice->warehouse()->firstOrFail();
            $invoice = app(InventoryPurchaseProductionAdapter::class)->post($invoice, $warehouse, $actor, null, true);
            $credit = $this->createPostedCredit($invoice, $actor);
            $sourceMovement = DB::table('wms_stock_movements')->where('source_type', 'PURCHASING')->where('source_id', (string) $invoice->id)->where('status', 'POSTED')->sole();
            $sourceAllocation = DB::table('wms_cost_allocations')->where('stock_movement_id', $sourceMovement->id)->where('status', 'POSTED')->where('cost_status', 'FINAL')->sole();
            $adapter = app(CreditPurchaseInventoryReversalAdapter::class);
            $reversed = $adapter->reverse($credit, $credit->document_date->format('Y-m-d'), 'Dedicated Gate 2 rollback evidence', $actor, true);
            $this->assertSame('REVERSED', $reversed->reversal_status);
            $this->assertNotNull($reversed->inventory_reversal_movement_id);
            $this->assertNotNull($reversed->inventory_reversal_allocation_id);
            $movement = DB::table('wms_stock_movements')->find($reversed->inventory_reversal_movement_id);
            $allocation = DB::table('wms_cost_allocations')->find($reversed->inventory_reversal_allocation_id);
            $this->assertSame('OUT', $movement->direction);
            $this->assertSame((int) $sourceMovement->id, (int) $movement->source_id);
            $this->assertSame((int) $sourceAllocation->id, (int) $allocation->parent_allocation_id);
            $this->assertSame(1, DB::table('wms_cost_allocation_journal_lines')->where('allocation_id', $allocation->id)->count());
            $totals = app(InventoryReconciliationService::class)->totals($credit->document_date->format('Y-m-d'), (int) $credit->warehouse_id, (int) $movement->item_id);
            $this->assertSame('ตรงกัน', $totals['status']);
            $afterFirst = $this->counts();
            $retry = $adapter->reverse($reversed, $credit->document_date->format('Y-m-d'), 'Dedicated Gate 2 rollback evidence', $actor, true);
            $this->assertSame((int) $reversed->inventory_reversal_movement_id, (int) $retry->inventory_reversal_movement_id);
            $this->assertSame($afterFirst, $this->counts());
        } finally {
            DB::rollBack();
            config(['erp.inventory.purchase_posting_enabled' => $previous]);
        }
        $this->assertSame($before, $this->counts());
    }

    private function createPostedCredit(PurchaseDocument $invoice, User $actor, ?string $prefix = null): PurchaseDocument
    {
        $invoice->load('lines.receiptAllocations');
        $line = $invoice->lines->sole();
        $allocation = $line->receiptAllocations->sole();
        $suffix = strtoupper(Str::random(12));
        $credit = PurchaseDocument::query()->create([
            'warehouse_id' => $invoice->warehouse_id, 'document_type' => 'CREDIT_NOTE', 'original_document_id' => $invoice->id,
            'document_number' => ($prefix ?? 'CN-GATE2-').$suffix, 'document_date' => $invoice->document_date, 'posting_date' => $invoice->posting_date,
            'supplier_id' => $invoice->supplier_id, 'supplier_code' => $invoice->supplier_code, 'supplier_name' => $invoice->supplier_name,
            'tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false, 'tax_decimal_places' => 2, 'subtotal' => $invoice->subtotal,
            'tax_amount' => 0, 'withholding_rate' => 0, 'withholding_base' => 0, 'withholding_amount' => 0, 'gross_amount' => $invoice->gross_amount,
            'rounding_amount' => 0, 'status' => 'APPROVED', 'reversal_status' => 'NONE', 'reversal_revision' => 0, 'description' => 'Gate 2 rollback fixture',
            'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $creditLine = $credit->lines()->create($line->only(['line_number', 'description', 'item_id', 'uom_id', 'purchase_order_line_id', 'account_id', 'tax_rate', 'tax_base', 'quantity', 'unit_price', 'discount_amount', 'net_amount', 'tax_amount', 'gross_amount']));
        $creditLine->receiptAllocations()->create(['goods_receipt_line_id' => $allocation->goods_receipt_line_id, 'allocated_quantity' => $allocation->allocated_quantity, 'allocated_amount' => $allocation->allocated_amount, 'idempotency_key' => 'fixture:credit-gate2:'.$credit->id]);
        $originalJournal = $invoice->journalEntry()->with('lines')->firstOrFail();
        $journal = app(JournalPostingService::class)->post([
            'source_type' => 'PURCHASING', 'source_id' => (string) $credit->id, 'source_reference' => $credit->document_number,
            'event_code' => 'purchase_credit_note', 'entry_date' => $credit->document_date->format('Y-m-d'), 'document_date' => $credit->document_date->format('Y-m-d'),
            'description' => 'Gate 2 Credit Purchase '.$credit->document_number,
            'lines' => $originalJournal->lines->map(fn ($journalLine): array => [
                'account_id' => $journalLine->account_id, 'subledger_type' => $journalLine->subledger_type, 'subledger_id' => $journalLine->subledger_id,
                'description' => 'Gate 2 reversal line', 'debit' => $journalLine->credit, 'credit' => $journalLine->debit,
            ])->all(),
        ], $invoice->warehouse, $actor);

        return $credit->forceFill(['status' => 'POSTED', 'journal_entry_id' => $journal->id, 'posting_date' => $journal->entry_date, 'posted_by' => $actor->id, 'posted_at' => now()])->save() ? $credit->fresh('lines.receiptAllocations') : $credit;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return ['documents' => DB::table('purchase_documents')->count(), 'journals' => DB::table('journal_entries')->count(), 'movements' => DB::table('wms_stock_movements')->count(), 'allocations' => DB::table('wms_cost_allocations')->count(), 'links' => DB::table('wms_cost_allocation_journal_lines')->count()];
    }
}
