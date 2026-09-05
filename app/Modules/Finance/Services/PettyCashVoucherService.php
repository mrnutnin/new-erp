<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Models\Party;
use App\Models\Warehouse;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Accounting\Support\TaxCalculator;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OtherCategory;
use App\Modules\Finance\Models\PettyCashFund;
use App\Modules\Finance\Models\PettyCashVoucher;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Finance\Support\PettyCashVoucherContract;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PettyCashVoucherService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly AccountMappingService $mappings,
        private readonly DocumentSequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $values, Warehouse $warehouse, DocumentSequence $sequence, User $actor, Request $request): PettyCashVoucher
    {
        return DB::transaction(function () use ($values, $warehouse, $sequence, $actor, $request): PettyCashVoucher {
            $fund = $this->fund($values['petty_cash_fund_id'], $warehouse);
            $payee = $this->payee($values);
            $voucher = PettyCashVoucher::query()->create([
                'petty_cash_fund_id' => $fund->id,
                'warehouse_id' => $warehouse->id,
                'document_number' => $this->number($sequence, $warehouse, $values['document_date']),
                'document_date' => $values['document_date'],
                ...$payee,
                'description' => $values['description'] ?? null,
                'status' => 'DRAFT',
                'created_by' => $actor->id,
            ]);
            $this->sequences->recordIssued($sequence->fresh(), $voucher->document_number, 'FINANCE_PETTY_CASH_VOUCHER', $voucher->id, $voucher->document_date, $actor->id);
            $this->replaceLines($voucher, $values['lines'], $fund);
            $this->audit->record('finance.petty_cash_voucher.created', $voucher, [], $voucher->only(['document_number', 'document_date', 'petty_cash_fund_id', 'payee_type', 'payee_user_id', 'payee_party_id', 'payee_name', 'total_amount', 'status']), $actor, $request);

            return $voucher->fresh('lines');
        });
    }

    public function update(PettyCashVoucher $voucher, array $values, Warehouse $warehouse, User $actor, Request $request): PettyCashVoucher
    {
        return DB::transaction(function () use ($voucher, $values, $warehouse, $actor, $request): PettyCashVoucher {
            $voucher = PettyCashVoucher::query()->lockForUpdate()->findOrFail($voucher->id);
            $this->assertDraft($voucher);
            $fund = $this->fund($values['petty_cash_fund_id'], $warehouse);
            $payee = $this->payee($values);
            $before = $voucher->only(['petty_cash_fund_id', 'document_date', 'payee_type', 'payee_user_id', 'payee_party_id', 'payee_name', 'description', 'total_amount']);
            $voucher->update([
                'petty_cash_fund_id' => $fund->id,
                'warehouse_id' => $warehouse->id,
                'document_date' => $values['document_date'],
                ...$payee,
                'description' => $values['description'] ?? null,
            ]);
            $this->replaceLines($voucher, $values['lines'], $fund);
            $this->audit->record('finance.petty_cash_voucher.updated', $voucher, $before, $voucher->fresh()->only(array_keys($before)), $actor, $request);

            return $voucher->fresh('lines');
        });
    }

    public function submit(PettyCashVoucher $voucher, Warehouse $warehouse, User $actor, Request $request): PettyCashVoucher
    {
        return $this->transition($voucher, $warehouse, 'SUBMIT', $actor, $request, 'finance.petty_cash_voucher.submitted');
    }

    public function approve(PettyCashVoucher $voucher, Warehouse $warehouse, User $actor, Request $request): PettyCashVoucher
    {
        return $this->transition($voucher, $warehouse, 'APPROVE', $actor, $request, 'finance.petty_cash_voucher.approved');
    }

    public function reject(PettyCashVoucher $voucher, Warehouse $warehouse, string $reason, User $actor, Request $request): PettyCashVoucher
    {
        return DB::transaction(function () use ($voucher, $warehouse, $reason, $actor, $request): PettyCashVoucher {
            $voucher = $this->lockedVoucher($voucher, $warehouse);
            if ($voucher->status !== 'SUBMITTED' || blank($reason)) throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลที่ไม่อนุมัติ']);
            $before = $voucher->only(['status', 'approved_by', 'approved_at']);
            $voucher->update(['status' => 'DRAFT', 'approved_by' => null, 'approved_at' => null]);
            $this->audit->record('finance.petty_cash_voucher.rejected', $voucher, $before, ['status' => 'DRAFT', 'reason' => trim($reason)], $actor, $request);
            return $voucher->fresh();
        });
    }

    public function deleteDraft(PettyCashVoucher $voucher, Warehouse $warehouse, User $actor, Request $request): void
    {
        DB::transaction(function () use ($voucher, $warehouse, $actor, $request): void {
            $voucher = $this->lockedVoucher($voucher, $warehouse);
            if ($voucher->status !== 'DRAFT') throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะเอกสาร Draft']);
            $this->audit->record('finance.petty_cash_voucher.deleted', $voucher, ['status' => 'DRAFT'], ['deleted' => true], $actor, $request);
            $voucher->delete();
        });
    }

    public function void(PettyCashVoucher $voucher, Warehouse $warehouse, string $reason, User $actor, Request $request): PettyCashVoucher
    {
        return DB::transaction(function () use ($voucher, $warehouse, $reason, $actor, $request): PettyCashVoucher {
            $voucher = $this->lockedVoucher($voucher, $warehouse);
            $before = $voucher->only(['status', 'voided_by', 'voided_at', 'void_reason']);
            $this->state($voucher, 'VOID');
            $voucher->update(['status' => 'VOID', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => trim($reason)]);
            $this->audit->record('finance.petty_cash_voucher.voided', $voucher, $before, $voucher->only(array_keys($before)), $actor, $request);

            return $voucher;
        });
    }

    public function post(PettyCashVoucher $voucher, Warehouse $warehouse, User $actor, Request $request): PettyCashVoucher
    {
        return DB::transaction(function () use ($voucher, $warehouse, $actor, $request): PettyCashVoucher {
            $voucher = $this->lockedVoucher($voucher, $warehouse);
            $fund = $this->fund($voucher->petty_cash_fund_id, $warehouse);
            if ($voucher->status === 'POSTED') {
                PettyCashVoucherContract::assertPostingMetadata((string) $voucher->idempotency_key, $voucher->journal_entry_id);

                return $voucher;
            }
            $this->state($voucher, 'POST');
            $lines = $voucher->lines()->with('expenseAccount')->lockForUpdate()->get();
            $this->assertLines($lines, $voucher, $fund);
            $inputVat = JournalBalance::decimal($voucher->tax_amount) !== '0.00' ? $this->mappings->resolveForEvent('supplier_payment', 'INPUT_VAT') : null;
            $whtPayable = JournalBalance::decimal($voucher->withholding_amount) !== '0.00' ? $this->mappings->resolveForEvent('supplier_payment', 'WHT_PAYABLE') : null;
            $cashAmount = JournalBalance::decimal($voucher->net_amount) !== '0.00' ? JournalBalance::decimal($voucher->net_amount) : JournalBalance::decimal($voucher->total_amount);
            $key = hash('sha256', "finance.petty_cash_voucher.post|{$voucher->id}");
            $journalLines = $lines->map(fn ($line) => ['account_id' => $line->expense_account_id, 'subledger_type' => null, 'subledger_id' => null, 'description' => $line->description ?: $voucher->document_number, 'debit' => $line->amount, 'credit' => '0.00', 'tax_base' => $line->tax_base, 'tax_amount' => $line->tax_amount, 'tax_code_id' => $line->tax_code_id])->all();
            if ($inputVat) {
                $journalLines[] = ['account_id' => $inputVat['account']->id, 'subledger_type' => 'TAX', 'subledger_id' => (string) $inputVat['account']->id, 'description' => 'ภาษีซื้อ '.$voucher->document_number, 'debit' => $voucher->tax_amount, 'credit' => '0.00', 'tax_base' => '0.00', 'tax_amount' => '0.00'];
            }
            if ($whtPayable) {
                $journalLines[] = ['account_id' => $whtPayable['account']->id, 'subledger_type' => 'TAX', 'subledger_id' => (string) $whtPayable['account']->id, 'description' => 'WHT '.$voucher->document_number, 'debit' => '0.00', 'credit' => $voucher->withholding_amount, 'tax_base' => '0.00', 'tax_amount' => '0.00'];
            }
            $journalLines[] = ['account_id' => $fund->cashBankAccount->account_id, 'subledger_type' => 'CASH', 'subledger_id' => (string) $fund->bank_account_id, 'description' => $voucher->document_number, 'debit' => '0.00', 'credit' => $cashAmount, 'tax_base' => '0.00', 'tax_amount' => '0.00'];
            $entry = $this->journals->postWithinTransaction([
                'source_type' => 'FINANCE_PETTY_CASH',
                'source_id' => (string) $voucher->id,
                'source_reference' => $voucher->document_number,
                'event_code' => 'expense_payment',
                'entry_date' => $voucher->document_date->format('Y-m-d'),
                'document_date' => $voucher->document_date->format('Y-m-d'),
                'description' => $voucher->description ?: $voucher->document_number,
                'posting_metadata' => ['contract_version' => 1, 'event_code' => 'expense_payment', 'accounts' => [
                    ['account_role' => 'CASH_ACCOUNT', 'account_id' => $fund->cashBankAccount->account_id, 'source' => 'DOCUMENT', 'source_type' => 'BANK_ACCOUNT', 'source_id' => (string) $fund->bank_account_id, 'mapping_id' => null, 'mapping_version' => null],
                    ...$lines->map(fn ($line, int $index) => ['account_role' => 'EXPENSE_ACCOUNT_'.($index + 1), 'account_id' => $line->expense_account_id, 'source' => 'DOCUMENT', 'source_type' => 'OTHER_CATEGORY', 'source_id' => (string) $line->expense_category_id, 'mapping_id' => null, 'mapping_version' => null])->all(),
                    ...($inputVat ? [$inputVat['provenance']] : []),
                    ...($whtPayable ? [$whtPayable['provenance']] : []),
                ]],
                'lines' => $journalLines,
            ], $warehouse, $actor);
            $before = $voucher->only(['status', 'journal_entry_id', 'idempotency_key', 'posted_by', 'posted_at']);
            $voucher->update(['status' => 'POSTED', 'journal_entry_id' => $entry->id, 'idempotency_key' => $key, 'posted_by' => $actor->id, 'posted_at' => now()]);
            $this->audit->record('finance.petty_cash_voucher.posted', $voucher, $before, $voucher->only(array_keys($before)), $actor, $request);

            return $voucher;
        }, 3);
    }

    public function reverse(PettyCashVoucher $voucher, Warehouse $warehouse, string $date, string $reason, User $actor, Request $request): PettyCashVoucher
    {
        return DB::transaction(function () use ($voucher, $warehouse, $date, $reason, $actor, $request): PettyCashVoucher {
            $voucher = $this->lockedVoucher($voucher, $warehouse);
            if ($voucher->status === 'REVERSED' && $voucher->reversal_journal_entry_id) {
                return $voucher;
            }
            $this->state($voucher, 'REVERSE');
            if (! $voucher->journalEntry) {
                throw ValidationException::withMessages(['journal_entry_id' => 'ไม่พบ Journal Entry ของเอกสารเงินสดย่อย']);
            }
            $reversal = $this->journals->reverseWithinTransaction($voucher->journalEntry, ['source_type' => 'FINANCE_PETTY_CASH', 'source_id' => (string) $voucher->id, 'reversal_date' => $date, 'reason' => $reason], $actor);
            $before = $voucher->only(['status', 'reversal_journal_entry_id', 'reversal_key', 'reversed_by', 'reversed_at', 'reversal_reason']);
            $voucher->update(['status' => 'REVERSED', 'reversal_journal_entry_id' => $reversal->id, 'reversal_key' => hash('sha256', "finance.petty_cash_voucher.reverse|{$voucher->id}"), 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => trim($reason)]);
            $this->audit->record('finance.petty_cash_voucher.reversed', $voucher, $before, $voucher->only(array_keys($before)), $actor, $request);

            return $voucher;
        }, 3);
    }

    private function transition(PettyCashVoucher $voucher, Warehouse $warehouse, string $transition, User $actor, Request $request, string $event): PettyCashVoucher
    {
        return DB::transaction(function () use ($voucher, $warehouse, $transition, $actor, $request, $event): PettyCashVoucher {
            $voucher = $this->lockedVoucher($voucher, $warehouse);
            $before = $voucher->only(['status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at']);
            $this->state($voucher, $transition);
            $voucher->update($transition === 'SUBMIT' ? ['status' => 'SUBMITTED', 'submitted_by' => $actor->id, 'submitted_at' => now()] : ['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->record($event, $voucher, $before, $voucher->only(array_keys($before)), $actor, $request);

            return $voucher;
        });
    }

    private function lockedVoucher(PettyCashVoucher $voucher, Warehouse $warehouse): PettyCashVoucher
    {
        return PettyCashVoucher::query()->whereKey($voucher->id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
    }

    private function fund(int|string $fundId, Warehouse $warehouse): PettyCashFund
    {
        $fund = PettyCashFund::query()->with('cashBankAccount.account')->whereKey($fundId)->where('warehouse_id', $warehouse->id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        if (! $fund->cashBankAccount) {
            throw ValidationException::withMessages(['petty_cash_fund_id' => 'กองเงินสดย่อยต้องผูกบัญชีเงินสด']);
        }
        try {
            PettyCashVoucherContract::assertCashFundBankAccount($fund->cashBankAccount, $warehouse->id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['petty_cash_fund_id' => $exception->getMessage()]);
        }
        if (! $fund->cashBankAccount->account || ! $fund->cashBankAccount->account->is_active || ! $fund->cashBankAccount->account->is_postable || $fund->cashBankAccount->account->control_account_type !== 'CASH') {
            throw ValidationException::withMessages(['petty_cash_fund_id' => 'บัญชีเงินสดของกองต้องเปิดใช้งานและลงรายการได้']);
        }

        return $fund;
    }

    private function replaceLines(PettyCashVoucher $voucher, array $values, PettyCashFund $fund): void
    {
        $categories = OtherCategory::query()->with('account')->whereKey(collect($values)->pluck('expense_category_id')->unique())->where('kind', 'EXPENSE')->where('is_active', true)->lockForUpdate()->get()->keyBy('id');
        $taxCodeIds = collect($values)->flatMap(fn (array $value) => [$value['tax_code_id'] ?? null, $value['withholding_tax_code_id'] ?? null])->filter()->unique()->values();
        $taxCodes = TaxCode::query()->whereKey($taxCodeIds)->where('is_active', true)->get()->keyBy('id');
        $lines = collect(array_values($values))->map(function (array $value, int $index) use ($categories, $taxCodes): array {
            $category = $categories->get($value['expense_category_id']);
            if (! $category || ! $category->account || ! $category->account->is_active || ! $category->account->is_postable) {
                throw ValidationException::withMessages(["lines.{$index}.expense_category_id" => 'หมวดค่าใช้จ่ายและบัญชีต้องเปิดใช้งานและลงรายการได้']);
            }

            $amount = JournalBalance::decimal($value['amount']);
            $vat = $taxCodes->get($value['tax_code_id'] ?? null);
            $wht = $taxCodes->get($value['withholding_tax_code_id'] ?? null);
            $vatAmount = $vat && $vat->kind === 'VAT_IN' ? TaxCalculator::calculate($amount, (string) $vat->rate, false, 2)['tax'] : '0.00';
            $whtAmount = $wht && $wht->kind === 'WHT' ? TaxCalculator::calculate($amount, (string) $wht->rate, false, 2)['tax'] : '0.00';

            return ['line_number' => $index + 1, 'expense_category_id' => $category->id, 'expense_category_code' => $category->code, 'expense_category_name' => $category->name, 'expense_account_id' => $category->account_id, 'expense_account_code' => $category->account->code, 'expense_account_name' => $category->account->name, 'description' => $value['description'] ?? null, 'receipt_reference' => $value['receipt_reference'] ?? null, 'amount' => $amount, 'tax_code_id' => $vat?->id, 'tax_code_code' => $vat?->code, 'tax_rate' => $vat?->rate, 'tax_base' => $amount, 'tax_amount' => $vatAmount, 'withholding_tax_code_id' => $wht?->id, 'withholding_tax_code' => $wht?->code, 'withholding_rate' => $wht?->rate, 'withholding_base' => $amount, 'withholding_amount' => $whtAmount];
        });
        $total = JournalBalance::decimal(JournalBalance::totals($lines->map(fn (array $line) => ['debit' => $line['amount'], 'credit' => '0.00'])->all())['debit'] / 100);
        if ($total === '0.00' || ((float) $fund->fund_limit > 0 && (float) $total > (float) $fund->fund_limit)) {
            throw ValidationException::withMessages(['lines' => 'ยอดเงินสดย่อยต้องมากกว่า 0 และไม่เกินวงเงินกอง']);
        }
        $voucher->lines()->delete();
        $voucher->lines()->createMany($lines->all());
        $taxTotal = $lines->reduce(fn (string $sum, array $line) => JournalBalance::add($sum, $line['tax_amount']), '0.00');
        $whtTotal = $lines->reduce(fn (string $sum, array $line) => JournalBalance::add($sum, $line['withholding_amount']), '0.00');
        $voucher->update(['total_amount' => JournalBalance::add($total, $taxTotal), 'tax_amount' => $taxTotal, 'withholding_amount' => $whtTotal, 'net_amount' => JournalBalance::subtract(JournalBalance::add($total, $taxTotal), $whtTotal)]);
    }

    private function payee(array $values): array
    {
        if ($values['payee_type'] === 'EMPLOYEE') {
            $user = User::query()->findOrFail($values['payee_user_id']);

            return ['payee_type' => 'EMPLOYEE', 'payee_user_id' => (int) $user->id, 'payee_party_id' => null, 'payee_name' => $user->name];
        }
        if ($values['payee_type'] === 'SUPPLIER') {
            $name = Party::query()->whereKey($values['payee_party_id'])->where('is_active', true)->whereHas('roles', fn ($query) => $query->where('role', 'SUPPLIER')->where('is_active', true))->value('name');
            if (! $name) {
                throw ValidationException::withMessages(['payee_party_id' => 'ไม่พบ Supplier ที่ใช้งานได้']);
            }

            return ['payee_type' => 'SUPPLIER', 'payee_user_id' => null, 'payee_party_id' => (int) $values['payee_party_id'], 'payee_name' => $name];
        }

        return ['payee_type' => 'OTHER', 'payee_user_id' => null, 'payee_party_id' => null, 'payee_name' => trim((string) ($values['payee_name'] ?? '')) ?: null];
    }

    private function assertLines($lines, PettyCashVoucher $voucher, PettyCashFund $fund): void
    {
        if ($lines->isEmpty() || $lines->contains(fn ($line) => (float) $line->amount <= 0 || ! $line->expenseAccount || ! $line->expenseAccount->is_active || ! $line->expenseAccount->is_postable)) {
            throw ValidationException::withMessages(['lines' => 'ต้องมีรายการค่าใช้จ่ายพร้อมบัญชีที่ลงรายการได้']);
        }
        $total = JournalBalance::decimal(JournalBalance::totals($lines->map(fn ($line) => ['debit' => $line->amount, 'credit' => '0.00'])->all())['debit'] / 100);
        $grossTotal = JournalBalance::add($total, JournalBalance::decimal($voucher->tax_amount));
        if ($grossTotal !== JournalBalance::decimal($voucher->total_amount) || ((float) $fund->fund_limit > 0 && (float) $grossTotal > (float) $fund->fund_limit)) {
            throw ValidationException::withMessages(['lines' => 'ยอดรายการหรือวงเงินกองเงินสดย่อยไม่ถูกต้อง']);
        }
    }

    private function assertDraft(PettyCashVoucher $voucher): void
    {
        if ($voucher->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'แก้ไขได้เฉพาะเอกสาร Draft']);
        }
    }

    private function state(PettyCashVoucher $voucher, string $transition): void
    {
        try {
            PettyCashVoucherContract::state($voucher->status, $transition);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }
    }

    private function number(DocumentSequence $sequence, Warehouse $warehouse, string $documentDate): string
    {
        if ($sequence->warehouse_id !== null && (int) $sequence->warehouse_id !== (int) $warehouse->id) {
            throw ValidationException::withMessages(['document_sequence' => 'รูปแบบเลขเอกสารต้องเป็นของคลังเดียวกันหรือเป็นรูปแบบกลาง']);
        }

        return $this->sequences->issueAvailableForBranch($sequence, $warehouse->branch, Carbon::parse($documentDate), fn (string $number) => PettyCashVoucher::query()->where('document_number', $number)->exists());
    }
}
