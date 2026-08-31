<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesReturn;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Financial-only Sales Return posting. Inventory/COGS is deliberately owned by the next contract. */
final class SalesReturnPostingService
{
    public function __construct(private readonly JournalPostingService $journals, private readonly OpenItemService $openItems, private readonly SalesReturnInventoryPostingService $inventory, private readonly CommissionCalculationService $commissions, private readonly AuditLogger $audit) {}

    public function post(SalesReturn $return, string $date, Warehouse $warehouse, User $actor, Request $request, ?int $refundBankAccountId = null): SalesReturn
    {
        return DB::transaction(function () use ($return, $date, $warehouse, $actor, $request, $refundBankAccountId): SalesReturn {
            $return = SalesReturn::query()->with('lines')->lockForUpdate()->findOrFail($return->id);
            if ($return->status === 'POSTED') {
                if ($return->posting_date?->format('Y-m-d') !== $date) {
                    throw ValidationException::withMessages(['posting_date' => 'Sales Return นี้ Post ด้วยวันที่อื่นแล้ว']);
                }

                return $return;
            }
            if ($return->status !== 'DRAFT' || $return->warehouse_id !== $warehouse->id || $return->lines->isEmpty()) {
                throw ValidationException::withMessages(['sales_return' => 'Post ได้เฉพาะ Sales Return ร่างที่มีรายการในคลังที่เลือก']);
            }
            $sale = PhysicalSale::query()->with('lines')->whereKey($return->physical_sale_id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== 'POSTED' || $sale->warehouse_id !== $warehouse->id || $sale->party_id <= 0 || ! $sale->journal_entry_id) {
                throw ValidationException::withMessages(['physical_sale' => 'เอกสารขายต้นทางไม่พร้อมทำ Sales Return']);
            }
            if ($date < $sale->posting_date?->format('Y-m-d')) {
                throw ValidationException::withMessages(['posting_date' => 'วันที่ Post ใบรับคืนต้องไม่ก่อนวันที่ Post HS/IV ต้นทาง']);
            }
            $this->commissions->assertPhysicalSaleCanBeReversed($sale);
            $this->assertReturnFits($return, $sale);
            $journal = JournalEntry::query()->with('lines')->whereKey($sale->journal_entry_id)->lockForUpdate()->firstOrFail();
            $cashRefund = null;
            if ($sale->document_type === 'HS') {
                if (DB::table('finance_advance_deposit_applications')->where('physical_sale_id', $sale->id)->whereNull('reversed_at')->exists()) {
                    throw ValidationException::withMessages(['physical_sale' => 'HS ที่ตัดเงินรับล่วงหน้าแล้วต้องย้อนรายการตัดเงินรับล่วงหน้าก่อนทำ Sales Return']);
                }
                $cashRefund = $this->postCashRefund($return, $sale, $journal, $date, $warehouse, $actor, $refundBankAccountId);
                $posted = $cashRefund['journal'];
            } else {
                $posted = $this->postCreditNote($return, $sale, $journal, $date, $warehouse, $actor);
            }
            $cogs = $this->inventory->postWithinTransaction($return, $warehouse, $date, $actor);
            $before = $return->only(['status', 'posting_date', 'journal_entry_id', 'posted_by', 'posted_at']);
            $return->update(['status' => 'POSTED', 'posting_date' => $date, 'journal_entry_id' => $posted->id, 'cogs_journal_entry_id' => $cogs->id, 'refund_bank_account_id' => $cashRefund['bank_account_id'] ?? null, 'refund_amount' => $cashRefund['amount'] ?? '0.00', 'posted_by' => $actor->id, 'posted_at' => now(), 'updated_by' => $actor->id]);
            $this->commissions->reverseForPostedReturn($return->fresh(), $actor, "Sales Return {$return->document_number}");
            $this->audit->record('pos.sales-return.posted.financial', $return, $before, $return->fresh()->only(array_keys($before)), $actor, $request);

            return $return->fresh();
        }, 3);
    }

    private function assertReturnFits(SalesReturn $return, PhysicalSale $sale): void
    {
        $source = $sale->lines->keyBy('id');
        $already = SalesReturn::query()->where('physical_sale_id', $sale->id)->where('status', 'POSTED')->whereKeyNot($return->id)->with('lines')->lockForUpdate()->get()->flatMap->lines->groupBy('physical_sale_line_id');
        $total = '0.00';
        foreach ($return->lines as $line) {
            $saleLine = $source->get($line->physical_sale_line_id);
            if (! $saleLine || (float) $line->quantity <= 0 || (float) $line->quantity + (float) ($already->get($line->physical_sale_line_id)?->sum('quantity') ?? 0) > (float) $saleLine->quantity || JournalBalance::decimal($line->line_total) !== JournalBalance::decimal((string) ((float) $line->quantity * (float) $line->unit_price))) {
                throw ValidationException::withMessages(['lines' => 'จำนวนหรือยอด Sales Return เกินเอกสารขายต้นทาง']);
            }
            $total = JournalBalance::add($total, $line->line_total);
        }
        if (JournalBalance::decimal($return->total_amount) !== $total || $total === '0.00') {
            throw ValidationException::withMessages(['total_amount' => 'ยอด Sales Return ไม่ตรงกับบรรทัด']);
        }
    }

    /** @return array{journal: JournalEntry, bank_account_id: int, amount: string} */
    private function postCashRefund(SalesReturn $return, PhysicalSale $sale, JournalEntry $source, string $date, Warehouse $warehouse, User $actor, ?int $refundBankAccountId): array
    {
        $bank = BankAccount::query()->whereKey($refundBankAccountId)->where('warehouse_id', $warehouse->id)->where('is_active', true)->lockForUpdate()->first();
        if (! $bank) {
            throw ValidationException::withMessages(['refund_bank_account_id' => 'กรุณาเลือกบัญชีเงินสด/ธนาคารสำหรับคืนเงิน']);
        }
        $tenders = $sale->tenders()->with('bankAccount')->lockForUpdate()->get();
        if ($tenders->isEmpty()) {
            throw ValidationException::withMessages(['physical_sale' => 'HS ต้นทางไม่มีช่องทางรับเงินสำหรับคำนวณยอดคืน']);
        }
        $ratio = BigDecimal::of((string) $return->total_amount)->dividedBy(BigDecimal::of((string) $sale->total_amount), 12, RoundingMode::HALF_UP);
        $cashAccountIds = $tenders->pluck('bankAccount.account_id')->filter()->unique()->all();
        $lines = $source->lines->reject(fn (JournalEntryLine $line): bool => in_array($line->account_id, $cashAccountIds, true) && JournalBalance::decimal($line->debit) !== '0.00')
            ->map(fn (JournalEntryLine $line): array => ['account_id' => $line->account_id, 'tax_code_id' => $line->tax_code_id, 'subledger_type' => $line->subledger_type, 'subledger_id' => $line->subledger_id, 'description' => $return->document_number, 'debit' => $this->exactScaled($line->credit, $ratio), 'credit' => $this->exactScaled($line->debit, $ratio), 'tax_base' => $this->exactScaled($line->tax_base, $ratio), 'tax_amount' => $this->exactScaled($line->tax_amount, $ratio), 'tax_point_date' => $date])->values()->all();
        $refundAmount = collect($lines)->reduce(
            fn (string $total, array $line): string => JournalBalance::add($total, JournalBalance::subtract($line['debit'], $line['credit'])),
            '0.00',
        );
        if ($refundAmount === '0.00' || str_starts_with($refundAmount, '-')) {
            throw ValidationException::withMessages(['total_amount' => 'ไม่สามารถคำนวณยอดคืนเงินจากรายการขายต้นทางได้']);
        }
        $lines[] = ['account_id' => $bank->account_id, 'subledger_type' => strtoupper($bank->type), 'subledger_id' => (string) $bank->id, 'description' => $return->document_number, 'debit' => '0.00', 'credit' => $refundAmount, 'tax_base' => '0.00', 'tax_amount' => '0.00', 'tax_point_date' => $date];
        $totals = JournalBalance::totals($lines);
        if ($totals['debit'] !== $totals['credit']) {
            throw ValidationException::withMessages(['total_amount' => 'ยอดคืนเงินไม่สมดุลกับรายการขายต้นทาง']);
        }
        $journal = $this->journals->postWithinTransaction(['source_type' => 'POS', 'source_id' => "sales-return:{$return->id}:hs", 'source_reference' => $return->document_number, 'event_code' => 'sales_credit_note', 'entry_date' => $date, 'document_date' => $date, 'description' => "คืนเงิน {$sale->document_number}", 'lines' => $lines], $warehouse, $actor);

        return ['journal' => $journal, 'bank_account_id' => $bank->id, 'amount' => $refundAmount];
    }

    private function postCreditNote(SalesReturn $return, PhysicalSale $sale, JournalEntry $source, string $date, Warehouse $warehouse, User $actor): JournalEntry
    {
        $invoice = OpenItem::query()->where('document_type', 'INVOICE')->where('balance_side', 'DEBIT')->where('party_id', $sale->party_id)->whereHas('journalEntryLine', fn ($q) => $q->where('journal_entry_id', $sale->journal_entry_id))->lockForUpdate()->sole();
        if ($this->openItems->remainingAt($invoice, $date) !== JournalBalance::decimal($invoice->original_amount)) {
            throw ValidationException::withMessages(['physical_sale' => 'IV ที่มีการรับชำระแล้วต้อง reverse receipt ก่อนทำ Sales Return']);
        }
        $ratio = BigDecimal::of((string) $return->total_amount)->dividedBy(BigDecimal::of((string) $sale->total_amount), 12, RoundingMode::HALF_UP);
        $lines = $source->lines->map(function (JournalEntryLine $line) use ($date, $ratio, $return): array {
            $debit = $this->exactScaled($line->credit, $ratio);
            $credit = $this->exactScaled($line->debit, $ratio);

            return ['account_id' => $line->account_id, 'tax_code_id' => $line->tax_code_id, 'subledger_type' => $line->subledger_type, 'subledger_id' => $line->subledger_id, 'description' => $return->document_number, 'debit' => $debit, 'credit' => $credit, 'tax_base' => $this->exactScaled($line->tax_base, $ratio), 'tax_amount' => $this->exactScaled($line->tax_amount, $ratio), 'tax_point_date' => $date];
        })->all();
        $totals = JournalBalance::totals($lines);
        if ($totals['debit'] !== $totals['credit']) {
            throw ValidationException::withMessages(['total_amount' => 'Sales Return นี้มีสัดส่วน/ภาษีที่ปัดเศษไม่ลงตัว ต้องใช้ return allocation contract']);
        }
        $journal = $this->journals->postWithinTransaction(['source_type' => 'POS', 'source_id' => "sales-return:{$return->id}", 'source_reference' => $return->document_number, 'event_code' => 'sales_credit_note', 'entry_date' => $date, 'document_date' => $date, 'description' => "Sales Return {$sale->document_number}", 'lines' => $lines], $warehouse, $actor);
        $credit = $journal->lines()->where('subledger_type', 'CUSTOMER')->where('subledger_id', (string) $sale->party_id)->where('credit', $return->total_amount)->sole();
        $creditItem = $this->openItems->recordFromJournalLine($credit, ['document_type' => 'CREDIT_NOTE', 'document_number' => $return->document_number]);
        $this->openItems->allocate(['debit_open_item_id' => $invoice->id, 'credit_open_item_id' => $creditItem->id, 'allocation_date' => $date, 'amount' => $return->total_amount, 'source_type' => 'POS', 'source_id' => "sales-return:{$return->id}"], $actor);

        return $journal;
    }

    private function exactScaled(mixed $amount, BigDecimal $ratio): string
    {
        return BigDecimal::of((string) ($amount ?? '0'))->multipliedBy($ratio)->toScale(2, RoundingMode::HALF_UP)->__toString();
    }
}
