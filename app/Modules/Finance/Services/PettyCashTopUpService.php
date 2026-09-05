<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\PettyCashFund;
use App\Modules\Finance\Models\PettyCashTopUp;
use App\Modules\Finance\Support\PettyCashTopUpContract;
use App\Modules\Finance\Support\PettyCashVoucherContract;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PettyCashTopUpService
{
    public function __construct(private readonly JournalPostingService $journals, private readonly DocumentSequenceService $sequences, private readonly AuditLogger $audit) {}

    public function create(array $values, Warehouse $warehouse, DocumentSequence $sequence, User $actor, Request $request): PettyCashTopUp
    {
        return DB::transaction(function () use ($values, $warehouse, $sequence, $actor, $request): PettyCashTopUp {
            [$fund, $source] = $this->accounts($values, $warehouse);
            $topUp = PettyCashTopUp::query()->create([
                ...$this->attributes($values, $fund, $source), 'warehouse_id' => $warehouse->id,
                'document_number' => $this->number($sequence, $warehouse, $values['document_date']), 'status' => 'DRAFT', 'created_by' => $actor->id,
            ]);
            $this->sequences->recordIssued($sequence->fresh(), $topUp->document_number, 'FINANCE_PETTY_CASH_TOP_UP', $topUp->id, $topUp->document_date, $actor->id);
            $this->audit->record('finance.petty_cash_top_up.created', $topUp, [], $topUp->only(['document_number', 'document_date', 'amount', 'status']), $actor, $request);

            return $topUp;
        });
    }

    public function update(PettyCashTopUp $topUp, array $values, Warehouse $warehouse, User $actor, Request $request): PettyCashTopUp
    {
        return DB::transaction(function () use ($topUp, $values, $warehouse, $actor, $request): PettyCashTopUp {
            $topUp = $this->locked($topUp, $warehouse);
            $this->mutable($topUp);
            [$fund, $source] = $this->accounts($values, $warehouse);
            $before = $topUp->only(['petty_cash_fund_id', 'document_date', 'source_bank_account_id', 'amount', 'description']);
            $topUp->update($this->attributes($values, $fund, $source));
            $this->audit->record('finance.petty_cash_top_up.updated', $topUp, $before, $topUp->fresh()->only(array_keys($before)), $actor, $request);

            return $topUp->fresh();
        });
    }

    public function submit(PettyCashTopUp $topUp, Warehouse $warehouse, User $actor, Request $request): PettyCashTopUp
    {
        return $this->transition($topUp, $warehouse, 'SUBMIT', $actor, $request);
    }

    public function approve(PettyCashTopUp $topUp, Warehouse $warehouse, User $actor, Request $request): PettyCashTopUp
    {
        return $this->transition($topUp, $warehouse, 'APPROVE', $actor, $request);
    }

    public function reject(PettyCashTopUp $topUp, Warehouse $warehouse, string $reason, User $actor, Request $request): PettyCashTopUp
    {
        return DB::transaction(function () use ($topUp, $warehouse, $reason, $actor, $request): PettyCashTopUp {
            $topUp = $this->locked($topUp, $warehouse);
            if ($topUp->status !== 'SUBMITTED' || blank($reason)) throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลที่ไม่อนุมัติ']);
            $before = $topUp->only(['status', 'approved_by', 'approved_at']);
            $topUp->update(['status' => 'DRAFT', 'approved_by' => null, 'approved_at' => null]);
            $this->audit->record('finance.petty_cash_top_up.rejected', $topUp, $before, ['status' => 'DRAFT', 'reason' => trim($reason)], $actor, $request);
            return $topUp->fresh();
        });
    }

    public function deleteDraft(PettyCashTopUp $topUp, Warehouse $warehouse, User $actor, Request $request): void
    {
        DB::transaction(function () use ($topUp, $warehouse, $actor, $request): void {
            $topUp = $this->locked($topUp, $warehouse);
            if ($topUp->status !== 'DRAFT') throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะเอกสาร Draft']);
            $this->audit->record('finance.petty_cash_top_up.deleted', $topUp, ['status' => 'DRAFT'], ['deleted' => true], $actor, $request);
            $topUp->delete();
        });
    }

    public function void(PettyCashTopUp $topUp, Warehouse $warehouse, string $reason, User $actor, Request $request): PettyCashTopUp
    {
        return DB::transaction(function () use ($topUp, $warehouse, $reason, $actor, $request): PettyCashTopUp {
            $topUp = $this->locked($topUp, $warehouse);
            $this->state($topUp, 'VOID');
            $before = $topUp->only(['status', 'voided_by', 'voided_at', 'void_reason']);
            $topUp->update(['status' => 'VOID', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => trim($reason)]);
            $this->audit->record('finance.petty_cash_top_up.voided', $topUp, $before, $topUp->only(array_keys($before)), $actor, $request);

            return $topUp;
        });
    }

    public function post(PettyCashTopUp $topUp, Warehouse $warehouse, User $actor, Request $request): PettyCashTopUp
    {
        return DB::transaction(function () use ($topUp, $warehouse, $actor, $request): PettyCashTopUp {
            $topUp = $this->locked($topUp, $warehouse);
            if ($topUp->status === 'POSTED') {
                PettyCashTopUpContract::assertPostingMetadata((string) $topUp->idempotency_key, $topUp->journal_entry_id);

                return $topUp;
            }
            $this->state($topUp, 'POST');
            [$fund, $source] = $this->accounts(['petty_cash_fund_id' => $topUp->petty_cash_fund_id, 'source_bank_account_id' => $topUp->source_bank_account_id], $warehouse);
            if ((int) $topUp->cash_bank_account_id !== (int) $fund->bank_account_id) {
                throw ValidationException::withMessages(['petty_cash_fund_id' => 'บัญชีเงินสดของกองเปลี่ยนหลังสร้างเอกสาร กรุณาแก้ไขและส่งอนุมัติใหม่']);
            }
            $cash = $fund->cashBankAccount;
            $entry = $this->journals->postWithinTransaction([
                'source_type' => 'FINANCE_PETTY_CASH_TOP_UP', 'source_id' => (string) $topUp->id, 'source_reference' => $topUp->document_number,
                'event_code' => 'petty_cash_top_up', 'entry_date' => $topUp->document_date->format('Y-m-d'), 'document_date' => $topUp->document_date->format('Y-m-d'), 'description' => $topUp->description ?: $topUp->document_number,
                'posting_metadata' => ['contract_version' => 1, 'event_code' => 'petty_cash_top_up', 'accounts' => [
                    ['account_role' => 'CASH_ACCOUNT', 'account_id' => $cash->account_id, 'source' => 'DOCUMENT', 'source_type' => 'BANK_ACCOUNT', 'source_id' => (string) $cash->id, 'mapping_id' => null, 'mapping_version' => null],
                    ['account_role' => 'BANK_ACCOUNT', 'account_id' => $source->account_id, 'source' => 'DOCUMENT', 'source_type' => 'BANK_ACCOUNT', 'source_id' => (string) $source->id, 'mapping_id' => null, 'mapping_version' => null],
                ]],
                'lines' => [
                    ['account_id' => $cash->account_id, 'subledger_type' => 'CASH', 'subledger_id' => (string) $cash->id, 'description' => $topUp->document_number, 'debit' => $topUp->amount, 'credit' => '0.00', 'tax_base' => '0.00', 'tax_amount' => '0.00'],
                    ['account_id' => $source->account_id, 'subledger_type' => 'BANK', 'subledger_id' => (string) $source->id, 'description' => $topUp->document_number, 'debit' => '0.00', 'credit' => $topUp->amount, 'tax_base' => '0.00', 'tax_amount' => '0.00'],
                ],
            ], $warehouse, $actor);
            $before = $topUp->only(['status', 'journal_entry_id', 'idempotency_key', 'posted_by', 'posted_at']);
            $topUp->update(['status' => 'POSTED', 'journal_entry_id' => $entry->id, 'idempotency_key' => hash('sha256', "finance.petty_cash_top_up.post|{$topUp->id}"), 'posted_by' => $actor->id, 'posted_at' => now()]);
            $this->audit->record('finance.petty_cash_top_up.posted', $topUp, $before, $topUp->only(array_keys($before)), $actor, $request);

            return $topUp;
        }, 3);
    }

    public function reverse(PettyCashTopUp $topUp, Warehouse $warehouse, string $date, string $reason, User $actor, Request $request): PettyCashTopUp
    {
        return DB::transaction(function () use ($topUp, $warehouse, $date, $reason, $actor, $request): PettyCashTopUp {
            $topUp = $this->locked($topUp, $warehouse);
            if ($topUp->status === 'REVERSED' && $topUp->reversal_journal_entry_id) {
                return $topUp;
            }
            $this->state($topUp, 'REVERSE');
            if (! $topUp->journalEntry) {
                throw ValidationException::withMessages(['journal_entry_id' => 'ไม่พบ Journal Entry ของเอกสารเติมเงินสดย่อย']);
            }
            $entry = $this->journals->reverseWithinTransaction($topUp->journalEntry, ['source_type' => 'FINANCE_PETTY_CASH_TOP_UP', 'source_id' => (string) $topUp->id, 'reversal_date' => $date, 'reason' => $reason], $actor);
            $before = $topUp->only(['status', 'reversal_journal_entry_id', 'reversal_key', 'reversed_by', 'reversed_at', 'reversal_reason']);
            $topUp->update(['status' => 'REVERSED', 'reversal_journal_entry_id' => $entry->id, 'reversal_key' => hash('sha256', "finance.petty_cash_top_up.reverse|{$topUp->id}"), 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => trim($reason)]);
            $this->audit->record('finance.petty_cash_top_up.reversed', $topUp, $before, $topUp->only(array_keys($before)), $actor, $request);

            return $topUp;
        }, 3);
    }

    private function transition(PettyCashTopUp $topUp, Warehouse $warehouse, string $transition, User $actor, Request $request): PettyCashTopUp
    {
        return DB::transaction(function () use ($topUp, $warehouse, $transition, $actor, $request): PettyCashTopUp {
            $topUp = $this->locked($topUp, $warehouse);
            $this->state($topUp, $transition);
            $before = $topUp->only(['status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at']);
            $topUp->update($transition === 'SUBMIT' ? ['status' => 'SUBMITTED', 'submitted_by' => $actor->id, 'submitted_at' => now()] : ['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->record($transition === 'SUBMIT' ? 'finance.petty_cash_top_up.submitted' : 'finance.petty_cash_top_up.approved', $topUp, $before, $topUp->only(array_keys($before)), $actor, $request);

            return $topUp;
        });
    }

    private function accounts(array $values, Warehouse $warehouse): array
    {
        $fund = PettyCashFund::query()->with('cashBankAccount.account')->whereKey($values['petty_cash_fund_id'])->where('warehouse_id', $warehouse->id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $source = BankAccount::query()->with('account')->whereKey($values['source_bank_account_id'])->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
        try {
            PettyCashVoucherContract::assertCashFundBankAccount($fund->cashBankAccount, $warehouse->id);
            PettyCashTopUpContract::assertSourceBankAccount($source, $warehouse->id);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['bank_account_id' => $e->getMessage()]);
        }
        if (! $fund->cashBankAccount->account || ! $fund->cashBankAccount->account->is_active || ! $fund->cashBankAccount->account->is_postable) {
            throw ValidationException::withMessages(['petty_cash_fund_id' => 'บัญชีเงินสดของกองต้องเปิดใช้งานและลงรายการได้']);
        }
        if ((int) $fund->bank_account_id === (int) $source->id || (int) $fund->cashBankAccount->account_id === (int) $source->account_id) {
            throw ValidationException::withMessages(['source_bank_account_id' => 'บัญชีต้นทางและบัญชีเงินสดย่อยต้องต่างกัน']);
        }

        return [$fund, $source];
    }

    private function attributes(array $values, PettyCashFund $fund, BankAccount $source): array
    {
        $cash = $fund->cashBankAccount;

        return ['petty_cash_fund_id' => $fund->id, 'document_date' => $values['document_date'], 'source_bank_account_id' => $source->id, 'source_bank_account_code' => $source->code, 'source_bank_account_name' => $source->name, 'source_account_id' => $source->account_id, 'source_account_code' => $source->account->code, 'source_account_name' => $source->account->name, 'cash_bank_account_id' => $cash->id, 'cash_bank_account_code' => $cash->code, 'cash_bank_account_name' => $cash->name, 'cash_account_id' => $cash->account_id, 'cash_account_code' => $cash->account->code, 'cash_account_name' => $cash->account->name, 'amount' => $values['amount'], 'description' => $values['description'] ?? null];
    }

    private function locked(PettyCashTopUp $topUp, Warehouse $warehouse): PettyCashTopUp
    {
        return PettyCashTopUp::query()->whereKey($topUp->id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
    }

    private function mutable(PettyCashTopUp $topUp): void
    {
        try {
            PettyCashTopUpContract::assertMutable($topUp->status);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }
    }

    private function state(PettyCashTopUp $topUp, string $transition): void
    {
        try {
            PettyCashTopUpContract::state($topUp->status, $transition);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }
    }

    private function number(DocumentSequence $sequence, Warehouse $warehouse, string $date): string
    {
        if ($sequence->warehouse_id !== null && (int) $sequence->warehouse_id !== (int) $warehouse->id) {
            throw ValidationException::withMessages(['document_sequence' => 'รูปแบบเลขเอกสารต้องเป็นของคลังเดียวกันหรือเป็นรูปแบบกลาง']);
        }

        return $this->sequences->issueAvailableForBranch($sequence, $warehouse->branch, Carbon::parse($date), fn (string $number) => PettyCashTopUp::query()->where('document_number', $number)->exists());
    }
}
