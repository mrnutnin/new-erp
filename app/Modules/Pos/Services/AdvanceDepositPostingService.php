<?php

namespace App\Modules\Pos\Services;

use App\Models\Branch;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\AdvanceDeposit;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Pos\Support\PhysicalSaleWithholdingSnapshot;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** AI receipt: cash/bank + WHT receivable against customer advance. */
final class AdvanceDepositPostingService
{
    public function __construct(private readonly DocumentSequenceService $sequences, private readonly AccountMappingService $mappings, private readonly JournalPostingService $journals) {}

    /** @param array<string,mixed> $values */
    public function createDraft(array $values, Warehouse $warehouse, User $actor): AdvanceDeposit
    {
        return DB::transaction(function () use ($values, $warehouse, $actor): AdvanceDeposit {
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => 'ADVANCE_DEPOSIT_AI', 'is_active' => true])->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขที่เอกสารใบรับเงินล่วงหน้าสำหรับคลังนี้']);
            }
            $date = Carbon::parse($values['document_date'] ?? now())->toDateString();
            $tax = $this->taxSnapshot($values);
            $gross = $tax['gross_amount'];
            if ($gross === '0.00') {
                throw ValidationException::withMessages(['original_amount' => 'ยอดมัดจำต้องมากกว่าศูนย์']);
            }
            $whtCode = empty($values['withholding_tax_code_id']) ? null : TaxCode::query()->whereKey($values['withholding_tax_code_id'])->where('kind', 'WHT')->where('is_active', true)->lockForUpdate()->first();
            $wht = PhysicalSaleWithholdingSnapshot::build($whtCode, $values['withholding_base'] ?? 0, $tax['tax_base']);
            $net = JournalBalance::subtract($gross, $wht['withholding_amount']);
            $number = $this->sequences->issueForBranch($sequence, Branch::query()->findOrFail($warehouse->branch_id), Carbon::parse($date));
            $deposit = AdvanceDeposit::query()->create([
                'warehouse_id' => $warehouse->id, 'party_id' => $values['party_id'], 'party_type' => 'CUSTOMER', 'direction' => 'RECEIPT', 'instrument_type' => 'DEPOSIT',
                'document_number' => $number, 'document_date' => $date, 'receipt_date' => $values['receipt_date'] ?? $date, 'currency_code' => 'THB',
                'tax_treatment' => $tax['tax_treatment'], 'prices_include_vat' => $tax['prices_include_vat'], 'is_tax_point' => $values['is_tax_point'] ?? false,
                'tax_code_id' => $tax['tax_code_id'], 'tax_rate' => $tax['tax_rate'], 'tax_base' => $tax['tax_base'], 'tax_amount' => $tax['tax_amount'], 'tax_point_date' => $values['tax_point_date'] ?? null,
                ...$wht, 'withholding_certificate_reference' => $values['withholding_certificate_reference'] ?? null,
                'net_amount' => $net, 'original_amount' => $gross, 'applied_amount' => '0.00', 'balance_amount' => $gross, 'status' => 'DRAFT', 'idempotency_key' => hash('sha256', "pos-ai|{$warehouse->id}|{$number}"), 'created_by' => $actor->id, 'description' => $values['description'] ?? null,
            ]);
            $deposit->tenders()->createMany(collect($values['tenders'] ?? [])->values()->map(fn (array $tender, int $index): array => ['bank_account_id' => $tender['bank_account_id'], 'line_number' => $index + 1, 'amount' => $tender['amount'], 'reference' => $tender['reference'] ?? null])->all());
            $this->sequences->recordIssued($sequence, $number, 'finance_advance_deposits', $deposit->id, Carbon::parse($date), $actor->id);

            return $deposit->fresh('tenders');
        }, 3);
    }

    public function post(AdvanceDeposit $deposit, string $postingDate, Warehouse $warehouse, User $actor, Request $request): AdvanceDeposit
    {
        return DB::transaction(function () use ($deposit, $postingDate, $warehouse, $actor): AdvanceDeposit {
            $deposit = AdvanceDeposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($deposit->status === 'POSTED') {
                if ($deposit->posting_date?->format('Y-m-d') !== $postingDate) {
                    throw ValidationException::withMessages(['posting_date' => 'ใบรับเงินล่วงหน้านี้ Post ด้วยวันที่อื่นแล้ว']);
                }

                return $deposit;
            }
            if ($deposit->status !== 'DRAFT' || $deposit->warehouse_id !== $warehouse->id || $deposit->party_type !== 'CUSTOMER' || $deposit->direction !== 'RECEIPT' || $deposit->instrument_type !== 'DEPOSIT') {
                throw ValidationException::withMessages(['advance_deposit' => 'ใบรับเงินล่วงหน้าต้องเป็นร่างรับมัดจำลูกค้าในคลังที่เลือก']);
            }
            if ($deposit->currency_code !== 'THB' || $deposit->is_tax_point) {
                throw ValidationException::withMessages(['tax_point' => 'ใบรับเงินล่วงหน้า tax-point หรือสกุลเงินอื่นยังไม่มี posting policy']);
            }
            $gross = JournalBalance::decimal($deposit->original_amount);
            $wht = PhysicalSaleWithholdingSnapshot::assertStored($deposit->withholding_tax_code_id, $deposit->withholding_rate, $deposit->withholding_base, $deposit->withholding_amount, $gross);
            if (JournalBalance::decimal($deposit->net_amount) !== JournalBalance::subtract($gross, $wht['withholding_amount'])) {
                throw ValidationException::withMessages(['net_amount' => 'ยอดสุทธิใบรับเงินล่วงหน้าไม่ตรงกับยอดมัดจำหลัง WHT']);
            }
            $party = Party::query()->whereKey($deposit->party_id)->where('is_active', true)->sharedLock()->first();
            if (! $party || ! PartyRole::query()->where(['party_id' => $deposit->party_id, 'role' => 'CUSTOMER', 'is_active' => true])->exists()) {
                throw ValidationException::withMessages(['party_id' => 'ลูกค้าต้องเปิดใช้งาน']);
            }
            $tenders = $deposit->tenders()->with('bankAccount.account')->orderBy('line_number')->lockForUpdate()->get();
            $cash = $tenders->reduce(fn (string $sum, $tender): string => JournalBalance::add($sum, $tender->amount), '0.00');
            if ($tenders->isEmpty() || $cash !== $deposit->net_amount) {
                throw ValidationException::withMessages(['tenders' => 'ช่องทางรับเงินของใบรับเงินล่วงหน้าต้องมีและรวมเท่ากับยอดสุทธิหลัง WHT']);
            }
            foreach ($tenders as $tender) {
                $bank = $tender->bankAccount;
                if (! $bank || $bank->warehouse_id !== $warehouse->id || ! $bank->is_active || $bank->currency_code !== 'THB' || ! $bank->account?->is_active || ! $bank->account->is_postable || $bank->account->control_account_type !== $bank->type) {
                    throw ValidationException::withMessages(['tenders' => 'บัญชีรับเงินของใบรับเงินล่วงหน้าต้อง active, THB และผูกบัญชีคุมตรงประเภท']);
                }
            }
            $advance = $this->mappings->resolve('CUSTOMER_ADVANCE');
            $lines = $tenders->map(fn ($tender): array => ['account_id' => $tender->bankAccount->account_id, 'subledger_type' => strtoupper($tender->bankAccount->type), 'subledger_id' => (string) $tender->bank_account_id, 'description' => $deposit->document_number, 'debit' => $tender->amount, 'credit' => '0.00'])->all();
            if ($wht['withholding_amount'] !== '0.00') {
                $account = $this->mappings->resolve('WHT_RECEIVABLE');
                $lines[] = ['account_id' => $account->id, 'subledger_type' => 'TAX', 'subledger_id' => (string) $account->id, 'description' => "WHT {$deposit->document_number}", 'debit' => $wht['withholding_amount'], 'credit' => '0.00'];
            }
            $lines[] = ['account_id' => $advance->id, 'description' => "เงินรับมัดจำ {$deposit->document_number}", 'debit' => '0.00', 'credit' => $gross];
            $journal = $this->journals->postWithinTransaction(['source_type' => 'POS', 'source_id' => "AI:{$deposit->id}", 'source_reference' => $deposit->document_number, 'event_code' => 'customer_advance', 'entry_date' => $postingDate, 'document_date' => $deposit->document_date->format('Y-m-d'), 'description' => $deposit->description ?: $deposit->document_number, 'lines' => $lines], $warehouse, $actor);
            $deposit->update(['status' => 'POSTED', 'posting_date' => $postingDate, 'journal_entry_id' => $journal->id, 'posted_by' => $actor->id, 'posted_at' => now(), 'balance_amount' => $gross]);

            return $deposit->fresh('tenders');
        }, 3);
    }

    public function voidDraft(AdvanceDeposit $deposit, string $reason, Warehouse $warehouse, User $actor): AdvanceDeposit
    {
        return DB::transaction(function () use ($deposit, $reason, $warehouse, $actor): AdvanceDeposit {
            $deposit = AdvanceDeposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($deposit->warehouse_id !== $warehouse->id || $deposit->status !== 'DRAFT') {
                throw ValidationException::withMessages(['advance_deposit' => 'ยกเลิกได้เฉพาะใบรับเงินล่วงหน้าร่างในคลังที่เลือก']);
            }
            if (mb_strlen(trim($reason)) < 3) {
                throw ValidationException::withMessages(['reason' => 'ต้องระบุเหตุผลยกเลิก']);
            }
            $deposit->update(['status' => 'VOID', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => trim($reason)]);

            return $deposit->fresh();
        }, 3);
    }

    /** @param array<string,mixed> $values @return array{tax_treatment:string,prices_include_vat:bool,tax_code_id:?int,tax_rate:string,tax_base:string,tax_amount:string,gross_amount:string} */
    private function taxSnapshot(array $values): array
    {
        $treatment = strtoupper((string) ($values['tax_treatment'] ?? 'VAT_OUT'));
        $included = (bool) ($values['prices_include_vat'] ?? true);
        $amount = BigDecimal::of((string) ($values['original_amount'] ?? '0'))->toScale(2, RoundingMode::HALF_UP);
        if ($amount->isNegative() || $amount->isZero()) {
            throw ValidationException::withMessages(['original_amount' => 'ยอดมัดจำต้องมากกว่าศูนย์']);
        }
        if ($treatment === 'NONE_VAT') {
            return ['tax_treatment' => $treatment, 'prices_include_vat' => $included, 'tax_code_id' => null, 'tax_rate' => '0.0000', 'tax_base' => $amount->__toString(), 'tax_amount' => '0.00', 'gross_amount' => $amount->__toString()];
        }
        $code = TaxCode::query()->whereKey($values['tax_code_id'] ?? null)->where('kind', 'VAT_OUT')->where('is_active', true)->lockForUpdate()->first();
        if ($treatment !== 'VAT_OUT' || ! $code) {
            throw ValidationException::withMessages(['tax_code_id' => 'ใบรับเงินล่วงหน้าที่มี VAT ต้องเลือก Tax Code ภาษีขายที่เปิดใช้งาน']);
        }
        $rate = BigDecimal::of((string) $code->rate);
        $base = $included ? $amount->multipliedBy(100)->dividedBy($rate->plus(100), 2, RoundingMode::HALF_UP) : $amount;
        $tax = $base->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP);

        return ['tax_treatment' => 'VAT_OUT', 'prices_include_vat' => $included, 'tax_code_id' => $code->id, 'tax_rate' => $rate->toScale(4, RoundingMode::HALF_UP)->__toString(), 'tax_base' => $base->__toString(), 'tax_amount' => $tax->__toString(), 'gross_amount' => ($included ? $amount : $base->plus($tax))->toScale(2, RoundingMode::HALF_UP)->__toString()];
    }
}
