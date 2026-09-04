<?php

namespace App\Modules\Purchasing\Services;

use App\Models\Branch;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\ItemCategory;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequisition;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Models\UomConversion;
use App\Modules\Purchasing\Support\PurchaseThreeWayMatchGate;
use App\Modules\Wms\Support\InventoryOpsSmokeContract;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Creates an isolated purchasing source chain for integration/readiness work.
 *
 * The caller owns the transaction boundary when a runner is supplied. This
 * builder creates source documents only; posting Stock/Cost/GL remains the
 * responsibility of the production adapters.
 */
final class ProcurementSourceBuilder
{
    public function __construct(
        private readonly AccountMappingService $accountMappings,
        private readonly PurchaseThreeWayMatchGate $matchGate,
    ) {}

    /**
     * @param  Closure(Closure): mixed|null  Transaction runner; omit to use DB::transaction.
     * @return array<string, int|float|bool|string|array<int, string>>
     */
    public function build(User $actor, string $prefix = 'INT', ?Closure $transaction = null, bool $persistent = false): array
    {
        $prefix = $this->normalizePrefix($prefix, $persistent);
        if ($persistent) {
            if (! str_starts_with($prefix, 'OPS-SMOKE-')) {
                throw ValidationException::withMessages(['prefix' => 'persistent procurement source ต้องใช้ prefix OPS-SMOKE-*']);
            }
            app(InventoryOpsSmokeContract::class)->validate($prefix, $actor->id, true);
        }
        $this->assertReady();

        $runner = $transaction ?? static fn (Closure $callback): mixed => DB::transaction($callback);

        return $runner(fn (): array => $this->createChain($actor, $prefix));
    }

    /** @return array<string, int|float|bool|string|array<int, string>> */
    private function createChain(User $actor, string $prefix): array
    {
        $hash = $this->sourceHash($prefix);
        $marker = 'Dedicated procurement source [builder:'.$hash.']';
        // The builder is also used by the persistent smoke writer. Keep the
        // idempotency lookup inside the caller's transaction and lock the
        // candidate rows so two writers cannot reuse/validate the same source
        // chain concurrently. A source that is not present will still be
        // protected by the unique document-number constraint on insert.
        $existingPrefix = PurchaseDocument::query()
            ->where('document_number', 'like', 'PI-'.$prefix.'-%')
            ->lockForUpdate()
            ->get(['id', 'description', 'status', 'journal_entry_id', 'warehouse_id', 'supplier_id', 'created_by', 'document_number']);
        if ($existingPrefix->isNotEmpty() && ! $existingPrefix->contains('description', $marker)) {
            throw new RuntimeException('พบ procurement source เดิมแต่ source/config hash ไม่ตรงกัน ไม่อนุญาตให้สร้างซ้ำ');
        }
        $existing = $existingPrefix->first(fn (PurchaseDocument $document): bool => $document->description === $marker);
        if ($existing) {
            $existing->load('lines.receiptAllocations.goodsReceiptLine.goodsReceipt', 'lines.purchaseOrderLine.purchaseOrder.purchaseRequisition');
        }
        if ($existing) {
            return $this->snapshot($existing, $prefix, $hash);
        }

        $branch = Branch::query()->first();
        $inventoryAccount = $this->accountMappings->resolve('INVENTORY_DEFAULT');
        $cogsAccount = $this->accountMappings->resolve('COGS_DEFAULT');
        if (! $branch || ! $inventoryAccount || ! $cogsAccount) {
            throw new RuntimeException('ต้องมี Branch และบัญชี Inventory ที่ active/postable ก่อนสร้าง procurement source');
        }

        $suffix = strtoupper(Str::random(10));
        $this->assertGeneratedLengths($prefix, $suffix);
        $date = now()->toDateString();
        $warehouse = Warehouse::query()->create(['branch_id' => $branch->id, 'code' => $prefix.'-'.$suffix, 'name' => 'Integration '.$suffix, 'is_active' => true]);
        $this->assertIsolatedWarehouseReady($warehouse->id);
        $supplier = Party::query()->create(['code' => $prefix.'-'.$suffix, 'name' => 'Integration Supplier '.$suffix, 'type' => 'COMPANY', 'branch_code' => '00000', 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
        PartyRole::query()->create(['party_id' => $supplier->id, 'role' => 'SUPPLIER', 'is_active' => true]);
        $purchaseUom = Uom::query()->create(['code' => 'BX'.$suffix, 'name' => 'Integration Box', 'decimal_places' => 0, 'is_active' => true, 'created_by' => $actor->id]);
        $stockUom = Uom::query()->create(['code' => 'PC'.$suffix, 'name' => 'Integration Piece', 'decimal_places' => 0, 'is_active' => true, 'created_by' => $actor->id]);
        UomConversion::query()->create(['from_uom_id' => $purchaseUom->id, 'to_uom_id' => $stockUom->id, 'factor' => 10, 'effective_from' => $date, 'created_by' => $actor->id]);
        $category = ItemCategory::query()->create(['code' => $prefix.'-'.$suffix, 'name' => 'Integration Category '.$suffix, 'is_active' => true, 'created_by' => $actor->id]);
        $item = Item::query()->create(['category_id' => $category->id, 'code' => $prefix.'-'.$suffix, 'name' => 'Integration Item '.$suffix, 'item_type' => 'GOODS', 'base_uom' => $stockUom->code, 'base_uom_id' => $stockUom->id, 'is_stock_item' => true, 'inventory_account_id' => $inventoryAccount->id, 'cogs_account_id' => $cogsAccount->id, 'is_active' => true, 'created_by' => $actor->id]);

        $requisition = PurchaseRequisition::query()->create([
            'warehouse_id' => $warehouse->id, 'document_number' => 'PR-'.$prefix.'-'.$suffix, 'document_date' => $date,
            'supplier_id' => null, 'description' => $marker, 'status' => 'APPROVED',
            'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $reqLine = $requisition->lines()->create(['line_number' => 1, 'item_id' => $item->id, 'uom_id' => $purchaseUom->id, 'quantity' => 10, 'description' => 'Integration item']);
        $order = PurchaseOrder::query()->create([
            'warehouse_id' => $warehouse->id, 'purchase_requisition_id' => $requisition->id, 'supplier_id' => $supplier->id,
            'supplier_code' => $supplier->code, 'supplier_name' => $supplier->name, 'document_number' => 'PO-'.$prefix.'-'.$suffix,
            'document_date' => $date, 'expected_date' => $date, 'subtotal' => 1000, 'total_amount' => 1000, 'status' => 'APPROVED',
            'description' => $marker, 'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $orderLine = $order->lines()->create(['purchase_requisition_line_id' => $reqLine->id, 'line_number' => 1, 'item_id' => $item->id, 'uom_id' => $purchaseUom->id, 'description' => 'Integration item', 'quantity' => 10, 'unit_price' => 100, 'line_total' => 1000]);
        $receipt = GoodsReceipt::query()->create([
            'warehouse_id' => $warehouse->id, 'purchase_order_id' => $order->id, 'supplier_id' => $supplier->id,
            'receipt_number' => 'GR-'.$prefix.'-'.$suffix, 'idempotency_key' => 'integration:'.$prefix.':'.$suffix, 'business_date' => $date, 'status' => 'APPROVED',
            'description' => $marker, 'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $receiptLine = $receipt->lines()->create(['purchase_order_line_id' => $orderLine->id, 'item_id' => $item->id, 'purchase_uom_id' => $purchaseUom->id, 'stock_uom_id' => $stockUom->id, 'purchase_quantity' => 10, 'factor' => 10, 'stock_quantity' => 100, 'total_cost' => 1000, 'stock_unit_cost' => 10, 'rounding_delta' => 0, 'conversion_snapshot' => ['factor' => 10, 'from' => $purchaseUom->code, 'to' => $stockUom->code]]);
        $taxDecimals = (int) app(GlobalSettings::class)->value('tax_decimal_places');
        $document = PurchaseDocument::query()->create([
            'warehouse_id' => $warehouse->id, 'document_type' => 'INVOICE', 'document_number' => 'PI-'.$prefix.'-'.$suffix,
            'document_date' => $date, 'posting_date' => $date, 'supplier_id' => $supplier->id, 'supplier_code' => $supplier->code, 'supplier_name' => $supplier->name,
            'tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false, 'tax_decimal_places' => $taxDecimals, 'subtotal' => 1000, 'tax_amount' => '0.00',
            'withholding_rate' => '0.0000', 'withholding_base' => '0.00', 'withholding_amount' => '0.00', 'gross_amount' => 1000, 'rounding_amount' => '0.00',
            'status' => 'APPROVED', 'reversal_status' => 'NONE', 'reversal_revision' => 0, 'description' => $marker,
            'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $line = $document->lines()->create(['line_number' => 1, 'description' => $orderLine->description, 'item_id' => $item->id, 'uom_id' => $purchaseUom->id, 'purchase_order_line_id' => $orderLine->id, 'account_id' => $inventoryAccount->id, 'withholding_base' => 0, 'withholding_amount' => 0, 'tax_rate' => '0.0000', 'tax_base' => 1000, 'quantity' => 10, 'unit_price' => 100, 'discount_amount' => 0, 'net_amount' => 1000, 'tax_amount' => '0.00', 'gross_amount' => 1000]);
        $allocation = $line->receiptAllocations()->create(['goods_receipt_line_id' => $receiptLine->id, 'allocated_quantity' => 10, 'allocated_amount' => 1000, 'idempotency_key' => "fixture:purchase:{$document->id}:line:{$line->id}:gr:{$receiptLine->id}"]);
        $document->load('lines.receiptAllocations.goodsReceiptLine.goodsReceipt', 'lines.purchaseOrderLine.purchaseOrder.lines');
        // A posted source has already passed the three-way gate. Re-running
        // the preview against the immutable/posting state can legitimately
        // report a different state, so idempotent retries validate linkage
        // and let the production adapter verify the posted chain instead.
        $match = (string) $document->status === 'POSTED'
            ? ['ready' => true, 'variance_state' => 'CLEAR', 'blockers' => []]
            : $this->matchGate->preview($document);
        if (! $match || ! $match['ready']) {
            throw ValidationException::withMessages(['three_way_match' => 'Procurement source ไม่ผ่าน 3-way match: '.implode(', ', $match['blockers'] ?? ['unknown'])]);
        }

        return [
            'warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id, 'item_id' => $item->id, 'purchase_uom_id' => $purchaseUom->id,
            'stock_uom_id' => $stockUom->id, 'inventory_account_id' => $inventoryAccount->id, 'requisition_id' => $requisition->id,
            'requisition_line_id' => $reqLine->id, 'order_id' => $order->id, 'order_line_id' => $orderLine->id, 'receipt_id' => $receipt->id,
            'receipt_line_id' => $receiptLine->id, 'purchase_document_id' => $document->id, 'purchase_document_line_id' => $line->id,
            'receipt_allocation_id' => $allocation->id, 'conversion_factor' => 10, 'allocated_amount' => 1000.0,
            'three_way_ready' => true, 'three_way_variance_state' => $match['variance_state'] ?? 'CLEAR', 'three_way_blockers' => $match['blockers'] ?? [],
            'source_hash' => $hash, 'actor_id' => $actor->id,
            'source_metadata' => ['source_type' => 'PURCHASING', 'event_code' => 'supplier_invoice.inventory', 'builder_hash' => $hash, 'source_prefix' => $prefix, 'actor_id' => $actor->id],
        ];
    }

    /** @return array<string, int|float|bool|string|array<int, string>> */
    private function snapshot(PurchaseDocument $document, string $prefix, string $hash): array
    {
        $line = $document->lines->first();
        $allocation = $line?->receiptAllocations->first();
        $receiptLine = $allocation?->goodsReceiptLine;
        $orderLine = $line?->purchaseOrderLine;
        $receipt = $receiptLine?->goodsReceipt;
        $order = $orderLine?->purchaseOrder;
        $requisition = $order?->purchaseRequisition;
        if (! $line || ! $allocation || ! $receiptLine || ! $orderLine || ! $receipt || ! $order || ! $requisition) {
            throw new RuntimeException('พบ procurement source เดิมแต่ linkage ไม่ครบ ไม่อนุญาตให้ reuse');
        }

        if ((string) $document->status === 'POSTED') {
            $this->assertPostedSnapshotIntegrity($document);

            return $this->snapshotResult($document, $prefix, $hash, $line, $allocation, $receiptLine, $orderLine, $receipt, $order);
        }

        $match = $this->matchGate->preview($document);
        if (! ($match['ready'] ?? false)) {
            throw new RuntimeException('พบ procurement source เดิมแต่ 3-way match ไม่พร้อม ไม่อนุญาตให้ reuse');
        }

        return $this->snapshotResult($document, $prefix, $hash, $line, $allocation, $receiptLine, $orderLine, $receipt, $order, $match);
    }

    /** @return array<string, int|float|bool|string|array<int, string>> */
    private function snapshotResult(PurchaseDocument $document, string $prefix, string $hash, mixed $line, mixed $allocation, mixed $receiptLine, mixed $orderLine, mixed $receipt, mixed $order, ?array $match = null): array
    {
        $requisition = $order->purchaseRequisition;

        return ['warehouse_id' => $document->warehouse_id, 'supplier_id' => $document->supplier_id, 'item_id' => $line->item_id, 'purchase_uom_id' => $line->uom_id, 'stock_uom_id' => $receiptLine->stock_uom_id, 'inventory_account_id' => $line->account_id, 'requisition_id' => $requisition->id, 'requisition_line_id' => $orderLine->purchase_requisition_line_id, 'order_id' => $order->id, 'order_line_id' => $orderLine->id, 'receipt_id' => $receipt->id, 'receipt_line_id' => $receiptLine->id, 'purchase_document_id' => $document->id, 'purchase_document_line_id' => $line->id, 'receipt_allocation_id' => $allocation->id, 'conversion_factor' => $receiptLine->factor, 'allocated_amount' => (float) $allocation->allocated_amount, 'three_way_ready' => true, 'three_way_variance_state' => $match['variance_state'] ?? 'CLEAR', 'three_way_blockers' => $match['blockers'] ?? [], 'source_hash' => $hash, 'actor_id' => $document->created_by, 'source_metadata' => ['source_type' => 'PURCHASING', 'event_code' => 'supplier_invoice.inventory', 'builder_hash' => $hash, 'source_prefix' => $prefix, 'actor_id' => $document->created_by, 'idempotent_reuse' => true]];
    }

    private function assertPostedSnapshotIntegrity(PurchaseDocument $document): void
    {
        $journal = JournalEntry::query()->whereKey($document->journal_entry_id)->where('status', 'POSTED')->where('source_type', 'PURCHASING')->where('source_event', 'supplier_invoice.inventory')->where('source_id', (string) $document->id)->where('source_reference', $document->document_number)->first();
        $movements = StockMovement::query()->where('source_type', 'PURCHASING')->where('source_id', (string) $document->id)->where('status', 'POSTED')->get();
        if (! $journal || $movements->count() !== 1) {
            throw new RuntimeException('พบ OPS source POSTED แต่ Journal/Movement identity ไม่ครบ ไม่อนุญาตให้ retry');
        }
        $allocations = CostAllocation::query()
            ->where('stock_movement_id', $movements->sole()->id)
            ->where('journal_entry_id', $journal->id)
            ->where('idempotency_key', 'movement:'.$movements->sole()->id.':receipt')
            ->where('allocation_type', 'RECEIPT')
            ->where('direction', 'IN')
            ->where('cost_status', 'FINAL')
            ->where('status', '!=', 'REVERSED')
            ->get();
        if ($allocations->count() !== 1 || $allocations->sole()->journalLineLinks()->count() !== 1) {
            throw new RuntimeException('พบ OPS source POSTED แต่ Cost Allocation/Journal linkage ไม่ครบ ไม่อนุญาตให้ retry');
        }
    }

    private function assertIsolatedWarehouseReady(int $warehouseId): void
    {
        if (Schema::hasTable('wms_cost_allocation_reviews') && DB::table('wms_cost_allocation_reviews as reviews')->join('wms_cost_allocations as allocations', 'allocations.id', '=', 'reviews.allocation_id')->where('allocations.warehouse_id', $warehouseId)->where('reviews.status', 'OPEN')->exists()) {
            throw new RuntimeException('Warehouse fixture มี legacy review เปิดอยู่ ไม่อนุญาตให้สร้าง source');
        }
        if (DB::table('wms_cost_allocations')->where('warehouse_id', $warehouseId)->where('status', 'PENDING')->exists()) {
            throw new RuntimeException('Warehouse fixture มี Cost Allocation PENDING ไม่อนุญาตให้สร้าง source');
        }
        if (DB::table('wms_stock_movements')->where('warehouse_id', $warehouseId)->where('movement_type', 'RECEIPT')->where('direction', 'OUT')->exists()) {
            throw new RuntimeException('Warehouse fixture มี RECEIPT movement ผิดทิศทาง ไม่อนุญาตให้สร้าง source');
        }
    }

    private function normalizePrefix(string $prefix, bool $persistent = false): string
    {
        $prefix = strtoupper(trim($prefix));
        if ($prefix === '' || strlen($prefix) > 24 || ! preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $prefix)) {
            throw ValidationException::withMessages(['prefix' => 'prefix ต้องเป็นตัวอักษร/ตัวเลขและขีดกลาง ความยาวไม่เกิน 24 ตัวอักษร']);
        }
        if ($persistent && strlen($prefix) > 19) {
            throw ValidationException::withMessages(['prefix' => 'persistent OPS-SMOKE prefix ต้องยาวไม่เกิน 19 ตัวอักษร เพื่อไม่ให้รหัส Supplier/Category เกิน 30 ตัวอักษร']);
        }

        return $prefix;
    }

    private function assertGeneratedLengths(string $prefix, string $suffix): void
    {
        $code = $prefix.'-'.$suffix;
        if (strlen($code) > 30) {
            throw ValidationException::withMessages(['prefix' => 'prefix ที่ใช้สร้าง Supplier/Category/รหัสอ้างอิงยาวเกิน 30 ตัวอักษร']);
        }
        foreach (['PR-'.$code => 40, 'PO-'.$code => 40, 'PI-'.$code => 40, 'GR-'.$code => 80] as $generated => $max) {
            if (strlen($generated) > $max) {
                throw ValidationException::withMessages(['prefix' => "prefix ทำให้เลขเอกสาร {$generated} เกิน {$max} ตัวอักษร"]);
            }
        }
    }

    private function sourceHash(string $prefix): string
    {
        return hash('sha256', json_encode([
            'prefix' => $prefix,
            'contract' => 'procurement-source-v2',
            'conversion_factor' => 10,
            'amount' => '1000.00',
            'costing_policy' => config('erp.inventory.costing_policy'),
        ], JSON_THROW_ON_ERROR));
    }

    private function assertReady(): void
    {
        foreach (['purchase_requisitions', 'purchase_orders', 'goods_receipts', 'purchase_documents', 'purchase_document_lines', 'purchase_document_receipt_allocations', 'accounting_account_mappings'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Procurement source ต้องมีตาราง {$table}; กรุณารัน migration ที่อนุมัติก่อน");
            }
        }
        if ((bool) config('erp.inventory.purchase_posting_enabled', false)) {
            throw new RuntimeException('Procurement source builder ต้องไม่ทำงานขณะเปิด Inventory posting');
        }
    }
}
