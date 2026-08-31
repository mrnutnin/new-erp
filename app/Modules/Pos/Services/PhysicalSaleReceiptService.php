<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Models\WithholdingRealization;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Finance\Services\SettlementPostingService;
use App\Modules\Finance\Support\WhtRealizationCalculator;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\PhysicalSale;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Creates and posts one POS receipt through the Finance settlement contract. */
final class PhysicalSaleReceiptService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly OpenItemService $openItems,
        private readonly SettlementPostingService $posting,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{settlement_date:string,allocation_amount:string|int|float,tenders:list<array{bank_account_id:int,amount:string|int|float,reference?:?string}>,document_date?:?string,description?:?string}  $input
     */
    public function receive(PhysicalSale $sale, array $input, Warehouse $warehouse, User $actor, Request $request): Settlement
    {
        $values = Validator::make($input, [
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'settlement_date' => ['required', 'date_format:Y-m-d'],
            'allocation_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'tenders' => ['required', 'array', 'min:1', 'max:20'],
            'tenders.*.bank_account_id' => ['required', 'integer', 'min:1'],
            'tenders.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($sale, $values, $warehouse, $actor, $request): Settlement {
            $sale = PhysicalSale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ((int) $sale->warehouse_id !== (int) $warehouse->id || $sale->document_type !== 'IV' || $sale->status !== 'POSTED' || ! $sale->journal_entry_id) {
                throw ValidationException::withMessages(['physical_sale' => 'รับชำระหนี้ได้เฉพาะใบขายเชื่อ (IV) ที่ Post แล้ว']);
            }

            $openItem = OpenItem::query()
                ->where('warehouse_id', $warehouse->id)->where('party_id', $sale->party_id)
                ->where('ledger_type', 'AR')->where('party_type', 'CUSTOMER')->where('balance_side', 'DEBIT')
                ->where('document_type', 'INVOICE')->where('document_number', $sale->document_number)
                ->whereHas('journalEntryLine', fn ($query) => $query->where('journal_entry_id', $sale->journal_entry_id))
                ->lockForUpdate()->firstOrFail();
            $date = (string) $values['settlement_date'];
            $remaining = $this->openItems->remainingAt($openItem, $date);
            $allocation = JournalBalance::decimal($values['allocation_amount']);
            if ($allocation > $remaining) {
                throw ValidationException::withMessages(['allocation_amount' => 'ยอดรับชำระเกินยอดคงเหลือของ HS/IV']);
            }
            $withholding = $this->withholdingFor($openItem, $allocation, $date);
            $cash = collect($values['tenders'])->reduce(fn (string $total, array $tender) => JournalBalance::add($total, $tender['amount']), '0.00');
            $gross = JournalBalance::add($cash, $withholding);
            if ($allocation > $gross) {
                throw ValidationException::withMessages(['tenders' => 'ยอดเงินรับรวม WHT ต้องไม่น้อยกว่ายอดที่ต้องการตัดชำระ']);
            }

            $documentDate = (string) ($values['document_date'] ?? $date);
            if ($documentDate > $date) {
                throw ValidationException::withMessages(['settlement_date' => 'วันที่รับชำระต้องไม่ก่อนวันที่เอกสารรับเงิน']);
            }
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'RECEIPT')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_type' => 'ยังไม่ได้ตั้งค่าเลขเอกสารรับเงินสำหรับคลังนี้']);
            }
            $number = $this->sequences->issueForBranch($sequence, $sale->branch, Carbon::parse($documentDate));
            $settlement = Settlement::query()->create([
                'document_type' => 'RECEIPT', 'document_number' => $number,
                'document_date' => $documentDate, 'settlement_date' => $date,
                'party_type' => 'CUSTOMER', 'party_id' => $sale->party_id,
                'bank_account_id' => $values['tenders'][0]['bank_account_id'],
                'gross_amount' => $gross, 'tax_amount' => '0.00',
                'withholding_amount' => $withholding, 'net_amount' => $cash,
                'status' => 'DRAFT', 'description' => $values['description'] ?? "รับชำระ {$sale->document_number}",
                'created_by' => $actor->id,
            ]);
            $settlement->tenders()->createMany(collect($values['tenders'])->values()->map(fn (array $tender, int $index) => [
                'bank_account_id' => $tender['bank_account_id'], 'line_number' => $index + 1,
                'amount' => JournalBalance::decimal($tender['amount']), 'reference' => $tender['reference'] ?? null,
            ])->all());
            $intent = $settlement->allocationIntents()->create(['open_item_id' => $openItem->id, 'line_number' => 1, 'amount' => $allocation]);
            $this->sequences->recordIssued($sequence, $number, 'finance_settlements', $settlement->id, Carbon::parse($documentDate), $actor->id);
            $settlement->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'approval_reason' => 'POS รับชำระทันที']);

            $posted = $this->posting->post($settlement, $warehouse, $actor, $request);
            $this->audit->record('pos.physical-sale.receipt-posted', $sale, [], [
                'settlement_id' => $posted->id, 'settlement_number' => $posted->document_number,
                'allocation_intent_id' => $intent->id, 'allocation_amount' => $allocation,
                'withholding_amount' => $withholding, 'cash_amount' => $cash,
            ], $actor, $request);

            return $posted;
        }, 3);
    }

    private function withholdingFor(OpenItem $item, string $allocation, string $date): string
    {
        if (! $item->withholding_tax_code_id || JournalBalance::decimal($item->withholding_amount) === '0.00') {
            return '0.00';
        }

        $allocated = Allocation::query()
            ->where(fn ($query) => $query->where('debit_open_item_id', $item->id)->orWhere('credit_open_item_id', $item->id))
            ->where('allocation_date', '<=', $date)->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $date))->sum('amount');
        $realized = WithholdingRealization::query()
            ->where('open_item_id', $item->id)->where('settlement_date', '<=', $date)
            ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $date))->sum('tax_amount');

        return WhtRealizationCalculator::calculate(
            $item->original_amount, $item->withholding_base, $item->withholding_amount,
            $allocation, (string) $allocated, (string) $realized,
        )['tax'];
    }
}
