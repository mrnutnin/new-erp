<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Services\AdvanceDepositApplicationService;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Support\PhysicalSaleCogsPostingContract;
use App\Modules\Pos\Support\PhysicalSalePostingReadiness;
use App\Modules\Pos\Support\PhysicalSaleRevenuePostingPlan;
use App\Modules\Pos\Support\PhysicalSaleStockPostingIntent;
use App\Modules\Pos\Support\PhysicalSaleWithholdingSnapshot;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Services\InventoryCostAllocationService;
use App\Modules\Wms\Services\StockMovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Posts the immutable stock, COGS and revenue effects of one HS/IV draft together. */
final class PhysicalSalePostingService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly InventoryCostAllocationService $allocations,
        private readonly JournalPostingService $journals,
        private readonly OpenItemService $openItems,
        private readonly AdvanceDepositApplicationService $advanceApplications,
        private readonly PhysicalSaleRevenuePostingPlan $revenue,
        private readonly CommissionCalculationService $commissions,
        private readonly AuditLogger $audit,
    ) {}

    public function post(PhysicalSale $sale, string $postingDate, Warehouse $warehouse, User $actor, Request $request, array $tenders = []): PhysicalSale
    {
        return DB::transaction(function () use ($sale, $postingDate, $warehouse, $actor, $request, $tenders): PhysicalSale {
            $sale = PhysicalSale::query()->lockForUpdate()->findOrFail($sale->id);
            if ((int) $sale->warehouse_id !== (int) $warehouse->id) {
                throw ValidationException::withMessages(['warehouse' => 'HS/IV ไม่ได้อยู่ในคลังที่เลือก']);
            }
            if ($sale->status === 'POSTED') {
                if ($sale->posting_date?->format('Y-m-d') !== $postingDate) {
                    throw ValidationException::withMessages(['posting_date' => 'HS/IV นี้ Post ด้วยวันที่อื่นแล้ว ไม่สามารถ Post ซ้ำด้วยข้อมูลคนละชุด']);
                }

                return $sale;
            }

            $lines = $sale->lines()->lockForUpdate()->get();
            $payload = $this->salePayload($sale, $lines, $postingDate);
            PhysicalSalePostingReadiness::assertReady($payload);
            $withholding = $this->withholdingSnapshot($sale);
            if (! $sale->due_date && $sale->document_type === 'IV') {
                throw ValidationException::withMessages(['due_date' => 'ใบขายเชื่อต้องมีวันครบกำหนดชำระก่อนลงบัญชี']);
            }
            // Legacy HS drafts were created before due_date existed. A cash
            // sale is due on its document date; IV must never be guessed.
            $sale->forceFill(['posting_date' => $postingDate, 'due_date' => $sale->due_date ?? $sale->document_date])->save();

            $allocationRows = [];
            foreach (PhysicalSaleStockPostingIntent::build($payload) as $intent) {
                $movement = $this->stock->recordIntent([...$intent, 'created_by' => $actor->id]);
                $movement = $this->stock->postWithinTransaction($movement);
                $allocations = CostAllocation::query()
                    ->where('stock_movement_id', $movement->id)
                    ->where('status', 'PENDING')->where('cost_status', 'FINAL')->whereNull('journal_entry_id')
                    ->orderBy('id')->lockForUpdate()->get();
                if ($allocations->isEmpty()) {
                    throw ValidationException::withMessages(['stock' => "ไม่พบต้นทุน FINAL สำหรับรายการ {$intent['line_number']}"]);
                }
                foreach ($allocations as $allocation) {
                    $item = Item::query()->with(['inventoryAccount.type', 'cogsAccount.type'])->lockForUpdate()->findOrFail($allocation->item_id);
                    $allocationRows[] = PhysicalSaleCogsPostingContract::build($payload, $allocation, $movement, $item);
                }
            }

            $cogs = $this->postCogs($sale, $postingDate, $warehouse, $actor, $allocationRows);
            $journal = null;
            if (JournalBalance::decimal($sale->total_amount) !== '0.00') {
                $advanceLines = $sale->document_type === 'HS'
                    ? $this->advanceApplications->applyToPhysicalSale($sale, (array) $request->input('advance_allocations', []), $postingDate, $actor)
                    : [];
                $revenue = $this->revenue->build($sale->fresh(), $tenders, $advanceLines);
                $journal = $this->journals->postWithinTransaction($revenue['journal'], $warehouse, $actor);
                if ($advanceLines !== []) {
                    DB::table('finance_advance_deposit_applications')
                        ->where('physical_sale_id', $sale->id)->whereNull('journal_entry_id')
                        ->update(['journal_entry_id' => $journal->id]);
                }
                if ($sale->document_type === 'IV') {
                    $arLine = $journal->lines()->where('account_id', $revenue['ar_account_id'])
                        ->where('subledger_type', 'CUSTOMER')->where('subledger_id', (string) $sale->party_id)->first();
                    if (! $arLine || $arLine->debit !== $sale->total_amount || $arLine->credit !== '0.00') {
                        throw ValidationException::withMessages(['journal' => 'Journal รายได้ต้องมี AR ของลูกค้าเพียงหนึ่งบรรทัด']);
                    }
                    $this->openItems->recordFromJournalLine($arLine, [
                        'document_type' => 'INVOICE', 'document_number' => $sale->document_number,
                        'due_date' => $sale->due_date->format('Y-m-d'),
                        ...$withholding,
                    ]);
                }
                if ($sale->document_type === 'HS') {
                    foreach (array_values($tenders) as $index => $tender) {
                        $sale->tenders()->create(['bank_account_id' => $tender['bank_account_id'], 'line_number' => $index + 1, 'amount' => JournalBalance::decimal($tender['amount']), 'reference' => $tender['reference'] ?? null]);
                    }
                }
            }

            $before = $sale->only(['status', 'posting_date', 'journal_entry_id', 'cogs_journal_entry_id', 'posted_by', 'posted_at']);
            $sale->forceFill([
                'status' => 'POSTED', 'posting_date' => $postingDate,
                'journal_entry_id' => $journal?->id, 'cogs_journal_entry_id' => $cogs->id,
                'posted_by' => $actor->id, 'posted_at' => now(), 'updated_by' => $actor->id,
            ])->save();
            $this->commissions->calculatePostedSale($sale);
            $this->audit->record('pos.physical-sale.posted', $sale, $before, $sale->only(array_keys($before)), $actor, $request);

            return $sale->fresh();
        }, 3);
    }

    private function salePayload(PhysicalSale $sale, $lines, string $postingDate): array
    {
        return [
            'id' => $sale->id, 'physical_sale_id' => $sale->id, 'status' => $sale->status, 'warehouse_id' => $sale->warehouse_id,
            'document_type' => $sale->document_type, 'document_number' => $sale->document_number,
            'source_type' => $sale->source_type, 'source_id' => $sale->source_id,
            'document_date' => $sale->document_date->format('Y-m-d'), 'posting_date' => $postingDate,
            'business_date' => $postingDate, 'total_amount' => $sale->total_amount, 'tax_amount' => $sale->tax_amount,
            'journal_entry_id' => $sale->journal_entry_id, 'cogs_journal_entry_id' => $sale->cogs_journal_entry_id,
            'lines' => $lines->map(function ($line) use ($postingDate): array {
                // Older drafts stored only a partial conversion snapshot. The
                // line's immutable columns remain authoritative for posting.
                $snapshot = array_replace(is_array($line->conversion_snapshot) ? $line->conversion_snapshot : [], [
                    'purchase_uom_id' => (int) $line->sale_uom_id,
                    'stock_uom_id' => (int) $line->stock_uom_id,
                    'factor' => (string) $line->uom_factor,
                    'business_date' => $postingDate,
                ]);

                return [
                    'line_id' => $line->id, 'line_number' => $line->line_number, 'item_id' => $line->item_id,
                    'uom_id' => $line->sale_uom_id, 'stock_uom_id' => $line->stock_uom_id,
                    'quantity' => $line->quantity, 'stock_quantity' => $line->stock_quantity,
                    'uom_factor' => $line->uom_factor, 'factor' => $line->uom_factor,
                    'conversion_snapshot' => $snapshot, 'line_total' => $line->line_total,
                ];
            })->all(),
        ];
    }

    /** @return array{withholding_tax_code_id:?int,withholding_rate:string,withholding_base:string,withholding_amount:string} */
    private function withholdingSnapshot(PhysicalSale $sale): array
    {
        return PhysicalSaleWithholdingSnapshot::assertStored(
            $sale->withholding_tax_code_id, $sale->withholding_rate, $sale->withholding_base,
            $sale->withholding_amount, JournalBalance::subtract($sale->subtotal, $sale->discount_amount),
        );
    }

    /** @param list<array{allocation_id:int,payload:array<string,mixed>}> $rows */
    private function postCogs(PhysicalSale $sale, string $postingDate, Warehouse $warehouse, User $actor, array $rows): JournalEntry
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['stock' => 'HS/IV ไม่มี Cost Allocation สำหรับสร้าง COGS']);
        }
        $journal = $this->journals->postWithinTransaction([
            'source_type' => 'POS', 'source_id' => (string) $sale->id, 'source_reference' => $sale->document_number,
            'event_code' => 'sales_cogs', 'entry_date' => $postingDate,
            'document_date' => $sale->document_date->format('Y-m-d'), 'description' => "COGS {$sale->document_number}",
            'lines' => collect($rows)->flatMap(fn (array $row) => $row['payload']['lines'])->all(),
        ], $warehouse, $actor);
        $journalLines = $journal->lines()->orderBy('line_number')->get()->values();
        foreach ($rows as $index => $row) {
            $inventoryLine = $journalLines->get(($index * 2) + 1);
            if (! $inventoryLine) {
                throw ValidationException::withMessages(['journal' => 'COGS Journal ไม่มีบรรทัด Inventory ครบทุกต้นทุน']);
            }
            $allocation = CostAllocation::query()->lockForUpdate()->findOrFail($row['allocation_id']);
            $this->allocations->linkJournalLineWithinTransaction($allocation, $inventoryLine);
        }

        return $journal;
    }
}
