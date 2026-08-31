<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Wms\Models\GoodsReceipt;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\ItemCategory;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\PurchaseOrder;
use App\Modules\Wms\Models\PurchaseRequisition;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Models\UomConversion;
use App\Modules\Wms\Support\PurchaseThreeWayMatchGate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Contract-only fixture builder for the opt-in MySQL Inventory integration.
 *
 * This deliberately does not insert rows or manufacture Journal entries. A
 * future dedicated harness can compose the steps inside one DB transaction;
 * the preflight below makes missing migrations/FKs fail before any write.
 */
final class InventoryPurchaseIntegrationFixture
{
    /** @return array{warehouse: Warehouse, supplier: Party, item: Item, purchaseUom: Uom, stockUom: Uom, inventoryAccount: Account, cogsAccount: Account} */
    public static function createFoundation(User $actor): array
    {
        self::assertReady();
        $branch = Branch::query()->first();
        $inventoryAccount = Account::query()->where('control_account_type', 'INVENTORY')->where('is_active', true)->where('is_postable', true)->first();
        $cogsAccount = app(AccountMappingService::class)->resolve('COGS_DEFAULT');
        if (! $branch || ! $inventoryAccount || ! $cogsAccount) {
            throw new RuntimeException('ต้องมี Branch และบัญชี Inventory ที่ active/postable ก่อนสร้าง fixture foundation');
        }
        $suffix = strtoupper(Str::random(10));
        $warehouse = Warehouse::query()->create(['branch_id' => $branch->id, 'code' => 'INT-'.$suffix, 'name' => 'Integration '.$suffix, 'is_active' => true]);
        $supplier = Party::query()->create(['code' => 'INT-'.$suffix, 'name' => 'Integration Supplier '.$suffix, 'type' => 'COMPANY', 'branch_code' => '00000', 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
        PartyRole::query()->create(['party_id' => $supplier->id, 'role' => 'SUPPLIER', 'is_active' => true]);
        $purchaseUom = Uom::query()->create(['code' => 'BX'.$suffix, 'name' => 'Integration Box', 'decimal_places' => 0, 'is_active' => true, 'created_by' => $actor->id]);
        $stockUom = Uom::query()->create(['code' => 'PC'.$suffix, 'name' => 'Integration Piece', 'decimal_places' => 0, 'is_active' => true, 'created_by' => $actor->id]);
        UomConversion::query()->create(['from_uom_id' => $purchaseUom->id, 'to_uom_id' => $stockUom->id, 'factor' => 10, 'effective_from' => now()->toDateString(), 'created_by' => $actor->id]);
        $category = ItemCategory::query()->create(['code' => 'INT-'.$suffix, 'name' => 'Integration Category '.$suffix, 'is_active' => true, 'created_by' => $actor->id]);
        $item = Item::query()->create(['category_id' => $category->id, 'code' => 'INT-'.$suffix, 'name' => 'Integration Item '.$suffix, 'item_type' => 'GOODS', 'base_uom' => $stockUom->code, 'base_uom_id' => $stockUom->id, 'is_stock_item' => true, 'inventory_account_id' => $inventoryAccount->id, 'cogs_account_id' => $cogsAccount->id, 'is_active' => true, 'created_by' => $actor->id]);

        return compact('warehouse', 'supplier', 'item', 'purchaseUom', 'stockUom', 'inventoryAccount', 'cogsAccount');
    }

    /** @return array{foundation: array, requisition: PurchaseRequisition, order: PurchaseOrder, receipt: GoodsReceipt} */
    public static function createProcurementChain(User $actor): array
    {
        $foundation = self::createFoundation($actor);
        $suffix = strtoupper(Str::random(10));
        $date = now()->toDateString();
        $requisition = PurchaseRequisition::query()->create([
            'warehouse_id' => $foundation['warehouse']->id, 'document_number' => 'PR-INT-'.$suffix, 'document_date' => $date,
            // Supplier is selected by Purchasing when the approved PR becomes a PO.
            'supplier_id' => null, 'description' => 'Dedicated integration fixture', 'status' => 'APPROVED',
            'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $reqLine = $requisition->lines()->create(['line_number' => 1, 'item_id' => $foundation['item']->id, 'uom_id' => $foundation['purchaseUom']->id, 'quantity' => 10, 'description' => 'Integration item']);
        $order = PurchaseOrder::query()->create([
            'warehouse_id' => $foundation['warehouse']->id, 'purchase_requisition_id' => $requisition->id, 'supplier_id' => $foundation['supplier']->id,
            'supplier_code' => $foundation['supplier']->code, 'supplier_name' => $foundation['supplier']->name, 'document_number' => 'PO-INT-'.$suffix,
            'document_date' => $date, 'expected_date' => $date, 'subtotal' => 1000, 'total_amount' => 1000, 'status' => 'APPROVED',
            'description' => 'Dedicated integration fixture', 'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $orderLine = $order->lines()->create(['purchase_requisition_line_id' => $reqLine->id, 'line_number' => 1, 'item_id' => $foundation['item']->id, 'uom_id' => $foundation['purchaseUom']->id, 'description' => 'Integration item', 'quantity' => 10, 'unit_price' => 100, 'line_total' => 1000]);
        $receipt = GoodsReceipt::query()->create([
            'warehouse_id' => $foundation['warehouse']->id, 'purchase_order_id' => $order->id, 'supplier_id' => $foundation['supplier']->id,
            'receipt_number' => 'GR-INT-'.$suffix, 'idempotency_key' => 'integration:'.$suffix, 'business_date' => $date, 'status' => 'APPROVED',
            'description' => 'Dedicated integration fixture', 'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $receipt->lines()->create(['purchase_order_line_id' => $orderLine->id, 'item_id' => $foundation['item']->id, 'purchase_uom_id' => $foundation['purchaseUom']->id, 'stock_uom_id' => $foundation['stockUom']->id, 'purchase_quantity' => 10, 'factor' => 10, 'stock_quantity' => 100, 'total_cost' => 1000, 'stock_unit_cost' => 10, 'rounding_delta' => 0, 'conversion_snapshot' => ['factor' => 10, 'from' => $foundation['purchaseUom']->code, 'to' => $foundation['stockUom']->code]]);

        return compact('foundation', 'requisition', 'order', 'receipt');
    }

    public static function createApprovedPurchase(User $actor): PurchaseDocument
    {
        self::assertReady();
        $chain = self::createProcurementChain($actor);
        $order = $chain['order'];
        $receipt = $chain['receipt']->load('lines');
        $orderLine = $order->load('lines')->lines->first();
        $receiptLine = $receipt->lines->firstWhere('purchase_order_line_id', $orderLine?->id);
        $item = $chain['foundation']['item'];
        if (! $orderLine || ! $receiptLine || ! $item->inventory_account_id) {
            throw new RuntimeException('Procurement chain ต้องมี PO line, GR line และ Inventory account ครบ');
        }
        $now = now();
        $document = PurchaseDocument::query()->create([
            'warehouse_id' => $order->warehouse_id, 'document_type' => 'INVOICE',
            'document_number' => 'PI-INT-'.strtoupper(Str::random(12)), 'document_date' => $receipt->business_date,
            'posting_date' => $receipt->business_date, 'supplier_id' => $order->supplier_id, 'supplier_code' => $order->supplier_code, 'supplier_name' => $order->supplier_name,
            'tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false, 'tax_decimal_places' => 2,
            'subtotal' => $receiptLine->total_cost, 'tax_amount' => '0.00', 'withholding_rate' => '0.0000',
            'withholding_base' => '0.00', 'withholding_amount' => '0.00', 'gross_amount' => $receiptLine->total_cost,
            'rounding_amount' => '0.00', 'status' => 'APPROVED', 'reversal_status' => 'NONE', 'reversal_revision' => 0,
            'description' => 'Dedicated MySQL integration fixture', 'approved_by' => $actor->id, 'approved_at' => $now,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $line = $document->lines()->create([
            'line_number' => 1, 'description' => $orderLine->description, 'item_id' => $receiptLine->item_id, 'uom_id' => $receiptLine->purchase_uom_id,
            'purchase_order_line_id' => $orderLine->id, 'account_id' => $item->inventory_account_id, 'withholding_base' => 0, 'withholding_amount' => 0,
            'tax_rate' => '0.0000', 'tax_base' => $receiptLine->total_cost, 'quantity' => $receiptLine->purchase_quantity,
            'unit_price' => $orderLine->unit_price, 'discount_amount' => 0, 'net_amount' => $receiptLine->total_cost,
            'tax_amount' => '0.00', 'gross_amount' => $receiptLine->total_cost,
        ]);
        $line->receiptAllocations()->create([
            'goods_receipt_line_id' => $receiptLine->id, 'allocated_quantity' => $receiptLine->purchase_quantity,
            'allocated_amount' => $receiptLine->total_cost, 'idempotency_key' => "fixture:purchase:{$document->id}:line:{$line->id}:gr:{$receiptLine->id}",
        ]);

        $document->load('lines.receiptAllocations.goodsReceiptLine.goodsReceipt', 'lines.purchaseOrderLine.purchaseOrder.lines');
        $match = app(PurchaseThreeWayMatchGate::class)->preview($document);
        if (! $match || ! $match['ready']) {
            throw new RuntimeException('Dedicated fixture ต้องผ่าน 3-way match: '.implode(', ', $match['blockers'] ?? ['unknown']));
        }

        return $document;
    }

    /** @return array<int, string> */
    public static function steps(): array
    {
        return [
            'user', 'warehouse', 'supplier_party_role', 'account_types_accounts',
            'typed_account_mappings', 'open_fiscal_period', 'journal_books',
            'uom_conversion', 'stock_item', 'purchase_order', 'goods_receipt',
            'approved_inventory_purchase',
        ];
    }

    /** @return array<string, array<int, string>> */
    public static function requiredSchema(): array
    {
        return [
            'purchase_document_lines' => ['item_id', 'uom_id'],
            'wms_stock_movements' => ['idempotency_key', 'source_type', 'source_id', 'source_reference'],
            'wms_cost_allocations' => ['stock_movement_id', 'warehouse_id', 'item_id', 'uom_id', 'idempotency_key'],
            'wms_cost_allocation_journal_lines' => ['allocation_id', 'journal_entry_line_id', 'revision', 'identity_key'],
        ];
    }

    public static function assertReady(): void
    {
        foreach (self::requiredSchema() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Integration fixture requires table {$table}; run the approved migrations first.");
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new RuntimeException("Integration fixture requires {$table}.{$column}; migration contract is incomplete.");
                }
            }
        }

        if ((bool) config('erp.inventory.purchase_posting_enabled', false)) {
            throw new RuntimeException('Integration fixture must not enable Inventory posting outside the test transaction.');
        }
    }

    /** @return array<int, string> */
    public static function baselineBlockers(?int $warehouseId = null): array
    {
        $blockers = [];
        $allocations = \DB::table('wms_cost_allocations');
        $movements = \DB::table('wms_stock_movements');
        if ($warehouseId !== null) {
            $allocations->where('warehouse_id', $warehouseId);
            $movements->where('warehouse_id', $warehouseId);
        }
        if ((clone $allocations)->where('status', 'PENDING')->whereNotNull('journal_entry_id')->exists()) {
            $blockers[] = 'มี allocation PENDING แต่มี Journal linkage';
        }
        if ((clone $movements)->where('movement_type', 'RECEIPT')->where('direction', 'OUT')->exists()) {
            $blockers[] = 'มี RECEIPT movement ที่ direction=OUT';
        }
        if ((clone $allocations)->where('status', 'PENDING')->exists()) {
            $blockers[] = 'มี Cost Allocation PENDING';
        }

        return array_values(array_unique($blockers));
    }
}
