<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Accounting\Support\TaxCalculator;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Models\EmployeeAdvanceClearing;
use App\Modules\Finance\Models\OtherCategory;
use App\Modules\Finance\Support\EmployeeAdvanceContract;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class EmployeeAdvanceClearingService
{
    public function __construct(private readonly JournalPostingService $journals, private readonly AccountMappingService $mappings, private readonly DocumentSequenceService $sequences, private readonly AuditLogger $audit) {}

    public function save(?EmployeeAdvanceClearing $clearing, array $values, Warehouse $warehouse, DocumentSequence $sequence, User $actor, Request $request): EmployeeAdvanceClearing
    {
        return DB::transaction(function () use ($clearing, $values, $warehouse, $sequence, $actor, $request): EmployeeAdvanceClearing {
            if ($clearing) {
                $clearing = $this->locked($clearing, $warehouse);
                if ($clearing->status !== 'DRAFT') throw ValidationException::withMessages(['status' => 'แก้ไขได้เฉพาะเอกสาร Draft']);
            }
            $advance = EmployeeAdvance::query()->whereKey($values['employee_advance_id'])->where('warehouse_id', $warehouse->id)->whereIn('status', ['POSTED', 'PARTIAL'])->lockForUpdate()->firstOrFail();
            $lines = $this->lines($values['lines']);
            $expense = collect($lines)->reduce(fn (string $sum, array $line) => JournalBalance::add($sum, $line['amount']), '0.00');
            $vat = collect($lines)->reduce(fn (string $sum, array $line) => JournalBalance::add($sum, $line['tax_amount']), '0.00');
            $wht = collect($lines)->reduce(fn (string $sum, array $line) => JournalBalance::add($sum, $line['withholding_amount']), '0.00');
            $net = JournalBalance::subtract(JournalBalance::add($expense, $vat), $wht);
            if ((float) $net <= 0) throw ValidationException::withMessages(['lines' => 'ยอดเคลียร์ต้องมากกว่าศูนย์']);
            $isFinal = (bool) ($values['is_final'] ?? true);
            $previous = EmployeeAdvanceClearing::query()->where('employee_advance_id', $advance->id)->whereIn('status', ['POSTED', 'CLEARED'])->when($clearing, fn ($q) => $q->where('id', '<>', $clearing->id))->sum('net_expense_amount');
            if (! $isFinal && (float) $net + (float) $previous > (float) $advance->amount) throw ValidationException::withMessages(['lines' => 'ยอดเคลียร์บางส่วนต้องไม่เกินยอดเงินทดรองคงเหลือ']);
            $refund = $isFinal ? max(0, (float) $advance->amount - (float) $net - (float) $previous) : 0;
            $additional = $isFinal ? max(0, (float) $net + (float) $previous - (float) $advance->amount) : 0;
            $data = ['branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'employee_advance_id' => $advance->id, 'document_date' => $values['document_date'], 'description' => $values['description'] ?? null, 'is_final' => $isFinal, 'expense_amount' => $expense, 'vat_amount' => $vat, 'wht_amount' => $wht, 'net_expense_amount' => $net, 'refund_amount' => number_format($refund, 2, '.', ''), 'additional_amount' => number_format($additional, 2, '.', '')];
            $before = $clearing?->only(array_keys($data)) ?? [];
            $clearing ??= new EmployeeAdvanceClearing(['document_number' => $this->number($sequence, $warehouse, $values['document_date']), 'status' => 'DRAFT', 'created_by' => $actor->id]);
            $clearing->fill($data)->save();
            $clearing->lines()->delete();
            $clearing->lines()->createMany($lines);
            $this->audit->record($before === [] ? 'finance.employee_advance_clearing.created' : 'finance.employee_advance_clearing.updated', $clearing, $before, $clearing->only(array_keys($data)), $actor, $request);
            return $clearing->fresh(['advance', 'lines']);
        });
    }

    public function post(EmployeeAdvanceClearing $clearing, Warehouse $warehouse, User $actor, Request $request): EmployeeAdvanceClearing
    {
        return DB::transaction(function () use ($clearing, $warehouse, $actor, $request): EmployeeAdvanceClearing {
            $clearing = EmployeeAdvanceClearing::query()->with(['advance.bankAccount.account', 'lines.expenseAccount'])->whereKey($clearing->id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
            if ($clearing->status === 'POSTED' || $clearing->status === 'CLEARED') {
                if (! $clearing->journal_entry_id) throw ValidationException::withMessages(['journal_entry_id' => 'เอกสารมีสถานะลงบัญชีแต่ไม่พบ Journal Entry']);
                return $clearing;
            }
            if ($clearing->status !== 'APPROVED') throw ValidationException::withMessages(['status' => 'ลงบัญชีได้เฉพาะใบเคลียร์ที่อนุมัติแล้ว']);
            $advance = $clearing->advance;
            $bank = $advance?->bankAccount;
            if (! $advance || ! $bank?->account || ! $bank->account->is_active || ! $bank->account->is_postable) throw ValidationException::withMessages(['employee_advance_id' => 'ไม่พบบัญชีจ่ายของใบเงินทดรองที่ใช้งานได้']);
            $advanceAccount = $this->mappings->resolveForEvent('employee_advance_clearing', 'EMPLOYEE_ADVANCE');
            $journalLines = $clearing->lines->map(fn ($line) => ['account_id' => $line->expense_account_id, 'subledger_type' => null, 'subledger_id' => null, 'description' => $line->description ?: $clearing->document_number, 'debit' => $line->amount, 'credit' => '0.00', 'tax_base' => $line->amount, 'tax_amount' => $line->tax_amount])->all();
            $accounts = [$advanceAccount['provenance']];
            if ((float) $clearing->vat_amount > 0) { $vat = $this->mappings->resolveForEvent('supplier_payment', 'INPUT_VAT'); $accounts[] = $vat['provenance']; $journalLines[] = ['account_id' => $vat['account']->id, 'subledger_type' => 'TAX', 'subledger_id' => (string) $vat['account']->id, 'description' => 'ภาษีซื้อ '.$clearing->document_number, 'debit' => $clearing->vat_amount, 'credit' => '0.00', 'tax_base' => '0.00', 'tax_amount' => '0.00']; }
            if ((float) $clearing->wht_amount > 0) { $wht = $this->mappings->resolveForEvent('supplier_payment', 'WHT_PAYABLE'); $accounts[] = $wht['provenance']; $journalLines[] = ['account_id' => $wht['account']->id, 'subledger_type' => 'TAX', 'subledger_id' => (string) $wht['account']->id, 'description' => 'WHT '.$clearing->document_number, 'debit' => '0.00', 'credit' => $clearing->wht_amount, 'tax_base' => '0.00', 'tax_amount' => '0.00']; }
            $advanceRelease = JournalBalance::subtract(JournalBalance::add($clearing->net_expense_amount, $clearing->refund_amount), $clearing->additional_amount);
            $journalLines[] = ['account_id' => $advanceAccount['account']->id, 'subledger_type' => 'EMPLOYEE_ADVANCE', 'subledger_id' => (string) $advance->id, 'description' => $clearing->document_number, 'debit' => '0.00', 'credit' => $advanceRelease, 'tax_base' => '0.00', 'tax_amount' => '0.00'];
            if ((float) $clearing->refund_amount > 0) {
                $accounts[] = ['account_role' => 'BANK_ACCOUNT', 'account_id' => $bank->account_id, 'source' => 'DOCUMENT', 'source_type' => 'BANK_ACCOUNT', 'source_id' => (string) $bank->id, 'mapping_id' => null, 'mapping_version' => null];
                $journalLines[] = ['account_id' => $bank->account_id, 'subledger_type' => $bank->type, 'subledger_id' => (string) $bank->id, 'description' => 'คืนเงิน '.$clearing->document_number, 'debit' => $clearing->refund_amount, 'credit' => '0.00', 'tax_base' => '0.00', 'tax_amount' => '0.00'];
            }
            if ((float) $clearing->additional_amount > 0) {
                $accounts[] = ['account_role' => 'BANK_ACCOUNT', 'account_id' => $bank->account_id, 'source' => 'DOCUMENT', 'source_type' => 'BANK_ACCOUNT', 'source_id' => (string) $bank->id, 'mapping_id' => null, 'mapping_version' => null];
                $journalLines[] = ['account_id' => $bank->account_id, 'subledger_type' => $bank->type, 'subledger_id' => (string) $bank->id, 'description' => 'เบิกเพิ่ม '.$clearing->document_number, 'debit' => '0.00', 'credit' => $clearing->additional_amount, 'tax_base' => '0.00', 'tax_amount' => '0.00'];
            }
            $entry = $this->journals->postWithinTransaction(['source_type' => 'FIN_EMP_ADV_CLEARING', 'source_id' => (string) $clearing->id, 'source_reference' => $clearing->document_number, 'event_code' => 'employee_advance_clearing', 'entry_date' => $clearing->document_date->format('Y-m-d'), 'document_date' => $clearing->document_date->format('Y-m-d'), 'description' => $clearing->description ?: $clearing->document_number, 'posting_metadata' => ['contract_version' => 1, 'event_code' => 'employee_advance_clearing', 'accounts' => $accounts], 'lines' => $journalLines], $warehouse, $actor);
            $before = $clearing->only(['status', 'journal_entry_id', 'idempotency_key', 'posted_by', 'posted_at']);
            $clearing->update(['status' => $clearing->is_final ? 'CLEARED' : 'POSTED', 'journal_entry_id' => $entry->id, 'idempotency_key' => hash('sha256', "finance.employee_advance_clearing.post|{$clearing->id}"), 'posted_by' => $actor->id, 'posted_at' => now()]);
            $advance->update(['status' => $clearing->is_final ? 'CLEARED' : 'PARTIAL']);
            $this->audit->record('finance.employee_advance_clearing.posted', $clearing, $before, $clearing->only(array_keys($before)), $actor, $request);
            return $clearing->fresh(['advance', 'journalEntry']);
        }, 3);
    }

    public function reverse(EmployeeAdvanceClearing $clearing, Warehouse $warehouse, string $date, string $reason, User $actor, Request $request): EmployeeAdvanceClearing
    {
        return DB::transaction(function () use ($clearing, $warehouse, $date, $reason, $actor, $request): EmployeeAdvanceClearing {
            $clearing = EmployeeAdvanceClearing::query()->with('journalEntry')->whereKey($clearing->id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
            if ($clearing->status === 'REVERSED' && $clearing->reversal_journal_entry_id) return $clearing;
            if (! in_array($clearing->status, ['POSTED', 'CLEARED'], true) || ! $clearing->journalEntry) throw ValidationException::withMessages(['status' => 'ยกเลิกรายการได้เฉพาะใบเคลียร์ที่ลงบัญชีแล้ว']);
            $reversal = $this->journals->reverseWithinTransaction($clearing->journalEntry, ['source_type' => 'FIN_EMP_ADV_CLEARING', 'source_id' => (string) $clearing->id, 'reversal_date' => $date, 'reason' => $reason], $actor);
            $before = $clearing->only(['status', 'reversal_journal_entry_id', 'reversal_key', 'reversed_by', 'reversed_at', 'reversal_reason']);
            $clearing->update(['status' => 'REVERSED', 'reversal_journal_entry_id' => $reversal->id, 'reversal_key' => hash('sha256', "finance.employee_advance_clearing.reverse|{$clearing->id}"), 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => trim($reason)]);
            $hasRemainingClearing = EmployeeAdvanceClearing::query()->where('employee_advance_id', $clearing->employee_advance_id)->whereIn('status', ['POSTED', 'CLEARED'])->where('id', '<>', $clearing->id)->exists();
            EmployeeAdvance::query()->whereKey($clearing->employee_advance_id)->update(['status' => $hasRemainingClearing ? 'PARTIAL' : 'POSTED']);
            $this->audit->record('finance.employee_advance_clearing.reversed', $clearing, $before, $clearing->only(array_keys($before)), $actor, $request);
            return $clearing->fresh(['reversalJournalEntry']);
        }, 3);
    }

    public function transition(EmployeeAdvanceClearing $clearing, Warehouse $warehouse, string $action, User $actor, Request $request): EmployeeAdvanceClearing
    {
        return DB::transaction(function () use ($clearing, $warehouse, $action, $actor, $request): EmployeeAdvanceClearing {
            $clearing = $this->locked($clearing, $warehouse);
            $map = ['submit' => ['DRAFT', 'SUBMITTED'], 'approve' => ['SUBMITTED', 'APPROVED'], 'reject' => ['SUBMITTED', 'DRAFT'], 'void' => [['SUBMITTED', 'APPROVED'], 'VOID']];
            $rule = $map[$action] ?? null;
            if (! $rule || (is_array($rule[0]) ? ! in_array($clearing->status, $rule[0], true) : $clearing->status !== $rule[0])) throw ValidationException::withMessages(['status' => 'ไม่สามารถดำเนินการกับสถานะปัจจุบันได้']);
            $before = $clearing->only(['status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'voided_by', 'voided_at', 'void_reason']);
            if ($action === 'reject' && blank($request->input('reason'))) throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลที่ไม่อนุมัติ']);
            $data = $rule[1] === 'SUBMITTED' ? ['status' => 'SUBMITTED', 'submitted_by' => $actor->id, 'submitted_at' => now()] : ($rule[1] === 'APPROVED' ? ['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()] : ($action === 'reject' ? ['status' => 'DRAFT', 'approved_by' => null, 'approved_at' => null] : ['status' => 'VOID', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => trim((string) $request->input('reason'))]));
            $clearing->update($data);
            $this->audit->record('finance.employee_advance_clearing.'.($action === 'reject' ? 'rejected' : ($rule[1] === 'VOID' ? 'voided' : strtolower($rule[1]))), $clearing, $before, [...$clearing->only(array_keys($data)), ...($action === 'reject' ? ['reason' => trim((string) $request->input('reason'))] : [])], $actor, $request);
            return $clearing->fresh();
        });
    }

    public function deleteDraft(EmployeeAdvanceClearing $clearing, Warehouse $warehouse, User $actor, Request $request): void
    {
        DB::transaction(function () use ($clearing, $warehouse, $actor, $request): void {
            $clearing = $this->locked($clearing, $warehouse);
            if ($clearing->status !== 'DRAFT') throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะเอกสาร Draft']);
            $this->audit->record('finance.employee_advance_clearing.deleted', $clearing, ['status' => 'DRAFT'], ['deleted' => true], $actor, $request);
            $clearing->delete();
        });
    }

    private function lines(array $values): array
    {
        $categories = OtherCategory::query()->with('account')->whereKey(collect($values)->pluck('expense_category_id')->unique())->where('kind', 'EXPENSE')->where('is_active', true)->get()->keyBy('id');
        $taxCodes = TaxCode::query()->whereKey(collect($values)->flatMap(fn (array $v) => [$v['tax_code_id'] ?? null, $v['withholding_tax_code_id'] ?? null])->filter()->unique())->where('is_active', true)->get()->keyBy('id');
        return collect(array_values($values))->map(function (array $value, int $index) use ($categories, $taxCodes): array {
            $category = $categories->get($value['expense_category_id']);
            if (! $category?->account || ! $category->account->is_active || ! $category->account->is_postable) throw ValidationException::withMessages(["lines.{$index}.expense_category_id" => 'หมวดค่าใช้จ่ายและบัญชีต้องเปิดใช้งานและลงรายการได้']);
            $amount = JournalBalance::decimal($value['amount']); $tax = $taxCodes->get($value['tax_code_id'] ?? null); $wht = $taxCodes->get($value['withholding_tax_code_id'] ?? null);
            return ['line_number' => $index + 1, 'expense_category_id' => $category->id, 'expense_category_code' => $category->code, 'expense_category_name' => $category->name, 'expense_account_id' => $category->account_id, 'expense_account_code' => $category->account->code, 'expense_account_name' => $category->account->name, 'description' => $value['description'] ?? null, 'receipt_reference' => $value['receipt_reference'] ?? null, 'amount' => $amount, 'tax_code_id' => $tax?->id, 'tax_code_code' => $tax?->code, 'tax_rate' => $tax?->rate, 'tax_amount' => $tax && $tax->kind === 'VAT_IN' ? TaxCalculator::calculate($amount, (string) $tax->rate, false, 2)['tax'] : '0.00', 'withholding_tax_code_id' => $wht?->id, 'withholding_tax_code' => $wht?->code, 'withholding_rate' => $wht?->rate, 'withholding_amount' => $wht && $wht->kind === 'WHT' ? TaxCalculator::calculate($amount, (string) $wht->rate, false, 2)['tax'] : '0.00'];
        })->all();
    }
    private function locked(EmployeeAdvanceClearing $clearing, Warehouse $warehouse): EmployeeAdvanceClearing { return EmployeeAdvanceClearing::query()->whereKey($clearing->id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail(); }
    private function number(DocumentSequence $sequence, Warehouse $warehouse, string $date): string { return $this->sequences->issueAvailableForBranch($sequence, $warehouse->branch, Carbon::parse($date), fn (string $number) => EmployeeAdvanceClearing::query()->where('document_number', $number)->exists()); }
}
