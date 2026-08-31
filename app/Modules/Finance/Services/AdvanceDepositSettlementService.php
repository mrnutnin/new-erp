<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\AdvanceDeposit;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Support\AdvanceDepositContract;
use App\Modules\Finance\Support\AdvanceDepositPostingContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Boundary for the future Settlement -> advance/deposit posting adapter.
 *
 * It deliberately only validates and returns a locked source. Creating a
 * subledger row here before the advance GL mapping/reversal contract exists
 * would make the cash and advance balances impossible to reconcile.
 */
final class AdvanceDepositSettlementService
{
    public function __construct(private readonly AccountMappingService $mappings, private readonly JournalPostingService $journals) {}

    /** Post an approved, unapplied Settlement directly as an Advance/Deposit. */
    public function postSettlementAsAdvance(Settlement $settlement, Warehouse $warehouse, string $instrumentType, ?User $actor = null): AdvanceDeposit
    {
        $instrumentType = strtoupper($instrumentType);
        if (! in_array($instrumentType, ['ADVANCE', 'DEPOSIT'], true)) {
            throw ValidationException::withMessages(['instrument_type' => 'ประเภทต้องเป็นเงินล่วงหน้าหรือเงินมัดจำ']);
        }

        return DB::transaction(function () use ($settlement, $warehouse, $instrumentType, $actor): AdvanceDeposit {
            $source = Settlement::query()->whereKey($settlement->id)->whereHas('bankAccount', fn ($q) => $q->where('warehouse_id', $warehouse->id))->lockForUpdate()->firstOrFail();
            if ($source->status === 'POSTED') {
                $existing = AdvanceDeposit::query()->where('source_settlement_id', $source->id)->where('instrument_type', $instrumentType)->first();
                if ($existing) {
                    return $existing;
                }
            }
            if ($source->status !== 'APPROVED' || $source->allocationIntents()->exists()) {
                throw ValidationException::withMessages(['settlement' => 'สร้าง Advance/Deposit ได้เฉพาะ Settlement ที่อนุมัติแล้วและยังไม่จัดสรร']);
            }
            $partyType = $source->document_type === 'RECEIPT' ? 'CUSTOMER' : 'SUPPLIER';
            if ($source->party_type !== $partyType || ! $source->party_id || JournalBalance::decimal($source->tax_amount) !== '0.00' || JournalBalance::decimal($source->withholding_amount) !== '0.00' || JournalBalance::decimal($source->gross_amount) !== JournalBalance::decimal($source->net_amount)) {
                throw ValidationException::withMessages(['settlement' => 'Advance/Deposit ต้องเป็นยอดเต็มจำนวน NONE VAT และคู่ค้าต้องตรงทิศทาง']);
            }
            $bank = BankAccount::query()->whereKey($source->bank_account_id)->where('warehouse_id', $warehouse->id)->where('is_active', true)->first();
            if (! $bank || ! $bank->account_id) {
                throw ValidationException::withMessages(['bank_account_id' => 'บัญชีธนาคารไม่พร้อมลงบัญชี']);
            }
            $mapping = $partyType === 'CUSTOMER' ? 'CUSTOMER_ADVANCE' : 'SUPPLIER_ADVANCE';
            $advanceAccount = $this->mappings->resolve($mapping);
            $amount = JournalBalance::decimal($source->gross_amount);
            $journal = $this->journals->post([
                'source_type' => 'FINANCE', 'source_id' => (string) $source->id, 'source_reference' => $source->document_number,
                'event_code' => AdvanceDepositPostingContract::event($partyType), 'entry_date' => $source->settlement_date->format('Y-m-d'),
                'document_date' => $source->document_date->format('Y-m-d'), 'description' => $source->description ?: $source->document_number,
                'lines' => AdvanceDepositPostingContract::sourceLines($partyType, (int) $bank->account_id, (int) $advanceAccount->id, $amount, $source->document_number),
            ], $warehouse, $actor);
            $source->update(['status' => 'POSTED', 'journal_entry_id' => $journal->id, 'posted_by' => $actor?->id, 'posted_at' => now()]);
            $advance = AdvanceDeposit::query()->create([
                'warehouse_id' => $warehouse->id, 'party_id' => $source->party_id, 'party_type' => $partyType, 'direction' => $source->document_type,
                'instrument_type' => $instrumentType, 'source_settlement_id' => $source->id, 'document_number' => 'ADV-'.$instrumentType.'-'.$source->document_number,
                'document_date' => $source->document_date, 'posting_date' => $source->settlement_date, 'original_amount' => $amount, 'applied_amount' => '0.00',
                'status' => 'POSTED', 'journal_entry_id' => $journal->id, 'idempotency_key' => $this->idempotencyKey($source, $instrumentType), 'created_by' => $actor?->id,
                'posted_by' => $actor?->id, 'posted_at' => now(), 'description' => $source->description,
            ]);

            return $advance;
        }, 3);
    }

    /**
     * Atomically turns a posted, unapplied cash settlement into an
     * Advance/Deposit subledger row and its GL entry.
     */
    public function postFromPostedSettlement(Settlement $settlement, Warehouse $warehouse, string $instrumentType, ?User $actor = null): AdvanceDeposit
    {
        $source = $this->assertPostedSource($settlement, $warehouse, $instrumentType);
        $key = $this->idempotencyKey($source, $instrumentType);

        return DB::transaction(function () use ($source, $warehouse, $instrumentType, $key, $actor): AdvanceDeposit {
            $existing = AdvanceDeposit::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $bank = BankAccount::query()->whereKey($source->bank_account_id)->where('warehouse_id', $warehouse->id)->where('is_active', true)->lockForUpdate()->first();
            if (! $bank || ! $bank->account_id) {
                throw ValidationException::withMessages(['bank_account_id' => 'บัญชีธนาคารของ Settlement ไม่พร้อมลงบัญชี Advance/Deposit']);
            }
            $amount = JournalBalance::decimal($source->gross_amount);
            $advance = AdvanceDeposit::query()->create([
                'warehouse_id' => $warehouse->id, 'party_id' => $source->party_id, 'party_type' => $source->party_type,
                'direction' => $source->document_type, 'instrument_type' => strtoupper($instrumentType), 'source_settlement_id' => $source->id,
                'document_number' => 'ADV-'.strtoupper($instrumentType).'-'.$source->document_number,
                'document_date' => $source->document_date, 'posting_date' => $source->settlement_date,
                'original_amount' => $amount, 'applied_amount' => '0.00', 'status' => 'POSTED', 'idempotency_key' => $key,
                'created_by' => $actor?->id, 'posted_by' => $actor?->id, 'posted_at' => now(), 'description' => $source->description,
            ]);
            // The posted Settlement Journal is the cash/advance Journal. Do
            // not post a second cash entry when materialising the subledger.
            $advance->update(['journal_entry_id' => $source->journal_entry_id]);

            return $advance->fresh();
        }, 3);
    }

    public function assertPostedSource(Settlement $settlement, Warehouse $warehouse, string $instrumentType): Settlement
    {
        return DB::transaction(function () use ($settlement, $warehouse, $instrumentType): Settlement {
            $source = Settlement::query()
                ->whereKey($settlement->id)
                ->whereHas('bankAccount', fn ($query) => $query->where('warehouse_id', $warehouse->id))
                ->lockForUpdate()
                ->firstOrFail();

            if (! $source->journal_entry_id) {
                throw ValidationException::withMessages(['settlement' => 'Settlement ยังไม่มี Journal ที่ลงบัญชีแล้ว']);
            }

            $instrumentType = strtoupper($instrumentType);
            if (! in_array($instrumentType, ['ADVANCE', 'DEPOSIT'], true)) {
                throw ValidationException::withMessages(['instrument_type' => 'ประเภทต้องเป็นเงินล่วงหน้าหรือเงินมัดจำ']);
            }
            if ($source->status !== 'POSTED') {
                throw ValidationException::withMessages(['settlement' => 'สร้าง Advance/Deposit ได้เฉพาะ Settlement ที่ลงบัญชีแล้ว']);
            }
            if (! in_array($source->document_type, ['RECEIPT', 'PAYMENT'], true)) {
                throw ValidationException::withMessages(['settlement' => 'Settlement นี้ไม่ใช่เอกสารรับ/จ่ายที่รองรับ']);
            }
            $partyType = $source->document_type === 'RECEIPT' ? 'CUSTOMER' : 'SUPPLIER';
            if ($source->party_type !== $partyType || ! $source->party_id) {
                throw ValidationException::withMessages(['settlement' => 'ประเภทคู่ค้าไม่ตรงกับทิศทางของ Settlement']);
            }
            if (JournalBalance::decimal($source->tax_amount) !== '0.00'
                || JournalBalance::decimal($source->withholding_amount) !== '0.00'
                || JournalBalance::decimal($source->gross_amount) !== JournalBalance::decimal($source->net_amount)
                || JournalBalance::decimal($source->gross_amount) === '0.00') {
                throw ValidationException::withMessages(['settlement' => 'Advance/Deposit ต้องเป็นยอดรับ/จ่ายเต็มจำนวนแบบไม่มีภาษีหรือหัก ณ ที่จ่าย']);
            }
            if ($source->allocationIntents()->exists()) {
                throw ValidationException::withMessages(['settlement' => 'Settlement ที่จัดสรร AR/AP แล้วไม่สามารถเป็น Advance/Deposit ได้']);
            }

            $bank = BankAccount::query()->whereKey($source->bank_account_id)
                ->where('warehouse_id', $warehouse->id)->where('is_active', true)->first();
            $event = $partyType === 'CUSTOMER' ? 'customer_advance' : 'supplier_payment';
            $journal = JournalEntry::query()->with('lines')->whereKey($source->journal_entry_id)
                ->where('status', 'POSTED')->where('warehouse_id', $warehouse->id)->first();
            if (! $bank || ! $bank->account_id || ! $journal
                || $journal->source_type !== 'FINANCE'
                || $journal->source_event !== $event
                || (string) $journal->source_id !== (string) $source->id
                || $journal->source_reference !== $source->document_number
                || $journal->entry_date->format('Y-m-d') !== $source->settlement_date->format('Y-m-d')
                || $journal->document_date->format('Y-m-d') !== $source->document_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['settlement' => 'Journal ต้นทางของ Settlement ไม่ตรงกับเอกสาร คลัง หรือ event ที่คาดไว้']);
            }
            $amount = JournalBalance::decimal($source->gross_amount);
            $bankLine = $journal->lines->first(fn ($line): bool => (int) $line->account_id === (int) $bank->account_id
                && JournalBalance::decimal($partyType === 'CUSTOMER' ? $line->debit : $line->credit) === $amount
                && JournalBalance::decimal($partyType === 'CUSTOMER' ? $line->credit : $line->debit) === '0.00');
            $mapping = $partyType === 'CUSTOMER' ? 'CUSTOMER_ADVANCE' : 'SUPPLIER_ADVANCE';
            $advanceAccount = $this->mappings->resolve($mapping);
            $advanceLine = $journal->lines->first(fn ($line): bool => (int) $line->account_id === (int) $advanceAccount->id
                && JournalBalance::decimal($partyType === 'CUSTOMER' ? $line->credit : $line->debit) === $amount
                && JournalBalance::decimal($partyType === 'CUSTOMER' ? $line->debit : $line->credit) === '0.00');
            $totals = JournalBalance::totals($journal->lines->map(fn ($line): array => ['debit' => $line->debit, 'credit' => $line->credit])->all());
            $amountCents = JournalBalance::totals([['debit' => $amount, 'credit' => '0.00']])['debit'];
            if (! $bankLine || ! $advanceLine || $totals['debit'] !== $totals['credit'] || $totals['debit'] !== $amountCents) {
                throw ValidationException::withMessages(['settlement' => 'Journal ต้นทางไม่สมดุลหรือไม่มีบรรทัดบัญชีธนาคารตรงยอด']);
            }

            AdvanceDepositContract::assertPartyDirection($partyType, $source->document_type);

            return $source;
        }, 3);
    }

    public function idempotencyKey(Settlement $settlement, string $instrumentType): string
    {
        return hash('sha256', implode('|', [
            'finance-advance-deposit', $settlement->id, strtoupper($instrumentType),
            $settlement->party_type, $settlement->party_id, $settlement->gross_amount,
        ]));
    }

    public function existing(Settlement $settlement, string $instrumentType): ?AdvanceDeposit
    {
        return AdvanceDeposit::query()
            ->where('source_settlement_id', $settlement->id)
            ->where('idempotency_key', $this->idempotencyKey($settlement, $instrumentType))
            ->first();
    }
}
