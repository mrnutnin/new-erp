<?php

namespace App\Modules\Wms\Services;

use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Support\PurchaseReturnPartialPostingContract;
use App\Modules\Purchasing\Support\PurchaseReturnPartialCostAllocationContract;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockCostLayer;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Purchasing\Support\PurchaseReturnPartialJournalLinkContract;
use App\Modules\Purchasing\Support\PurchaseReturnPartialMultiLayerJournalLinkContract;
use App\Modules\Wms\Services\InventoryCostAllocationService;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Modules\Wms\Models\StockMovement;

/** Feature-gated Partial Return WMS boundary. */
final class PurchaseReturnPartialInventoryAdapter
{
    public function __construct(private readonly GlobalSettings $settings, private readonly StockMovementService $movements, private readonly InventoryCostAllocationService $allocations) {}

    public function linkCostJournal(PurchaseReturn $purchaseReturn, StockMovement $movement): CostAllocation
    {
        return DB::transaction(function () use ($purchaseReturn, $movement): CostAllocation {
            $return = $purchaseReturn->fresh(['creditNote.journalEntry']);
            $credit = $return->creditNote;
            $allocations = CostAllocation::query()->where('stock_movement_id', $movement->id)->where('status', '!=', 'REVERSED')->lockForUpdate()->get();
            if ($allocations->isEmpty() || ! $credit?->journal_entry_id) {
                throw ValidationException::withMessages(['allocation' => 'Partial Return Journal link ต้องมี Cost Allocation และ Credit Note Journal']);
            }
            $accountId = $movement->item()->value('inventory_account_id');
            $allocationTotal = $allocations->reduce(fn (BigDecimal $total, CostAllocation $row): BigDecimal => $total->plus(BigDecimal::of((string) $row->value)->abs()), BigDecimal::zero());
            $journalLine = JournalEntryLine::query()->where('journal_entry_id', $credit->journal_entry_id)->where('account_id', $accountId)->where('debit', '0.00')->where('credit', '>', 0)->get()->first(fn (JournalEntryLine $line): bool => BigDecimal::of((string) $line->credit)->isEqualTo($allocationTotal));
            if (! $journalLine) {
                throw ValidationException::withMessages(['journal_line_id' => 'ไม่พบ Credit Note Journal line ที่ตรงกับ Partial Cost Allocation']);
            }
            PurchaseReturnPartialMultiLayerJournalLinkContract::plan([
                'purchase_return_id' => $return->id, 'credit_note_id' => $credit->id, 'journal_entry_id' => $credit->journal_entry_id, 'journal_line_id' => $journalLine->id,
                'allocation_ids' => $allocations->pluck('id')->all(), 'return_status' => $return->status, 'credit_status' => $credit->status, 'journal_status' => $credit->journalEntry?->status, 'journal_event' => $credit->journalEntry?->source_event,
                'journal_source_id' => $credit->id, 'credit_warehouse_id' => $credit->warehouse_id, 'return_warehouse_id' => $return->warehouse_id, 'credit_supplier_id' => $credit->supplier_id, 'return_supplier_id' => $return->supplier_id,
                'allocation_account_id' => $accountId, 'journal_account_id' => $journalLine->account_id, 'allocation_total' => $allocationTotal, 'journal_line_credit' => $journalLine->credit,
            ]);

            foreach ($allocations as $allocation) {
                $this->allocations->linkJournalLineWithinTransaction($allocation, $journalLine);
            }
            return $allocations->first()->fresh();
        }, 3);
    }

    public function post(PurchaseReturn $purchaseReturn, User $actor, bool $featureEnabled = false): StockMovement
    {
        if (! $featureEnabled) {
            throw ValidationException::withMessages(['posting' => 'Partial Return Stock posting ยังไม่เปิดใช้งาน']);
        }

        return DB::transaction(function () use ($purchaseReturn, $actor): StockMovement {
            $plan = $this->preflight($purchaseReturn);
            $return = $purchaseReturn->fresh(['lines.goodsReceiptLine']);
        $line = $return->lines->sole();
        $movement = $this->movements->recordIntent([
                'warehouse_id' => $return->warehouse_id, 'item_id' => $line->item_id, 'uom_id' => $plan['movement']['stock_uom_id'],
                'movement_type' => 'ISSUE', 'direction' => 'OUT', 'status' => 'DRAFT',
                'quantity' => $plan['movement']['stock_quantity'], 'base_quantity' => $plan['movement']['stock_quantity'],
                'business_date' => $return->return_date->format('Y-m-d'), 'source_type' => 'PURCHASING', 'source_id' => (string) $return->id,
                'source_reference' => $return->return_number, 'idempotency_key' => $plan['movement']['idempotency_key'],
                'metadata' => ['purchase_return_id' => $return->id, 'partial_return_ratio' => $plan['movement']['return_ratio']], 'created_by' => $actor->id,
            ]);

            return $this->movements->postWithinTransaction($movement);
        }, 3);
    }

    public function preflight(PurchaseReturn $purchaseReturn): array
    {
        $purchaseReturn->loadMissing(['lines.goodsReceiptLine', 'goodsReceipt']);
        if ($purchaseReturn->lines->count() !== 1) {
            throw ValidationException::withMessages(['lines' => 'Partial Return MVP preflight รองรับทีละหนึ่ง GR line']);
        }
        $line = $purchaseReturn->lines->sole();
        $receiptLine = $line->goodsReceiptLine;
        if (! $receiptLine || (int) $receiptLine->goods_receipt_id !== (int) $purchaseReturn->goods_receipt_id) {
            throw ValidationException::withMessages(['goods_receipt_id' => 'Return line ต้องอยู่ใน Goods Receipt ต้นทาง']);
        }
        $sourceMovement = StockMovement::query()->where('source_type', 'PURCHASING')->where('source_id', (string) $purchaseReturn->purchase_document_id)->where('item_id', $line->item_id)->where('direction', 'IN')->where('status', 'POSTED')->latest('id')->first();
        if (! $sourceMovement) {
            throw ValidationException::withMessages(['movement' => 'Partial Return ต้องมี Receipt Stock Movement ที่ Post แล้วก่อน']);
        }
        $stockUomId = (int) $sourceMovement->uom_id;

        $movementPlan = PurchaseReturnPartialPostingContract::plan([
            'purchase_return_id' => $purchaseReturn->id,
            'goods_receipt_line_id' => $receiptLine->id,
            'received_purchase_quantity' => (string) $receiptLine->purchase_quantity,
            'returned_purchase_quantity' => (string) $line->purchase_quantity,
            'factor' => (string) $receiptLine->factor,
            'stock_unit_cost' => (string) $receiptLine->stock_unit_cost,
        ]);
        $method = (string) $this->settings->value('inventory_costing_method');
        $movementPlan['stock_uom_id'] = $stockUomId;
        $cost = $method === 'AVG'
            ? PurchaseReturnPartialCostAllocationContract::plan('AVG', $movementPlan['stock_quantity'], (string) (StockBalance::query()->where('warehouse_id', $purchaseReturn->warehouse_id)->where('item_id', $line->item_id)->where('uom_id', $stockUomId)->value('average_unit_cost') ?? '0'))
            : PurchaseReturnPartialCostAllocationContract::plan('FIFO', $movementPlan['stock_quantity'], '0', StockCostLayer::query()->where('warehouse_id', $purchaseReturn->warehouse_id)->where('item_id', $line->item_id)->where('uom_id', $stockUomId)->where('method', 'FIFO')->where('cost_status', 'FINAL')->where('remaining_quantity', '>', 0)->orderBy('business_date')->orderBy('id')->get(['remaining_quantity as quantity', 'unit_cost'])->map(fn ($layer): array => ['quantity' => (string) $layer->quantity, 'unit_cost' => (string) $layer->unit_cost])->all());

        return ['movement' => $movementPlan, 'cost' => $cost, 'posting_enabled' => false];
    }
}
