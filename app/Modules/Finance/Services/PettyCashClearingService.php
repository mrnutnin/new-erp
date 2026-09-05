<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\PettyCashClearing;
use App\Modules\Finance\Models\PettyCashFund;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class PettyCashClearingService
{
    public function __construct(private readonly AuditLogger $audit, private readonly AccountMappingService $mappings, private readonly JournalPostingService $journals, private readonly DocumentSequenceService $sequences) {}

    public function save(?PettyCashClearing $clearing, array $values, Warehouse $warehouse, User $actor, Request $request, ?DocumentSequence $sequence = null): PettyCashClearing
    {
        return DB::transaction(function () use ($clearing, $values, $warehouse, $actor, $request, $sequence): PettyCashClearing {
            $fund = $this->fund($values['petty_cash_fund_id'], $warehouse);
            if ($clearing && $clearing->status !== 'DRAFT') throw ValidationException::withMessages(['status' => 'แก้ไขได้เฉพาะเอกสาร Draft']);
            $expected = $this->expected($fund);
            $actual = number_format((float) $values['actual_amount'], 2, '.', '');
            $variance = number_format((float) $actual - (float) $expected, 2, '.', '');
            if ((float) $variance !== 0.0 && blank($values['reason'] ?? null)) throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลเมื่อยอดเงินจริงต่างจากยอดตามทะเบียน']);
            $data = ['petty_cash_fund_id' => $fund->id, 'warehouse_id' => $warehouse->id, 'clearing_date' => $values['clearing_date'], 'expected_amount' => $expected, 'actual_amount' => $actual, 'variance_amount' => $variance, 'reason' => filled($values['reason'] ?? null) ? trim($values['reason']) : null];
            $before = $clearing?->only(array_keys($data)) ?? [];
            $clearing ??= new PettyCashClearing(['document_number' => $this->number($sequence ?? throw ValidationException::withMessages(['document_sequence' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร PETTY_CASH_CLEARING']), $warehouse, $values['clearing_date']), 'status' => 'DRAFT', 'created_by' => $actor->id]);
            $clearing->fill($data)->save();
            $this->audit->record($before === [] ? 'finance.petty_cash_clearing.created' : 'finance.petty_cash_clearing.updated', $clearing, $before, $clearing->only(array_keys($data)), $actor, $request);
            return $clearing;
        });
    }

    public function transition(PettyCashClearing $clearing, Warehouse $warehouse, string $action, string $reason, User $actor, Request $request): PettyCashClearing
    {
        return DB::transaction(function () use ($clearing, $warehouse, $action, $reason, $actor, $request): PettyCashClearing {
            $clearing = PettyCashClearing::query()->whereKey($clearing)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
            $before = $clearing->only(['status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'voided_by', 'voided_at', 'void_reason']);
            $data = match ($action) {
                'submit' => $clearing->status === 'DRAFT' ? ['status' => 'SUBMITTED', 'submitted_by' => $actor->id, 'submitted_at' => now()] : null,
                'approve' => $clearing->status === 'SUBMITTED' ? ['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()] : null,
                'reject' => $clearing->status === 'SUBMITTED' && filled($reason) ? ['status' => 'DRAFT', 'approved_by' => null, 'approved_at' => null] : null,
                'void' => in_array($clearing->status, ['SUBMITTED', 'APPROVED'], true) ? ['status' => 'VOID', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => trim($reason)] : null,
            };
            if ($data === null || ($action === 'void' && blank($reason))) throw ValidationException::withMessages(['status' => 'ไม่สามารถเปลี่ยนสถานะเอกสารนี้ได้']);
            $clearing->update($data);
            $event = ['submit' => 'submitted', 'approve' => 'approved', 'reject' => 'rejected', 'void' => 'voided'][$action];
            $this->audit->record("finance.petty_cash_clearing.{$event}", $clearing, $before, [...$clearing->only(array_keys($before)), ...($action === 'reject' ? ['reason' => trim($reason)] : [])], $actor, $request);
            return $clearing;
        });
    }

    public function deleteDraft(PettyCashClearing $clearing, Warehouse $warehouse, User $actor, Request $request): void
    {
        DB::transaction(function () use ($clearing, $warehouse, $actor, $request): void {
            $clearing = PettyCashClearing::query()->whereKey($clearing->id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
            if ($clearing->status !== 'DRAFT') throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะเอกสาร Draft']);
            $this->audit->record('finance.petty_cash_clearing.deleted', $clearing, ['status' => 'DRAFT'], ['deleted' => true], $actor, $request);
            $clearing->delete();
        });
    }

    public function post(PettyCashClearing $clearing, Warehouse $warehouse, User $actor, Request $request): PettyCashClearing
    {
        return DB::transaction(function () use ($clearing, $warehouse, $actor, $request): PettyCashClearing {
            $clearing = PettyCashClearing::query()->with('fund.cashBankAccount')->whereKey($clearing)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
            if ($clearing->status === 'POSTED') return $clearing;
            if ($clearing->status !== 'APPROVED') throw ValidationException::withMessages(['status' => 'ลงบัญชีได้เฉพาะเอกสารที่อนุมัติแล้ว']);
            $variance = JournalBalance::decimal($clearing->variance_amount);
            if ($variance === '0.00') throw ValidationException::withMessages(['variance_amount' => 'ผลต่างเป็นศูนย์ ไม่จำเป็นต้องสร้างรายการปรับปรุง GL']);
            $isShort = str_starts_with($variance, '-');
            $amount = ltrim($variance, '-');
            $role = $isShort ? 'PETTY_CASH_VARIANCE_LOSS' : 'PETTY_CASH_VARIANCE_GAIN';
            $mapping = $this->mappings->resolveForEvent('petty_cash_clearing', $role);
            $cash = $clearing->fund?->cashBankAccount;
            if (! $cash?->account_id) throw ValidationException::withMessages(['petty_cash_fund_id' => 'ไม่พบบัญชีเงินสดของวงเงินสดย่อย']);
            $journalLines = $isShort
                ? [['account_id' => $mapping['account']->id, 'subledger_type' => null, 'subledger_id' => null, 'description' => 'เงินขาดจากการเคลียร์ '.$clearing->id, 'debit' => $amount, 'credit' => '0.00', 'tax_base' => '0.00', 'tax_amount' => '0.00'], ['account_id' => $cash->account_id, 'subledger_type' => 'CASH', 'subledger_id' => (string) $cash->id, 'description' => 'ปรับปรุงเงินสด '.$clearing->id, 'debit' => '0.00', 'credit' => $amount, 'tax_base' => '0.00', 'tax_amount' => '0.00']]
                : [['account_id' => $cash->account_id, 'subledger_type' => 'CASH', 'subledger_id' => (string) $cash->id, 'description' => 'ปรับปรุงเงินสด '.$clearing->id, 'debit' => $amount, 'credit' => '0.00', 'tax_base' => '0.00', 'tax_amount' => '0.00'], ['account_id' => $mapping['account']->id, 'subledger_type' => null, 'subledger_id' => null, 'description' => 'เงินเกินจากการเคลียร์ '.$clearing->id, 'debit' => '0.00', 'credit' => $amount, 'tax_base' => '0.00', 'tax_amount' => '0.00']];
            $entry = $this->journals->postWithinTransaction([
                'source_type' => 'FINANCE_PETTY_CASH_CLEARING',
                'source_id' => (string) $clearing->id,
                'source_reference' => 'PC-CLEARING-'.$clearing->id,
                'event_code' => 'petty_cash_clearing',
                'entry_date' => $clearing->clearing_date->format('Y-m-d'),
                'document_date' => $clearing->clearing_date->format('Y-m-d'),
                'description' => $clearing->reason ?: 'เคลียร์เงินสดย่อย #'.$clearing->id,
                'posting_metadata' => [
                    'contract_version' => 1,
                    'event_code' => 'petty_cash_clearing',
                    'accounts' => [
                        [
                            'account_role' => 'CASH_ACCOUNT',
                            'account_id' => $cash->account_id,
                            'source' => 'DOCUMENT',
                            'source_type' => 'BANK_ACCOUNT',
                            'source_id' => (string) $cash->id,
                            'mapping_id' => null,
                            'mapping_version' => null,
                        ],
                        $mapping['provenance'],
                    ],
                ],
                'lines' => $journalLines,
            ], $warehouse, $actor);
            $before = $clearing->only(['status', 'journal_entry_id', 'idempotency_key', 'posted_by', 'posted_at']);
            $clearing->update(['status' => 'POSTED', 'journal_entry_id' => $entry->id, 'idempotency_key' => hash('sha256', "finance.petty_cash_clearing.post|{$clearing->id}"), 'posted_by' => $actor->id, 'posted_at' => now()]);
            $this->audit->record('finance.petty_cash_clearing.posted', $clearing, $before, $clearing->only(array_keys($before)), $actor, $request);
            return $clearing->fresh(['journalEntry']);
        }, 3);
    }

    public function reverse(PettyCashClearing $clearing, Warehouse $warehouse, string $date, string $reason, User $actor, Request $request): PettyCashClearing
    {
        return DB::transaction(function () use ($clearing, $warehouse, $date, $reason, $actor, $request): PettyCashClearing {
            $clearing = PettyCashClearing::query()->with('journalEntry')->whereKey($clearing)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
            if ($clearing->status === 'REVERSED' && $clearing->reversal_journal_entry_id) return $clearing;
            if ($clearing->status !== 'POSTED') throw ValidationException::withMessages(['status' => 'กลับรายการได้เฉพาะเอกสารที่ลงบัญชีแล้ว']);
            if (! $clearing->journalEntry) throw ValidationException::withMessages(['journal_entry_id' => 'ไม่พบ Journal Entry ของเอกสารเคลียร์เงินสดย่อย']);

            $entry = $this->journals->reverseWithinTransaction($clearing->journalEntry, [
                'source_type' => 'FINANCE_PETTY_CASH_CLEARING',
                'source_id' => (string) $clearing->id,
                'reversal_date' => $date,
                'reason' => $reason,
            ], $actor);
            $before = $clearing->only(['status', 'reversal_journal_entry_id', 'reversal_key', 'reversed_by', 'reversed_at', 'reversal_reason']);
            $clearing->update(['status' => 'REVERSED', 'reversal_journal_entry_id' => $entry->id, 'reversal_key' => hash('sha256', "finance.petty_cash_clearing.reverse|{$clearing->id}"), 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => trim($reason)]);
            $this->audit->record('finance.petty_cash_clearing.reversed', $clearing, $before, $clearing->only(array_keys($before)), $actor, $request);

            return $clearing->fresh(['journalEntry', 'reversalJournalEntry']);
        }, 3);
    }

    private function fund(int $id, Warehouse $warehouse): PettyCashFund { return PettyCashFund::query()->whereKey($id)->where('warehouse_id', $warehouse->id)->where('is_active', true)->lockForUpdate()->firstOrFail(); }

    private function expected(PettyCashFund $fund): string
    {
        $topUps = $fund->topUps()->where('status', 'POSTED')->sum('amount');
        $vouchers = $fund->vouchers()->where('status', 'POSTED')->sum('total_amount');
        return number_format((float) $topUps - (float) $vouchers, 2, '.', '');
    }

    private function number(DocumentSequence $sequence, Warehouse $warehouse, string $date): string
    {
        if ($sequence->warehouse_id !== null && (int) $sequence->warehouse_id !== (int) $warehouse->id) {
            throw ValidationException::withMessages(['document_sequence' => 'รูปแบบเลขเอกสารต้องเป็นของคลังเดียวกันหรือเป็นรูปแบบกลาง']);
        }

        return $this->sequences->issueAvailableForBranch($sequence, $warehouse->branch, Carbon::parse($date), fn (string $number) => PettyCashClearing::query()->where('document_number', $number)->exists());
    }
}
