<?php

namespace App\Modules\Accounting\Services;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalBook;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Accounting\Support\PostingEvent;
use App\Modules\Accounting\Support\PostingIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JournalPostingService
{
    public function __construct(private readonly JournalEntryWriter $writer) {}

    public function post(array $attributes, Warehouse $warehouse, ?User $actor = null): JournalEntry
    {
        return $this->postForBranch($attributes, $warehouse->branch, $warehouse, $actor);
    }

    /**
     * Join an existing outer transaction. The caller owns commit/rollback.
     */
    public function postWithinTransaction(array $attributes, Warehouse $warehouse, ?User $actor = null): JournalEntry
    {
        return $this->postForBranchWithinTransaction($attributes, $warehouse->branch, $warehouse, $actor);
    }

    public function postForBranch(array $attributes, Branch $branch, ?Warehouse $warehouse = null, ?User $actor = null): JournalEntry
    {
        return DB::transaction(fn (): JournalEntry => $this->postForBranchWithinTransaction($attributes, $branch, $warehouse, $actor), 3);
    }

    /** Join an existing outer transaction for a branch-scoped event. */
    public function postForBranchWithinTransaction(array $attributes, Branch $branch, ?Warehouse $warehouse = null, ?User $actor = null): JournalEntry
    {
        if ($warehouse !== null && $warehouse->branch_id !== $branch->id) {
            throw ValidationException::withMessages(['warehouse_id' => 'คลังต้องอยู่ในสาขาเดียวกับรายการบัญชี']);
        }

        $posting = $this->normalize($this->validatePosting($attributes), $branch, $warehouse);
        $key = PostingIdentity::key($posting['source_type'], $posting['event_code'], $posting['source_id']);
        $hash = PostingIdentity::fingerprint($posting);

        // Book is the sequence lock and serializes retries for one accounting event family.
        $book = JournalBook::query()->where('type', PostingEvent::bookType($posting['event_code']))->lockForUpdate()->first();
        if (! $book) {
            throw ValidationException::withMessages(['event_code' => 'ไม่พบสมุดบัญชีของ event นี้']);
        }

        $existing = JournalEntry::query()->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($existing) {
            if (! hash_equals((string) $existing->posting_hash, $hash)) {
                throw ValidationException::withMessages(['source_id' => 'เอกสารนี้เคย Post ด้วยข้อมูลบัญชีคนละชุด กรุณาตรวจสอบเอกสารเดิม']);
            }

            return $existing;
        }

        if (! $book->is_active) {
            throw ValidationException::withMessages(['event_code' => 'สมุดบัญชีของ event นี้ปิดใช้งานอยู่']);
        }

        $period = FiscalPeriod::query()
            ->whereDate('start_date', '<=', $posting['entry_date'])
            ->whereDate('end_date', '>=', $posting['entry_date'])
            ->where('status', 'OPEN')
            ->lockForUpdate()
            ->first();
        if (! $period) {
            throw ValidationException::withMessages(['entry_date' => 'วันที่ Post ต้องอยู่ในงวดบัญชีที่เปิดอยู่']);
        }

        $accounts = Account::query()
            ->whereKey(collect($posting['lines'])->pluck('account_id')->unique())
            ->sharedLock()
            ->get()
            ->keyBy('id');
        foreach ($posting['lines'] as $index => $line) {
            $account = $accounts->get($line['account_id']);
            if (! $account || ! $account->is_active || ! $account->is_postable) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => 'บัญชีต้องเปิดใช้งานและลงรายการได้']);
            }
            if ($account->control_account_type !== null && (! $line['subledger_type'] || ! $line['subledger_id'])) {
                throw ValidationException::withMessages(["lines.{$index}.subledger_id" => 'บัญชีคุมต้องมีประเภทและรหัส Subledger']);
            }
        }

        $sequence = (int) JournalEntry::query()
            ->whereBelongsTo($book, 'book')->whereBelongsTo($period, 'period')->max('sequence_number') + 1;
        $now = now();
        $reason = "Post อัตโนมัติจาก {$posting['source_type']} / {$posting['event_code']}";
        $entry = JournalEntry::query()->create([
            'journal_book_id' => $book->id,
            'fiscal_period_id' => $period->id,
            'branch_id' => $branch->id,
            'warehouse_id' => $warehouse?->id,
            'sequence_number' => $sequence,
            'entry_number' => sprintf('%s-%s-%06d', $book->sequence_prefix, str_replace('-', '', substr($posting['entry_date'], 0, 7)), $sequence),
            'entry_date' => $posting['entry_date'],
            'document_date' => $posting['document_date'],
            'source_type' => $posting['source_type'],
            'source_event' => $posting['event_code'],
            'source_id' => $posting['source_id'],
            'source_reference' => $posting['source_reference'],
            'idempotency_key' => $key,
            'posting_hash' => $hash,
            'posting_metadata' => $posting['posting_metadata'],
            'description' => $posting['description'],
            'currency_code' => CompanySetting::query()->value('base_currency') ?: 'THB',
            'exchange_rate' => 1,
            'status' => 'POSTED',
            'validated_by' => $actor?->id,
            'validated_at' => $now,
            'validation_reason' => $reason,
            'posted_by' => $actor?->id,
            'posted_at' => $now,
            'posting_reason' => $reason,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);
        $entry->lines()->createMany(collect($posting['lines'])->values()->map(fn (array $line, int $index) => [
            ...$line,
            'line_number' => $index + 1,
        ])->all());

        return $entry;
    }

    public function reverse(JournalEntry $entry, array $attributes, ?User $actor = null): JournalEntry
    {
        return DB::transaction(fn (): JournalEntry => $this->reverseWithinTransaction($entry, $attributes, $actor), 3);
    }

    public function reverseWithinTransaction(JournalEntry $entry, array $attributes, ?User $actor = null): JournalEntry
    {
        $attributes['source_type'] = strtoupper(trim((string) ($attributes['source_type'] ?? '')));
        $attributes['source_id'] = trim((string) ($attributes['source_id'] ?? ''));
        $attributes['reason'] = trim((string) ($attributes['reason'] ?? ''));
        $values = Validator::make($attributes, [
            'source_type' => ['required', 'string', 'max:30', 'regex:/^[A-Z][A-Z0-9_]*$/', 'not_in:MANUAL,REVERSAL'],
            'source_id' => ['required', 'string', 'max:100'],
            'reversal_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ])->validate();
        $key = PostingIdentity::key($values['source_type'], 'journal.reversal', "{$entry->id}:{$values['source_id']}");
        $hash = PostingIdentity::fingerprint([
            'journal_entry_id' => $entry->id,
            ...$values,
        ]);

        return $this->writer->reverseWithinTransaction($entry, $values['reversal_date'], $values['reason'], $actor, [
            'source_type' => $values['source_type'],
            'source_event' => 'journal.reversal',
            'source_id' => $values['source_id'],
            'idempotency_key' => $key,
            'posting_hash' => $hash,
        ]);
    }

    private function validatePosting(array $attributes): array
    {
        foreach (['source_type', 'source_reference', 'event_code', 'description'] as $field) {
            if (isset($attributes[$field]) && is_string($attributes[$field])) {
                $attributes[$field] = trim($attributes[$field]);
            }
        }
        $attributes['source_type'] = strtoupper((string) ($attributes['source_type'] ?? ''));
        $attributes['source_id'] = trim((string) ($attributes['source_id'] ?? ''));
        $attributes['event_code'] = strtolower((string) ($attributes['event_code'] ?? ''));
        if (isset($attributes['lines']) && is_array($attributes['lines'])) {
            $attributes['lines'] = array_map(function ($line) {
                if (! is_array($line)) {
                    return $line;
                }

                $line['subledger_type'] = strtoupper(trim((string) ($line['subledger_type'] ?? ''))) ?: null;
                $line['subledger_id'] = trim((string) ($line['subledger_id'] ?? '')) ?: null;
                $line['tax_point_date'] = $line['tax_point_date'] ?? null;
                $line['tax_settlement_date'] = $line['tax_settlement_date'] ?? null;

                return $line;
            }, $attributes['lines']);
        }

        return Validator::make($attributes, [
            'source_type' => ['required', 'string', 'max:30', 'regex:/^[A-Z][A-Z0-9_]*$/', 'not_in:MANUAL,REVERSAL'],
            'source_id' => ['required', 'string', 'max:100'],
            'source_reference' => ['nullable', 'string', 'max:100'],
            'event_code' => ['required', 'string', Rule::in(PostingEvent::codes())],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:500'],
            'posting_metadata' => ['nullable', 'array'],
            'posting_metadata.contract_version' => ['required_with:posting_metadata', 'integer', 'min:1'],
            'posting_metadata.event_code' => ['required_with:posting_metadata', 'string', 'max:80'],
            'posting_metadata.accounts' => ['required_with:posting_metadata', 'array', 'max:50'],
            'posting_metadata.accounts.*.account_role' => ['required', 'string', 'max:80', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'posting_metadata.accounts.*.account_id' => ['required', 'integer', 'min:1'],
            'posting_metadata.accounts.*.source' => ['required', Rule::in(['ORIGINAL', 'DOCUMENT', 'MASTER', 'MAPPING'])],
            'posting_metadata.accounts.*.source_type' => ['nullable', 'string', 'max:80'],
            'posting_metadata.accounts.*.source_id' => ['nullable', 'string', 'max:100'],
            'posting_metadata.accounts.*.mapping_id' => ['nullable', 'integer', 'min:1'],
            'posting_metadata.accounts.*.mapping_version' => ['nullable', 'integer', 'min:1'],
            // A depreciation run retains one subledger line per Asset when its account is controlled.
            'lines' => ['required', 'array', 'min:2', 'max:2000'],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.tax_code_id' => ['nullable', 'integer', 'exists:tax_codes,id'],
            'lines.*.subledger_type' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'lines.*.subledger_id' => ['nullable', 'string', 'max:100'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.debit' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'lines.*.credit' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'lines.*.tax_base' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999999999.99'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999999999.99'],
            'lines.*.tax_point_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.tax_settlement_date' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
    }

    private function normalize(array $posting, Branch $branch, ?Warehouse $warehouse): array
    {
        $posting['source_reference'] = trim((string) ($posting['source_reference'] ?? '')) ?: null;
        $posting['document_date'] ??= null;
        $posting['warehouse_id'] = $warehouse?->id;
        $posting['branch_id'] = $branch->id;
        $posting['posting_metadata'] = isset($posting['posting_metadata'])
            ? PostingIdentity::canonicalize($posting['posting_metadata'])
            : null;
        $posting['lines'] = collect($posting['lines'])->map(function (array $line, int $index) {
            $line = [
                'account_id' => (int) $line['account_id'],
                'tax_code_id' => isset($line['tax_code_id']) ? (int) $line['tax_code_id'] : null,
                'subledger_type' => strtoupper(trim((string) ($line['subledger_type'] ?? ''))) ?: null,
                'subledger_id' => trim((string) ($line['subledger_id'] ?? '')) ?: null,
                'description' => trim((string) ($line['description'] ?? '')) ?: null,
                'debit' => JournalBalance::decimal($line['debit']),
                'credit' => JournalBalance::decimal($line['credit']),
                'tax_base' => isset($line['tax_base']) ? JournalBalance::decimal($line['tax_base']) : null,
                'tax_amount' => isset($line['tax_amount']) ? JournalBalance::decimal($line['tax_amount']) : null,
                'tax_point_date' => $line['tax_point_date'] ?? null,
                'tax_settlement_date' => $line['tax_settlement_date'] ?? null,
            ];
            if (($line['subledger_type'] === null) !== ($line['subledger_id'] === null)) {
                throw ValidationException::withMessages(["lines.{$index}.subledger_id" => 'ประเภทและรหัส Subledger ต้องระบุคู่กัน']);
            }
            if (($line['debit'] !== '0.00') === ($line['credit'] !== '0.00')) {
                throw ValidationException::withMessages(["lines.{$index}.debit" => 'แต่ละบรรทัดต้องมีจำนวนเงินด้านเดบิตหรือเครดิตเพียงด้านเดียว']);
            }

            return $line;
        })->all();

        $totals = JournalBalance::totals($posting['lines']);
        if ($totals['debit'] <= 0 || $totals['debit'] !== $totals['credit']) {
            throw ValidationException::withMessages(['lines' => 'ยอดรวมเดบิตและเครดิตต้องเท่ากันและมากกว่าศูนย์']);
        }
        $this->assertMetadataMatchesPosting($posting);

        return $posting;
    }

    private function assertMetadataMatchesPosting(array $posting): void
    {
        $metadata = $posting['posting_metadata'];
        if ($metadata === null) {
            return;
        }
        if (strtolower(trim((string) $metadata['event_code'])) !== $posting['event_code']) {
            throw ValidationException::withMessages(['posting_metadata.event_code' => 'Posting metadata ต้องอ้างอิง event เดียวกับ Journal']);
        }

        $lineAccounts = collect($posting['lines'])->pluck('account_id')->map(fn (int $id): string => (string) $id)->unique();
        $roles = [];
        foreach ($metadata['accounts'] as $index => $snapshot) {
            if (! $lineAccounts->contains((string) $snapshot['account_id'])) {
                throw ValidationException::withMessages(["posting_metadata.accounts.{$index}.account_id" => 'บัญชีใน Posting metadata ต้องอยู่ใน Journal lines']);
            }
            if (isset($roles[$snapshot['account_role']])) {
                throw ValidationException::withMessages(["posting_metadata.accounts.{$index}.account_role" => 'Account role ใน Posting metadata ห้ามซ้ำ']);
            }
            $roles[$snapshot['account_role']] = true;
        }
    }
}
