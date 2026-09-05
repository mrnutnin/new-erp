<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\InternalTransfer;
use App\Modules\Finance\Support\InternalTransferContract;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class InternalTransferService
{
    public function __construct(private readonly JournalPostingService $journals, private readonly DocumentSequenceService $sequences, private readonly AuditLogger $audit) {}

    public function create(array $values, Warehouse $warehouse, DocumentSequence $sequence, User $actor, Request $request): InternalTransfer
    {
        return DB::transaction(function () use ($values, $warehouse, $sequence, $actor, $request): InternalTransfer {
            [$source, $destination] = $this->accounts($values, $warehouse);
            $transfer = InternalTransfer::query()->create([
                'warehouse_id' => $warehouse->id,
                'document_number' => $this->sequences->issueForBranch($sequence, $warehouse->branch, Carbon::parse($values['document_date'])),
                'document_date' => $values['document_date'],
                'source_bank_account_id' => $source->id,
                'destination_bank_account_id' => $destination->id,
                'amount' => $values['amount'],
                'description' => $values['description'] ?? null,
                'status' => 'DRAFT',
                'created_by' => $actor->id,
            ]);
            $this->sequences->recordIssued($sequence->fresh(), $transfer->document_number, 'FINANCE_INTERNAL_TRANSFER', $transfer->id, $transfer->document_date, $actor->id);
            $this->audit->record('finance.internal_transfer.created', $transfer, [], $transfer->only(['document_number', 'document_date', 'amount', 'status']), $actor, $request);

            return $transfer;
        });
    }

    public function transition(InternalTransfer $transfer, Warehouse $warehouse, string $action, User $actor, Request $request): InternalTransfer
    {
        return DB::transaction(function () use ($transfer, $warehouse, $action, $actor, $request): InternalTransfer {
            $transfer = $this->locked($transfer, $warehouse);
            $next = InternalTransferContract::transition($transfer->status, $action);
            $before = $transfer->only(['status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'voided_by', 'voided_at', 'void_reason']);
            $attributes = ['status' => $next];
            if ($next === 'SUBMITTED') $attributes += ['submitted_by' => $actor->id, 'submitted_at' => now()];
            if ($next === 'APPROVED') $attributes += ['approved_by' => $actor->id, 'approved_at' => now()];
            if ($next === 'VOID') $attributes += ['voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => trim((string) $request->input('reason'))];
            $transfer->update($attributes);
            $this->audit->record('finance.internal_transfer.'.strtolower($action), $transfer, $before, $transfer->only(array_keys($before)), $actor, $request);

            return $transfer->fresh();
        });
    }

    public function update(InternalTransfer $transfer, array $values, Warehouse $warehouse, User $actor, Request $request): InternalTransfer
    {
        return DB::transaction(function () use ($transfer, $values, $warehouse, $actor, $request): InternalTransfer {
            $transfer = $this->locked($transfer, $warehouse);
            if ($transfer->status !== 'DRAFT') throw ValidationException::withMessages(['status' => 'แก้ไขได้เฉพาะเอกสาร Draft']);
            [$source, $destination] = $this->accounts($values, $warehouse);
            $before = $transfer->only(['document_date', 'source_bank_account_id', 'destination_bank_account_id', 'amount', 'description']);
            $transfer->update(['document_date' => $values['document_date'], 'source_bank_account_id' => $source->id, 'destination_bank_account_id' => $destination->id, 'amount' => $values['amount'], 'description' => $values['description'] ?? null]);
            $this->audit->record('finance.internal_transfer.updated', $transfer, $before, $transfer->fresh()->only(array_keys($before)), $actor, $request);
            return $transfer->fresh();
        });
    }

    public function deleteDraft(InternalTransfer $transfer, Warehouse $warehouse, User $actor, Request $request): void
    {
        DB::transaction(function () use ($transfer, $warehouse, $actor, $request): void {
            $transfer = $this->locked($transfer, $warehouse);
            if ($transfer->status !== 'DRAFT') throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะเอกสาร Draft']);
            $this->audit->record('finance.internal_transfer.deleted', $transfer, ['status' => 'DRAFT'], ['deleted' => true], $actor, $request);
            $transfer->delete();
        });
    }

    public function post(InternalTransfer $transfer, Warehouse $warehouse, User $actor, Request $request): InternalTransfer
    {
        return DB::transaction(function () use ($transfer, $warehouse, $actor, $request): InternalTransfer {
            $transfer = $this->locked($transfer, $warehouse);
            if ($transfer->status === 'POSTED') return $transfer;
            if (InternalTransferContract::transition($transfer->status, 'POST') !== 'POSTED') throw ValidationException::withMessages(['status' => 'ลงบัญชีได้เฉพาะเอกสารที่อนุมัติแล้ว']);
            [$source, $destination] = $this->accounts(['source_bank_account_id' => $transfer->source_bank_account_id, 'destination_bank_account_id' => $transfer->destination_bank_account_id], $warehouse);
            $entry = $this->journals->postWithinTransaction([
                'source_type' => 'FINANCE_INTERNAL_TRANSFER', 'source_id' => (string) $transfer->id, 'source_reference' => $transfer->document_number,
                'event_code' => 'internal_transfer', 'entry_date' => $transfer->document_date->format('Y-m-d'), 'document_date' => $transfer->document_date->format('Y-m-d'),
                'description' => $transfer->description ?: $transfer->document_number,
                'posting_metadata' => ['contract_version' => 1, 'event_code' => 'internal_transfer', 'accounts' => [
                    ['account_role' => 'TRANSFER_SOURCE', 'account_id' => $source->account_id, 'source' => 'DOCUMENT', 'source_type' => 'BANK_ACCOUNT', 'source_id' => (string) $source->id, 'mapping_id' => null, 'mapping_version' => null],
                    ['account_role' => 'TRANSFER_DESTINATION', 'account_id' => $destination->account_id, 'source' => 'DOCUMENT', 'source_type' => 'BANK_ACCOUNT', 'source_id' => (string) $destination->id, 'mapping_id' => null, 'mapping_version' => null],
                ]],
                'lines' => [
                    ['account_id' => $destination->account_id, 'subledger_type' => strtoupper($destination->type), 'subledger_id' => (string) $destination->id, 'description' => $transfer->document_number, 'debit' => $transfer->amount, 'credit' => '0.00'],
                    ['account_id' => $source->account_id, 'subledger_type' => strtoupper($source->type), 'subledger_id' => (string) $source->id, 'description' => $transfer->document_number, 'debit' => '0.00', 'credit' => $transfer->amount],
                ],
            ], $warehouse, $actor);
            $transfer->update(['status' => 'POSTED', 'journal_entry_id' => $entry->id, 'idempotency_key' => hash('sha256', "finance.internal_transfer.post|{$transfer->id}"), 'posted_by' => $actor->id, 'posted_at' => now()]);
            $this->audit->record('finance.internal_transfer.posted', $transfer, [], $transfer->only(['status', 'journal_entry_id', 'idempotency_key']), $actor, $request);

            return $transfer->fresh();
        });
    }

    public function reverse(InternalTransfer $transfer, Warehouse $warehouse, string $date, string $reason, User $actor, Request $request): InternalTransfer
    {
        return DB::transaction(function () use ($transfer, $warehouse, $date, $reason, $actor, $request): InternalTransfer {
            $transfer = $this->locked($transfer, $warehouse);
            if ($transfer->status === 'REVERSED') return $transfer;
            InternalTransferContract::transition($transfer->status, 'REVERSE');
            $entry = $transfer->journalEntry()->lockForUpdate()->first();
            if (! $entry) throw ValidationException::withMessages(['status' => 'เอกสารไม่มี Journal ที่ลงบัญชีแล้ว']);
            $reversal = $this->journals->reverseWithinTransaction($entry, ['source_type' => 'FINANCE_INTERNAL_TRANSFER', 'source_id' => "{$transfer->id}", 'reversal_date' => $date, 'reason' => $reason], $actor);
            $transfer->update(['status' => 'REVERSED', 'reversal_journal_entry_id' => $reversal->id, 'reversal_key' => hash('sha256', "finance.internal_transfer.reverse|{$transfer->id}"), 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => trim($reason)]);
            $this->audit->record('finance.internal_transfer.reversed', $transfer, [], $transfer->only(['status', 'reversal_journal_entry_id', 'reversal_key']), $actor, $request);

            return $transfer->fresh();
        });
    }

    private function accounts(array $values, Warehouse $warehouse): array
    {
        $accounts = BankAccount::query()->with('account')->whereKey([$values['source_bank_account_id'], $values['destination_bank_account_id']])->where('warehouse_id', $warehouse->id)->where('is_active', true)->lockForUpdate()->get()->keyBy('id');
        $source = $accounts->get((int) $values['source_bank_account_id']);
        $destination = $accounts->get((int) $values['destination_bank_account_id']);
        if (! $source || ! $destination || $source->id === $destination->id || ! $source->account || ! $destination->account || ! $source->account->is_active || ! $destination->account->is_active || ! $source->account->is_postable || ! $destination->account->is_postable || $source->currency_code !== 'THB' || $destination->currency_code !== 'THB') {
            throw ValidationException::withMessages(['bank_account_id' => 'บัญชีต้นทางและปลายทางต้องเป็นบัญชีเงินสด/ธนาคาร THB ที่เปิดใช้งานและอยู่ในคลังเดียวกัน']);
        }

        return [$source, $destination];
    }

    private function locked(InternalTransfer $transfer, Warehouse $warehouse): InternalTransfer
    {
        return InternalTransfer::query()->whereKey($transfer->id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
    }
}
