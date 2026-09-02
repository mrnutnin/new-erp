<?php

namespace App\Modules\Pos\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\AdvanceDepositApplicationService;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesReturn;
use App\Modules\Pos\Models\SalesReturnInventoryLink;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostAllocationJournalLine;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Services\InventoryCostAllocationService;
use App\Modules\Wms\Services\StockMovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Full-return cancellation for a Posted POS sale. */
final class PhysicalSaleCancellationService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly AdvanceDepositApplicationService $advanceApplications,
        private readonly OpenItemService $openItems,
        private readonly StockMovementService $movements,
        private readonly InventoryCostAllocationService $allocations,
        private readonly JournalPostingService $journals,
        private readonly CommissionCalculationService $commissions,
        private readonly AuditLogger $audit,
    ) {}

    public function cancel(PhysicalSale $sale, Warehouse $warehouse, string $date, string $reason, User $actor, Request $request): PhysicalSale
    {
        return DB::transaction(function () use ($sale, $warehouse, $date, $reason, $actor, $request): PhysicalSale {
            $sale = PhysicalSale::query()->with('lines')->lockForUpdate()->findOrFail($sale->id);
            if ((int) $sale->warehouse_id !== (int) $warehouse->id || $sale->status !== 'POSTED') {
                throw ValidationException::withMessages(['physical_sale' => 'ยกเลิกได้เฉพาะ HS/IV ที่ Post แล้วในคลังที่เลือก']);
            }
            if ($sale->reversal_status === 'REVERSED') {
                return $sale;
            }
            $this->commissions->assertPhysicalSaleCanBeReversed($sale);
            if ($date < $sale->posting_date?->format('Y-m-d')) {
                throw ValidationException::withMessages(['reversal_date' => 'วันที่กลับรายการต้องไม่ก่อนวันที่ Post HS/IV ต้นทาง']);
            }
            if (SalesReturn::query()->where('physical_sale_id', $sale->id)->where('status', 'POSTED')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['physical_sale' => 'HS/IV มีใบรับคืนที่ Post แล้ว จึงยกเลิกทั้งใบซ้ำไม่ได้']);
            }
            $draftReturns = SalesReturn::query()->where('physical_sale_id', $sale->id)->where('status', 'DRAFT')->lockForUpdate()->get();
            foreach ($draftReturns as $draftReturn) {
                $before = $draftReturn->only(['status', 'void_reason', 'updated_by']);
                $draftReturn->forceFill([
                    'status' => 'VOID', 'void_reason' => 'ยกเลิกอัตโนมัติจากการยกเลิก HS/IV ทั้งใบ', 'updated_by' => $actor->id,
                ])->save();
                $this->audit->record('pos.sales-return.voided-by-source-cancellation', $draftReturn, $before, $draftReturn->only(array_keys($before)), $actor, $request);
            }
            if ($sale->document_type === 'IV') {
                $this->assertNoPostedReceipts($sale);
            }
            if (! $sale->cogs_journal_entry_id) {
                throw ValidationException::withMessages(['physical_sale' => 'HS/IV ไม่มี Journal ต้นทุนสำหรับกลับรายการ']);
            }

            $cogs = JournalEntry::query()->lockForUpdate()->findOrFail($sale->cogs_journal_entry_id);
            $return = $this->createReturn($sale, $warehouse, $date, $reason, $actor);

            if ($sale->journal_entry_id) {
                $revenue = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($sale->journal_entry_id);
                $this->assertRevenueJournal($revenue, $sale, $warehouse);
                if ($sale->document_type === 'HS') {
                    $reversal = $this->journals->reverseWithinTransaction($revenue, [
                        'source_type' => 'POS', 'source_id' => "physical-sale-cancel:{$sale->id}:sale", 'reversal_date' => $date, 'reason' => $reason,
                    ], $actor);
                    $this->advanceApplications->reversePhysicalSaleApplications($sale, $reversal, $date, $reason, $actor);
                    $return->forceFill(['journal_entry_id' => $reversal->id])->save();
                } else {
                    // A Credit Note event (not a generic JE reversal) produces an AR
                    // CREDIT OpenItem, which is allocated to the original invoice.
                    $credit = $this->postCreditNote($return, $sale, $revenue, $warehouse, $date, $actor);
                    $return->forceFill(['journal_entry_id' => $credit->id])->save();
                }
            }
            $reversalCogs = $this->journals->reverseWithinTransaction($cogs, [
                'source_type' => 'POS', 'source_id' => "physical-sale-cancel:{$sale->id}:cogs", 'reversal_date' => $date, 'reason' => $reason,
            ], $actor);
            $return->forceFill(['cogs_journal_entry_id' => $reversalCogs->id])->save();
            $this->returnStockAndLinkCosts($sale, $return, $reversalCogs, $date, $actor);

            $return->forceFill(['status' => 'POSTED', 'posting_date' => $date, 'posted_by' => $actor->id, 'posted_at' => now()])->save();
            $this->commissions->reverseForPostedReturn($return->fresh(), $actor, "ยกเลิก {$sale->document_number}");
            $before = $sale->only(['status', 'reversal_status', 'cancellation_return_id', 'void_reason']);
            $sale->forceFill(['status' => 'VOID', 'reversal_status' => 'REVERSED', 'cancellation_return_id' => $return->id,
                'reversal_revision' => (int) $sale->reversal_revision + 1, 'reversal_key' => "physical-sale-cancel:{$sale->id}",
                'void_reason' => $reason, 'voided_by' => $actor->id, 'voided_at' => now(), 'updated_by' => $actor->id])->save();
            $this->audit->record('pos.physical-sale.cancelled', $sale, $before, $sale->fresh()->only(array_keys($before)), $actor, $request);

            return $sale->fresh();
        }, 3);
    }

    private function createReturn(PhysicalSale $sale, Warehouse $warehouse, string $date, string $reason, User $actor): SalesReturn
    {
        $existing = SalesReturn::query()->where('physical_sale_id', $sale->id)->where('reversal_key', "physical-sale-cancel:{$sale->id}")->lockForUpdate()->first();
        if ($existing) {
            return $existing;
        }
        $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => 'SALES_RETURN', 'is_active' => true])->lockForUpdate()->first();
        if (! $sequence) {
            throw ValidationException::withMessages(['document_type' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร Sales Return']);
        }
        $number = $this->sequences->issueForBranch($sequence, Branch::query()->findOrFail($sale->branch_id), Carbon::parse($date));
        $return = SalesReturn::query()->create(['warehouse_id' => $warehouse->id, 'physical_sale_id' => $sale->id, 'document_number' => $number,
            'document_date' => $date, 'reason' => $reason, 'party_code' => $sale->party_code, 'party_name' => $sale->party_name,
            'party_address' => $sale->party_address, 'total_amount' => $sale->total_amount, 'status' => 'DRAFT',
            'reversal_key' => "physical-sale-cancel:{$sale->id}", 'reversal_revision' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
        foreach ($sale->lines as $line) {
            $return->lines()->create(['physical_sale_line_id' => $line->id, 'line_number' => $line->line_number,
                'item_id' => $line->item_id, 'uom_id' => $line->sale_uom_id, 'stock_uom_id' => $line->stock_uom_id, 'quantity' => $line->quantity,
                'stock_quantity' => $line->stock_quantity, 'uom_factor' => $line->uom_factor, 'unit_price' => $line->unit_price,
                'line_total' => $line->line_total, 'conversion_snapshot' => $line->conversion_snapshot, 'item_snapshot' => $line->item_snapshot]);
        }
        $this->sequences->recordIssued($sequence, $number, 'pos_sales_returns', $return->id, Carbon::parse($date), $actor->id);

        return $return;
    }

    private function postCreditNote(SalesReturn $return, PhysicalSale $sale, JournalEntry $revenue, Warehouse $warehouse, string $date, User $actor): JournalEntry
    {
        $journal = $this->journals->postWithinTransaction(['source_type' => 'POS', 'source_id' => "sales-return:{$return->id}", 'source_reference' => $return->document_number,
            'event_code' => 'sales_credit_note', 'entry_date' => $date, 'document_date' => $date, 'description' => "ยกเลิก {$sale->document_number}",
            'posting_metadata' => $this->originalRevenueMetadata($revenue),
            'lines' => $revenue->lines->map(fn (JournalEntryLine $line) => ['account_id' => $line->account_id, 'tax_code_id' => $line->tax_code_id,
                'subledger_type' => $line->subledger_type, 'subledger_id' => $line->subledger_id, 'description' => $return->document_number,
                'debit' => $line->credit, 'credit' => $line->debit, 'tax_base' => $line->tax_base, 'tax_amount' => $line->tax_amount,
                'tax_point_date' => $date, 'tax_settlement_date' => $line->tax_settlement_date])->all()], $warehouse, $actor);
        $creditLine = $journal->lines()->where('subledger_type', 'CUSTOMER')->where('subledger_id', (string) $sale->party_id)->where('credit', $sale->total_amount)->sole();
        $creditItem = $this->openItems->recordFromJournalLine($creditLine, ['document_type' => 'CREDIT_NOTE', 'document_number' => $return->document_number]);
        $invoice = OpenItem::query()->where('document_type', 'INVOICE')->where('balance_side', 'DEBIT')->where('party_id', $sale->party_id)
            ->whereHas('journalEntryLine', fn ($q) => $q->where('journal_entry_id', $sale->journal_entry_id))->lockForUpdate()->sole();
        $this->openItems->allocate(['debit_open_item_id' => $invoice->id, 'credit_open_item_id' => $creditItem->id, 'allocation_date' => $date,
            'amount' => $sale->total_amount, 'source_type' => 'POS', 'source_id' => "physical-sale-cancel:{$sale->id}"], $actor);

        return $journal;
    }

    private function originalRevenueMetadata(JournalEntry $revenue): array
    {
        $original = collect(data_get($revenue->posting_metadata, 'accounts', []))
            ->filter(fn (array $account): bool => $revenue->lines->contains('account_id', (int) ($account['account_id'] ?? 0)))
            ->map(function (array $account) use ($revenue): array {
                $account['event_code'] = 'sales_credit_note';
                $account['source'] = 'ORIGINAL';
                $account['source_type'] = 'JOURNAL_ENTRY';
                $account['source_id'] = (string) $revenue->id;
                $account['mapping_id'] = null;
                $account['mapping_version'] = null;

                return $account;
            })
            ->unique('account_role')
            ->values();
        if ($original->isEmpty()) {
            $original = $revenue->lines->pluck('account_id')->unique()->values()->map(fn (int $accountId): array => [
                'event_code' => 'sales_credit_note', 'account_role' => 'ORIGINAL_ACCOUNT_'.$accountId, 'account_id' => $accountId,
                'source' => 'ORIGINAL', 'source_type' => 'JOURNAL_ENTRY', 'source_id' => (string) $revenue->id,
                'mapping_id' => null, 'mapping_version' => null,
            ]);
        }

        return ['contract_version' => 1, 'event_code' => 'sales_credit_note', 'accounts' => $original->all()];
    }

    private function assertNoPostedReceipts(PhysicalSale $sale): void
    {
        $invoice = OpenItem::query()->where('document_type', 'INVOICE')->where('balance_side', 'DEBIT')
            ->whereHas('journalEntryLine', fn ($q) => $q->where('journal_entry_id', $sale->journal_entry_id))->lockForUpdate()->sole();
        $hasPostedReceipt = Settlement::query()
            ->where('document_type', 'RECEIPT')
            ->where('status', 'POSTED')
            ->whereHas('allocationIntents', fn ($query) => $query->where('open_item_id', $invoice->id))
            ->lockForUpdate()
            ->exists();
        if ($hasPostedReceipt) {
            throw ValidationException::withMessages(['receipt' => 'ไม่สามารถยกเลิก IV ได้ เพราะมีการรับชำระหนี้แล้ว กรุณายกเลิกเอกสารรับชำระหนี้ก่อน']);
        }
    }

    private function returnStockAndLinkCosts(PhysicalSale $sale, SalesReturn $return, JournalEntry $reversalCogs, string $date, User $actor): void
    {
        $returnLines = $return->lines()->get()->keyBy('physical_sale_line_id');
        $movements = StockMovement::query()->where(['warehouse_id' => $sale->warehouse_id, 'source_type' => 'POS', 'source_id' => (string) $sale->id, 'status' => 'POSTED'])->lockForUpdate()->get();
        foreach ($movements as $movement) {
            $sourceAllocations = CostAllocation::query()->where('stock_movement_id', $movement->id)->where('status', 'POSTED')->where('cost_status', 'FINAL')->lockForUpdate()->get();
            $reversal = $this->movements->reverseWithinTransaction($movement, ['idempotency_key' => "physical-sale-cancel:{$sale->id}:movement:{$movement->id}", 'business_date' => $date, 'created_by' => $actor->id]);
            foreach ($sourceAllocations as $source) {
                $allocation = $this->allocations->reverseWithinTransaction($source, $reversal, [
                    'idempotency_key' => "physical-sale-cancel:{$sale->id}:allocation:{$source->id}",
                ]);
                $sourceLineId = CostAllocationJournalLine::query()->where('allocation_id', $source->id)->value('journal_entry_line_id');
                $line = $sourceLineId ? $reversalCogs->lines()->where('line_number', JournalEntryLine::query()->findOrFail($sourceLineId)->line_number)->first() : null;
                if (! $line) {
                    throw ValidationException::withMessages(['journal' => 'ไม่พบ COGS reversal line']);
                }
                $this->allocations->linkJournalLineWithinTransaction($allocation, $line);
                $saleLineId = data_get($movement->metadata, 'physical_sale_line_id');
                $returnLine = $returnLines->get($saleLineId);
                if (! $returnLine) {
                    throw ValidationException::withMessages(['stock' => 'Movement ไม่มีบรรทัด Sales Return ที่ตรงกัน']);
                }
                SalesReturnInventoryLink::query()->firstOrCreate(['sales_return_line_id' => $returnLine->id, 'source_stock_movement_id' => $movement->id, 'source_cost_allocation_id' => $source->id], ['reversal_stock_movement_id' => $reversal->id, 'reversal_cost_allocation_id' => $allocation->id]);
            }
        }
    }

    private function assertRevenueJournal(JournalEntry $journal, PhysicalSale $sale, Warehouse $warehouse): void
    {
        if ($journal->status !== 'POSTED' || $journal->source_type !== 'POS' || (string) $journal->source_id !== (string) $sale->id || (int) $journal->warehouse_id !== (int) $warehouse->id) {
            throw ValidationException::withMessages(['journal' => 'Journal รายได้ต้นทางไม่ตรงกับ HS/IV']);
        }
    }
}
