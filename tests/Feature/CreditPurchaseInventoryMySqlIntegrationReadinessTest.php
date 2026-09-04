<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Services\PurchaseReturnPostingService;
use App\Modules\Purchasing\Services\PurchaseReturnService;
use App\Modules\Purchasing\Services\PurchaseReturnCreditNoteService;
use App\Modules\Wms\Services\PurchaseReturnPartialInventoryAdapter;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Support\PaymentDueDate;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Wms\Services\CreditPurchaseInventoryReversalAdapter;
use App\Modules\Wms\Services\InventoryPurchaseProductionAdapter;
use App\Modules\Wms\Services\InventoryReconciliationService;
use App\Modules\Wms\Services\PurchaseDocumentPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function test_non_return_credit_note_cannot_create_stock_or_cost_reversal(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
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
            $credit = $this->createPostedCredit($invoice, $actor);
            $credit->forceFill(['credit_note_mode' => 'NON_RETURN'])->save();
            $credit = $credit->fresh('lines.receiptAllocations');
            $before = $this->counts();
            try {
                app(CreditPurchaseInventoryReversalAdapter::class)->reverse($credit, $credit->document_date->format('Y-m-d'), 'Non-return must remain financial only', $actor, true);
                self::fail('NON_RETURN Credit Note ต้องถูกปฏิเสธก่อนสร้าง Stock/Cost reversal');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
            $this->assertSame($before, $this->counts());
        } finally {
            DB::rollBack();
            config(['erp.inventory.purchase_posting_enabled' => $previous]);
        }
    }

    public function test_non_return_credit_note_posts_ap_without_stock_or_cost_side_effects(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        $actor = User::query()->first();
        if (! $actor) {
            $this->markTestSkipped('ต้องมี User ใน local MySQL');
        }
        InventoryPurchaseIntegrationFixture::assertReady();
        $previous = config('erp.inventory.purchase_posting_enabled');
        DB::beginTransaction();
        try {
            $inventoryFixture = InventoryPurchaseIntegrationFixture::createApprovedPurchase($actor);
            $term = PaymentTerm::query()->where('is_active', true)->firstOrFail();
            $expenseAccount = Account::query()->whereNull('control_account_type')->where('is_active', true)->where('is_postable', true)->whereHas('type', fn ($query) => $query->where('code', 'EXPENSE'))->firstOrFail();
            $invoice = PurchaseDocument::query()->create([
                'warehouse_id' => $inventoryFixture->warehouse_id, 'branch_id' => $inventoryFixture->branch_id, 'document_type' => 'INVOICE',
                'document_number' => 'PI-NONRETURN-'.strtoupper(Str::random(12)), 'document_date' => $inventoryFixture->document_date,
                'supplier_id' => $inventoryFixture->supplier_id, 'supplier_code' => $inventoryFixture->supplier_code, 'supplier_name' => $inventoryFixture->supplier_name,
                'tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false, 'tax_decimal_places' => 2, 'subtotal' => '1000.00', 'tax_amount' => '0.00',
                'withholding_rate' => '0.0000', 'withholding_base' => '0.00', 'withholding_amount' => '0.00', 'gross_amount' => '1000.00', 'rounding_amount' => '0.00',
                'payment_term_id' => $term->id, 'due_date' => PaymentDueDate::calculate($inventoryFixture->document_date->format('Y-m-d'), $term->due_rule, $term->credit_days),
                'status' => 'APPROVED', 'reversal_status' => 'NONE', 'reversal_revision' => 0, 'description' => 'Non-return integration source invoice',
                'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $invoiceLine = $invoice->lines()->create(['line_number' => 1, 'description' => 'Commercial adjustment source', 'account_id' => $expenseAccount->id, 'quantity' => 1, 'unit_price' => 1000, 'discount_amount' => 0, 'net_amount' => 1000, 'tax_amount' => 0, 'gross_amount' => 1000]);
            $request = Request::create('/purchasing/purchase-documents/'.$invoice->id.'/post', 'POST', ['posting_date' => $invoice->document_date->format('Y-m-d')]);
            $request->setUserResolver(fn (): User => $actor);
            $invoice = app(PurchaseDocumentPostingService::class)->post($invoice, $invoice->document_date->format('Y-m-d'), $actor, $request);
            $credit = PurchaseDocument::query()->create([
                'warehouse_id' => $invoice->warehouse_id, 'branch_id' => $invoice->branch_id, 'document_type' => 'CREDIT_NOTE', 'credit_note_mode' => 'NON_RETURN',
                'original_document_id' => $invoice->id, 'document_number' => 'CN-NONRETURN-'.strtoupper(Str::random(12)), 'document_date' => $invoice->document_date,
                'supplier_id' => $invoice->supplier_id, 'supplier_code' => $invoice->supplier_code, 'supplier_name' => $invoice->supplier_name,
                'tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false, 'tax_decimal_places' => 2, 'subtotal' => $invoiceLine->gross_amount,
                'tax_amount' => '0.00', 'withholding_rate' => '0.0000', 'withholding_base' => '0.00', 'withholding_amount' => '0.00',
                'gross_amount' => $invoiceLine->gross_amount, 'rounding_amount' => '0.00', 'status' => 'APPROVED', 'reversal_status' => 'NONE', 'reversal_revision' => 0,
                'description' => 'Non-return commercial adjustment', 'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $credit->lines()->create($invoiceLine->only(['line_number', 'description', 'account_id', 'quantity', 'unit_price', 'discount_amount', 'net_amount', 'tax_amount', 'gross_amount']));
            $before = $this->counts();
            $creditRequest = Request::create('/purchasing/purchase-documents/'.$credit->id.'/post', 'POST', ['posting_date' => $credit->document_date->format('Y-m-d')]);
            $creditRequest->setUserResolver(fn (): User => $actor);
            $posted = app(PurchaseDocumentPostingService::class)->post($credit, $credit->document_date->format('Y-m-d'), $actor, $creditRequest);
            $after = $this->counts();
            self::assertSame('POSTED', $posted->status);
            self::assertNotNull($posted->journal_entry_id);
            self::assertSame($before['movements'], $after['movements']);
            self::assertSame($before['allocations'], $after['allocations']);
            self::assertSame(1, DB::table('finance_open_items')->where('document_number', $posted->document_number)->where('document_type', 'CREDIT_NOTE')->count());
        } finally {
            DB::rollBack();
            config(['erp.inventory.purchase_posting_enabled' => $previous]);
        }
    }

    public function test_full_purchase_return_posts_credit_note_and_wms_reversal_atomically(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
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
            $invoice->load('lines.receiptAllocations.goodsReceiptLine.goodsReceipt');
            $apLine = JournalEntryLine::query()->where('journal_entry_id', $invoice->journal_entry_id)->where('subledger_type', 'SUPPLIER')->where('credit', $invoice->gross_amount)->firstOrFail();
            app(OpenItemService::class)->recordFromJournalLine($apLine, ['document_type' => 'INVOICE', 'document_number' => $invoice->document_number, 'due_date' => $invoice->document_date->format('Y-m-d'), 'withholding_rate' => '0.0000', 'withholding_base' => '0.00', 'withholding_amount' => '0.00']);
            $sourceAllocation = $invoice->lines->sole()->receiptAllocations->sole();
            $receipt = $sourceAllocation->goodsReceiptLine->goodsReceipt;
            DocumentSequence::query()->firstOrCreate(['warehouse_id' => null, 'document_type' => 'PURCHASE_RETURN'], ['name' => 'Purchase Return Integration', 'prefix' => 'PRT', 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'created_by' => $actor->id]);
            $request = Request::create('/purchasing/returns', 'POST');
            $request->setUserResolver(fn (): User => $actor);
            $return = app(PurchaseReturnService::class)->createDraft([
                'goods_receipt_id' => $receipt->id, 'purchase_document_id' => $invoice->id, 'return_date' => $receipt->business_date->format('Y-m-d'),
                'reason' => 'Supplier return damaged goods', 'idempotency_key' => 'integration:return:'.strtoupper(Str::random(12)),
                'lines' => [['goods_receipt_line_id' => $sourceAllocation->goods_receipt_line_id, 'purchase_quantity' => $sourceAllocation->allocated_quantity]],
            ], $actor, $request);
            $return = app(PurchaseReturnService::class)->approve(app(PurchaseReturnService::class)->submit($return, $actor), $actor);
            $before = $this->counts();
            $posted = app(PurchaseReturnPostingService::class)->post($return, $return->return_date->format('Y-m-d'), $actor, $request, true);
            $after = $this->counts();
            $posted->load('creditNote');
            self::assertSame('POSTED', $posted->status);
            self::assertSame('POSTED', $posted->creditNote->status);
            self::assertSame('RETURN', $posted->creditNote->credit_note_mode);
            self::assertSame($before['movements'] + 1, $after['movements']);
            self::assertSame($before['allocations'] + 1, $after['allocations']);
            self::assertSame(1, DB::table('finance_open_items')->where('document_number', $posted->creditNote->document_number)->where('document_type', 'CREDIT_NOTE')->count());
            $movement = DB::table('wms_stock_movements')->where('id', $posted->creditNote->inventory_reversal_movement_id)->where('direction', 'OUT')->first();
            self::assertNotNull($movement);
        } finally {
            DB::rollBack();
            config(['erp.inventory.purchase_posting_enabled' => $previous]);
        }
    }

    public function test_partial_return_posts_stock_cost_and_immutable_journal_link(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
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
            $invoice->load('lines.receiptAllocations.goodsReceiptLine.goodsReceipt');
            $apLine = JournalEntryLine::query()->where('journal_entry_id', $invoice->journal_entry_id)->where('subledger_type', 'SUPPLIER')->where('credit', $invoice->gross_amount)->firstOrFail();
            app(OpenItemService::class)->recordFromJournalLine($apLine, ['document_type' => 'INVOICE', 'document_number' => $invoice->document_number, 'due_date' => $invoice->document_date->format('Y-m-d'), 'withholding_rate' => '0.0000', 'withholding_base' => '0.00', 'withholding_amount' => '0.00']);
            $allocation = $invoice->lines->sole()->receiptAllocations->sole();
            $receipt = $allocation->goodsReceiptLine->goodsReceipt;
            DocumentSequence::query()->firstOrCreate(['warehouse_id' => null, 'document_type' => 'PURCHASE_RETURN'], ['name' => 'Partial Return Integration', 'prefix' => 'PRT', 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'created_by' => $actor->id]);
            $request = Request::create('/purchasing/returns', 'POST');
            $request->setUserResolver(fn (): User => $actor);
            $return = app(PurchaseReturnService::class)->createDraft(['goods_receipt_id' => $receipt->id, 'purchase_document_id' => $invoice->id, 'return_date' => $receipt->business_date->format('Y-m-d'), 'reason' => 'Partial damaged goods return', 'idempotency_key' => 'integration:partial-return:'.strtoupper(Str::random(12)), 'lines' => [['goods_receipt_line_id' => $allocation->goods_receipt_line_id, 'purchase_quantity' => '2.50000000']]], $actor, $request);
            $return = app(PurchaseReturnService::class)->approve(app(PurchaseReturnService::class)->submit($return, $actor), $actor);
            $credit = app(PurchaseReturnCreditNoteService::class)->createDraft($return, $actor, $request);
            $credit->forceFill(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()])->save();
            $credit = app(PurchaseDocumentPostingService::class)->post($credit, $return->return_date->format('Y-m-d'), $actor, $request);
            try {
                $movement = app(PurchaseReturnPartialInventoryAdapter::class)->post($return, $actor, true);
            } catch (ValidationException $exception) {
                if (str_contains($exception->getMessage(), 'สินค้าไม่เพียงพอ')) {
                    $this->markTestSkipped('Partial Return E2E ต้องมี Stock Balance on-hand จาก Receipt fixture ก่อนเปิด writer');
                }
                throw $exception;
            }
            $cost = app(PurchaseReturnPartialInventoryAdapter::class)->linkCostJournal($return->fresh('creditNote'), $movement);
            self::assertSame('POSTED', $credit->status);
            self::assertSame('POSTED', $movement->status);
            self::assertSame('POSTED', $cost->status);
            self::assertSame(1, DB::table('wms_cost_allocation_journal_lines')->where('allocation_id', $cost->id)->count());
            self::assertSame('APPROVED', $return->fresh()->status);
        } finally {
            DB::rollBack();
            config(['erp.inventory.purchase_posting_enabled' => $previous]);
        }
    }

    public function test_fifo_partial_return_aggregates_multiple_layers_into_one_journal_link(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        $actor = User::query()->first();
        if (! $actor) {
            $this->markTestSkipped('ต้องมี User ใน local MySQL');
        }
        InventoryPurchaseIntegrationFixture::assertReady();
        $settings = DB::table('company_settings')->where('id', 1)->firstOrFail();
        DB::beginTransaction();
        try {
            DB::table('company_settings')->where('id', 1)->update(['inventory_costing_method' => 'FIFO', 'settings_version' => ((int) $settings->settings_version) + 1]);
            app(\App\Modules\Settings\Services\GlobalSettings::class)->forget((int) $settings->settings_version);
            $invoice = InventoryPurchaseIntegrationFixture::createApprovedPurchase($actor);
            config(['erp.inventory.purchase_posting_enabled' => true]);
            $warehouse = $invoice->warehouse()->firstOrFail();
            $invoice = app(InventoryPurchaseProductionAdapter::class)->post($invoice, $warehouse, $actor, null, true);
            $invoice->load('lines.receiptAllocations.goodsReceiptLine.goodsReceipt');
            $apLine = JournalEntryLine::query()->where('journal_entry_id', $invoice->journal_entry_id)->where('subledger_type', 'SUPPLIER')->where('credit', $invoice->gross_amount)->firstOrFail();
            app(OpenItemService::class)->recordFromJournalLine($apLine, ['document_type' => 'INVOICE', 'document_number' => $invoice->document_number, 'due_date' => $invoice->document_date->format('Y-m-d'), 'withholding_rate' => '0.0000', 'withholding_base' => '0.00', 'withholding_amount' => '0.00']);
            $allocation = $invoice->lines->sole()->receiptAllocations->sole();
            $receipt = $allocation->goodsReceiptLine->goodsReceipt;
            $sourceMovement = DB::table('wms_stock_movements')->where('source_type', 'PURCHASING')->where('source_id', (string) $invoice->id)->where('direction', 'IN')->where('status', 'POSTED')->sole();
            $movementService = app(\App\Modules\Wms\Services\StockMovementService::class);
            $secondReceipt = $movementService->recordIntent([
                'warehouse_id' => $warehouse->id, 'item_id' => $sourceMovement->item_id, 'uom_id' => $sourceMovement->uom_id,
                'movement_type' => 'RECEIPT', 'direction' => 'IN', 'status' => 'DRAFT', 'quantity' => '20.00000000', 'base_quantity' => '20.00000000',
                'business_date' => $receipt->business_date->format('Y-m-d'), 'source_type' => 'PURCHASING', 'source_id' => (string) $invoice->id,
                'source_reference' => $invoice->document_number.'-L2', 'idempotency_key' => 'integration:fifo-layer-2:'.strtoupper(Str::random(12)),
                'metadata' => ['unit_cost' => '10.00000000', 'unit_cost_trusted' => true], 'created_by' => $actor->id,
            ]);
            $movementService->post($secondReceipt);
            $drain = $movementService->recordIntent([
                'warehouse_id' => $warehouse->id, 'item_id' => $sourceMovement->item_id, 'uom_id' => $sourceMovement->uom_id,
                'movement_type' => 'ISSUE', 'direction' => 'OUT', 'status' => 'DRAFT', 'quantity' => '90.00000000', 'base_quantity' => '90.00000000',
                'business_date' => $receipt->business_date->format('Y-m-d'), 'source_type' => 'PURCHASING', 'source_id' => (string) $invoice->id,
                'source_reference' => $invoice->document_number.'-DRAIN', 'idempotency_key' => 'integration:fifo-drain:'.strtoupper(Str::random(12)), 'created_by' => $actor->id,
            ]);
            $movementService->post($drain);
            DocumentSequence::query()->firstOrCreate(['warehouse_id' => null, 'document_type' => 'PURCHASE_RETURN'], ['name' => 'FIFO Return Integration', 'prefix' => 'PRT', 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'created_by' => $actor->id]);
            $request = Request::create('/purchasing/returns', 'POST');
            $request->setUserResolver(fn (): User => $actor);
            $return = app(PurchaseReturnService::class)->createDraft(['goods_receipt_id' => $receipt->id, 'purchase_document_id' => $invoice->id, 'return_date' => $receipt->business_date->format('Y-m-d'), 'reason' => 'FIFO multi-layer return', 'idempotency_key' => 'integration:fifo-return:'.strtoupper(Str::random(12)), 'lines' => [['goods_receipt_line_id' => $allocation->goods_receipt_line_id, 'purchase_quantity' => '2.50000000']]], $actor, $request);
            $return = app(PurchaseReturnService::class)->approve(app(PurchaseReturnService::class)->submit($return, $actor), $actor);
            $posted = app(PurchaseReturnPostingService::class)->postPartial($return, $return->return_date->format('Y-m-d'), $actor, $request, true);
            $credit = $posted->creditNote;
            $movement = DB::table('wms_stock_movements')->where('source_type', 'PURCHASING')->where('source_id', (string) $return->id)->where('direction', 'OUT')->latest('id')->first();
            $cost = DB::table('wms_cost_allocations')->where('stock_movement_id', $movement->id)->where('status', 'POSTED')->first();
            $allocations = DB::table('wms_cost_allocations')->where('stock_movement_id', $movement->id)->where('status', 'POSTED')->get();
            self::assertCount(2, $allocations);
            self::assertSame('POSTED', $posted->status);
            self::assertSame('POSTED', $credit->status);
            self::assertSame('POSTED', $cost->status);
            self::assertSame(2, DB::table('wms_cost_allocation_journal_lines')->whereIn('allocation_id', $allocations->pluck('id'))->count());
            self::assertSame(1, DB::table('journal_entry_lines')->where('journal_entry_id', $credit->journal_entry_id)->where('account_id', $invoice->lines->sole()->account_id)->where('credit', '250.00000000')->count());
            $countsAfterPost = $this->counts();
            $retry = app(PurchaseReturnPostingService::class)->postPartial($posted, $posted->return_date->format('Y-m-d'), $actor, $request, true);
            self::assertSame('POSTED', $retry->status);
            self::assertSame($countsAfterPost, $this->counts());
        } finally {
            DB::rollBack();
            DB::table('company_settings')->where('id', 1)->update((array) $settings);
            app(\App\Modules\Settings\Services\GlobalSettings::class)->forget((int) $settings->settings_version);
        }
    }

    private function createPostedCredit(PurchaseDocument $invoice, User $actor, ?string $prefix = null): PurchaseDocument
    {
        $invoice->load('lines.receiptAllocations');
        $line = $invoice->lines->sole();
        $allocation = $line->receiptAllocations->sole();
        $suffix = strtoupper(Str::random(12));
        $credit = PurchaseDocument::query()->create([
            'warehouse_id' => $invoice->warehouse_id, 'document_type' => 'CREDIT_NOTE', 'credit_note_mode' => 'RETURN', 'original_document_id' => $invoice->id,
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
