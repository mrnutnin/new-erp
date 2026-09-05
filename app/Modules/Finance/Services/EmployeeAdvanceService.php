<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Support\EmployeeAdvanceContract;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class EmployeeAdvanceService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly AccountMappingService $mappings,
        private readonly DocumentSequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $values, Warehouse $warehouse, DocumentSequence $sequence, User $actor, Request $request): EmployeeAdvance
    {
        return DB::transaction(function () use ($values, $warehouse, $sequence, $actor, $request): EmployeeAdvance {
            $this->accounts($values, $warehouse);
            $advance = EmployeeAdvance::query()->create([
                ...$values,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'document_number' => $this->number($sequence, $warehouse, $values['document_date']),
                'status' => 'DRAFT',
                'idempotency_key' => hash('sha256', 'finance.employee_advance.create|'.bin2hex(random_bytes(16))),
                'created_by' => $actor->id,
            ]);
            $this->sequences->recordIssued($sequence->fresh(), $advance->document_number, 'FINANCE_EMPLOYEE_ADVANCE', $advance->id, $advance->document_date, $actor->id);
            $this->audit->record('finance.employee_advance.created', $advance, [], $advance->only(['document_number', 'employee_user_id', 'document_date', 'amount', 'status']), $actor, $request);

            return $advance;
        });
    }

    public function update(EmployeeAdvance $advance, array $values, Warehouse $warehouse, User $actor, Request $request): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $values, $warehouse, $actor, $request): EmployeeAdvance {
            $advance = $this->locked($advance, $warehouse);
            EmployeeAdvanceContract::assertMutable($advance->status);
            $this->accounts($values, $warehouse);
            $before = $advance->only(['employee_user_id', 'bank_account_id', 'document_date', 'due_date', 'amount', 'purpose']);
            $advance->update($values);
            $this->audit->record('finance.employee_advance.updated', $advance, $before, $advance->fresh()->only(array_keys($before)), $actor, $request);

            return $advance->fresh();
        });
    }

    public function submit(EmployeeAdvance $advance, Warehouse $warehouse, User $actor, Request $request): EmployeeAdvance
    {
        return $this->transition($advance, $warehouse, 'SUBMIT', $actor, $request);
    }

    public function approve(EmployeeAdvance $advance, Warehouse $warehouse, User $actor, Request $request): EmployeeAdvance
    {
        if ((int) $advance->employee_user_id === (int) $actor->id) {
            throw ValidationException::withMessages(['status' => 'ผู้รับเงินทดรองไม่สามารถอนุมัติเอกสารของตนเอง']);
        }

        return $this->transition($advance, $warehouse, 'APPROVE', $actor, $request);
    }

    public function reject(EmployeeAdvance $advance, Warehouse $warehouse, string $reason, User $actor, Request $request): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $warehouse, $reason, $actor, $request): EmployeeAdvance {
            $advance = $this->locked($advance, $warehouse);
            if ($advance->status !== 'SUBMITTED' || blank($reason)) throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลที่ไม่อนุมัติ']);
            $before = $advance->only(['status', 'approved_by', 'approved_at']);
            $advance->update(['status' => 'DRAFT', 'approved_by' => null, 'approved_at' => null]);
            $this->audit->record('finance.employee_advance.rejected', $advance, $before, ['status' => 'DRAFT', 'reason' => trim($reason)], $actor, $request);
            return $advance->fresh();
        });
    }

    public function deleteDraft(EmployeeAdvance $advance, Warehouse $warehouse, User $actor, Request $request): void
    {
        DB::transaction(function () use ($advance, $warehouse, $actor, $request): void {
            $advance = $this->locked($advance, $warehouse);
            if ($advance->status !== 'DRAFT') throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะเอกสาร Draft']);
            $this->audit->record('finance.employee_advance.deleted', $advance, ['status' => 'DRAFT'], ['deleted' => true], $actor, $request);
            $advance->delete();
        });
    }

    public function void(EmployeeAdvance $advance, Warehouse $warehouse, string $reason, User $actor, Request $request): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $warehouse, $reason, $actor, $request): EmployeeAdvance {
            $advance = $this->locked($advance, $warehouse);
            try {
                $status = EmployeeAdvanceContract::state($advance->status, 'VOID');
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['status' => $e->getMessage()]);
            }
            $before = $advance->only(['status', 'reversal_reason']);
            $advance->update(['status' => $status, 'reversal_reason' => trim($reason)]);
            $this->audit->record('finance.employee_advance.voided', $advance, $before, $advance->only(array_keys($before)), $actor, $request);

            return $advance->fresh();
        });
    }

    public function post(EmployeeAdvance $advance, Warehouse $warehouse, User $actor, Request $request): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $warehouse, $actor, $request): EmployeeAdvance {
            $advance = $this->locked($advance, $warehouse);
            if ($advance->status === 'POSTED') {
                EmployeeAdvanceContract::assertPostingMetadata((string) $advance->idempotency_key, $advance->journal_entry_id);

                return $advance;
            }
            $this->state($advance, 'POST');
            $bank = BankAccount::query()->with('account')->whereKey($advance->bank_account_id)->where('warehouse_id', $warehouse->id)->whereIn('type', ['BANK', 'CASH'])->where('is_active', true)->firstOrFail();
            if (! $bank->account || ! $bank->account->is_active || ! $bank->account->is_postable) {
                throw ValidationException::withMessages(['bank_account_id' => 'บัญชีจ่ายต้องผูกกับบัญชี GL ที่เปิดใช้งานและลงรายการได้']);
            }
            $advanceAccount = $this->mappings->resolveForEvent('employee_advance', 'EMPLOYEE_ADVANCE');
            $amount = (string) $advance->amount;
            $entry = $this->journals->postWithinTransaction([
                'source_type' => 'FINANCE_EMPLOYEE_ADVANCE',
                'source_id' => (string) $advance->id,
                'source_reference' => $advance->document_number,
                'event_code' => 'employee_advance',
                'entry_date' => $advance->document_date->format('Y-m-d'),
                'document_date' => $advance->document_date->format('Y-m-d'),
                'description' => $advance->purpose ?: $advance->document_number,
                'posting_metadata' => ['contract_version' => 1, 'event_code' => 'employee_advance', 'accounts' => [
                    $advanceAccount['provenance'],
                    ['account_role' => 'BANK_ACCOUNT', 'account_id' => $bank->account_id, 'source' => 'DOCUMENT', 'source_type' => 'BANK_ACCOUNT', 'source_id' => (string) $bank->id, 'mapping_id' => null, 'mapping_version' => null],
                ]],
                'lines' => [
                    ['account_id' => $advanceAccount['account']->id, 'subledger_type' => 'EMPLOYEE_ADVANCE', 'subledger_id' => (string) $advance->id, 'description' => $advance->document_number, 'debit' => $amount, 'credit' => '0.00', 'tax_base' => '0.00', 'tax_amount' => '0.00'],
                    ['account_id' => $bank->account_id, 'subledger_type' => $bank->type, 'subledger_id' => (string) $bank->id, 'description' => $advance->document_number, 'debit' => '0.00', 'credit' => $amount, 'tax_base' => '0.00', 'tax_amount' => '0.00'],
                ],
            ], $warehouse, $actor);
            $before = $advance->only(['status', 'journal_entry_id', 'idempotency_key', 'posted_by', 'posted_at']);
            $advance->update(['status' => 'POSTED', 'journal_entry_id' => $entry->id, 'idempotency_key' => hash('sha256', "finance.employee_advance.post|{$advance->id}"), 'posted_by' => $actor->id, 'posted_at' => now()]);
            $this->audit->record('finance.employee_advance.posted', $advance, $before, $advance->only(array_keys($before)), $actor, $request);

            return $advance->fresh();
        }, 3);
    }

    public function reverse(EmployeeAdvance $advance, Warehouse $warehouse, string $date, string $reason, User $actor, Request $request): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $warehouse, $date, $reason, $actor, $request): EmployeeAdvance {
            $advance = $this->locked($advance, $warehouse);
            if ($advance->status === 'REVERSED' && $advance->reversal_journal_entry_id) {
                return $advance;
            }
            $this->state($advance, 'REVERSE');
            $entry = $advance->journalEntry ?: null;
            if (! $entry) {
                throw ValidationException::withMessages(['journal_entry_id' => 'ไม่พบ Journal Entry ของใบเงินทดรองจ่าย']);
            }
            $reversal = $this->journals->reverseWithinTransaction($entry, ['source_type' => 'FINANCE_EMPLOYEE_ADVANCE', 'source_id' => (string) $advance->id, 'reversal_date' => $date, 'reason' => $reason], $actor);
            $before = $advance->only(['status', 'reversal_journal_entry_id', 'reversal_key', 'reversed_by', 'reversed_at', 'reversal_reason']);
            $advance->update(['status' => 'REVERSED', 'reversal_journal_entry_id' => $reversal->id, 'reversal_key' => hash('sha256', "finance.employee_advance.reverse|{$advance->id}"), 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => trim($reason)]);
            $this->audit->record('finance.employee_advance.reversed', $advance, $before, $advance->only(array_keys($before)), $actor, $request);

            return $advance->fresh();
        }, 3);
    }

    private function transition(EmployeeAdvance $advance, Warehouse $warehouse, string $transition, User $actor, Request $request): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $warehouse, $transition, $actor, $request): EmployeeAdvance {
            $advance = $this->locked($advance, $warehouse);
            try {
                $status = EmployeeAdvanceContract::state($advance->status, $transition);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['status' => $e->getMessage()]);
            }
            $before = $advance->only(['status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at']);
            $advance->update($transition === 'SUBMIT'
                ? ['status' => $status, 'submitted_by' => $actor->id, 'submitted_at' => now()]
                : ['status' => $status, 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->record('finance.employee_advance.'.strtolower($transition === 'SUBMIT' ? 'submitted' : 'approved'), $advance, $before, $advance->only(array_keys($before)), $actor, $request);

            return $advance->fresh();
        });
    }

    private function accounts(array $values, Warehouse $warehouse): void
    {
        $account = BankAccount::query()->with('account')->whereKey($values['bank_account_id'])->where('warehouse_id', $warehouse->id)->whereIn('type', ['BANK', 'CASH'])->where('is_active', true)->firstOrFail();
        if (! $account->account || ! $account->account->is_active || ! $account->account->is_postable) {
            throw ValidationException::withMessages(['bank_account_id' => 'บัญชีจ่ายต้องผูกกับบัญชี GL ที่เปิดใช้งานและลงรายการได้']);
        }
    }

    private function state(EmployeeAdvance $advance, string $transition): void
    {
        try {
            EmployeeAdvanceContract::state($advance->status, $transition);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }
    }

    private function locked(EmployeeAdvance $advance, Warehouse $warehouse): EmployeeAdvance
    {
        return EmployeeAdvance::query()->whereKey($advance->id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
    }

    private function number(DocumentSequence $sequence, Warehouse $warehouse, string $date): string
    {
        if ($sequence->warehouse_id !== null && (int) $sequence->warehouse_id !== (int) $warehouse->id) {
            throw ValidationException::withMessages(['document_sequence' => 'รูปแบบเลขเอกสารต้องเป็นของคลังเดียวกันหรือเป็นรูปแบบกลาง']);
        }

        return $this->sequences->issueAvailableForBranch($sequence, $warehouse->branch, Carbon::parse($date), fn (string $number) => EmployeeAdvance::query()->where('document_number', $number)->exists());
    }
}
