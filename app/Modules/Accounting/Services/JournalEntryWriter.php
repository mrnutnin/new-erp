<?php

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalBook;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Accounting\Support\JournalEntryState;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JournalEntryWriter
{
    public function create(array $attributes, Warehouse $warehouse, User $user): JournalEntry
    {
        return DB::transaction(function () use ($attributes, $warehouse, $user) {
            $book = JournalBook::query()->where('type', 'GENERAL')->where('is_active', true)->lockForUpdate()->first();
            if (! $book) {
                throw ValidationException::withMessages(['entry_date' => 'กรุณาเปิดใช้งานสมุดรายวันทั่วไปก่อนลงรายการ']);
            }

            $period = $this->openPeriod($attributes['entry_date']);
            $sequence = (int) JournalEntry::query()
                ->whereBelongsTo($book, 'book')->whereBelongsTo($period, 'period')->max('sequence_number') + 1;
            $entry = JournalEntry::query()->create([
                ...Arr::except($attributes, 'lines'),
                'journal_book_id' => $book->id,
                'fiscal_period_id' => $period->id,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'sequence_number' => $sequence,
                'entry_number' => sprintf('%s-%s-%06d', $book->sequence_prefix, CarbonImmutable::parse($attributes['entry_date'])->format('Ym'), $sequence),
                'source_type' => 'MANUAL',
                'currency_code' => 'THB',
                'exchange_rate' => 1,
                'status' => 'DRAFT',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $this->replaceLines($entry, $attributes['lines']);

            return $entry;
        });
    }

    public function update(JournalEntry $entry, array $attributes, User $user): JournalEntry
    {
        return DB::transaction(function () use ($entry, $attributes, $user) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->status !== 'DRAFT') {
                throw ValidationException::withMessages(['entry_date' => 'แก้ไขได้เฉพาะรายการสถานะ Draft']);
            }

            $period = $this->openPeriod($attributes['entry_date']);
            if ($period->id !== $entry->fiscal_period_id) {
                throw ValidationException::withMessages(['entry_date' => 'Draft ที่ออกเลขแล้วไม่สามารถย้ายข้ามงวดบัญชีได้']);
            }
            $entry->update([
                ...Arr::except($attributes, 'lines'),
                'fiscal_period_id' => $period->id,
                'updated_by' => $user->id,
            ]);
            $this->replaceLines($entry, $attributes['lines']);

            return $entry;
        });
    }

    public function submit(JournalEntry $entry, string $reason, User $user): JournalEntry
    {
        return DB::transaction(function () use ($entry, $reason, $user) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $status = $this->transition($entry->status, 'validate');
            $this->assertReadyToPost($entry);
            $entry->update([
                'status' => $status,
                'validated_by' => $user->id,
                'validated_at' => now(),
                'validation_reason' => $reason,
                'updated_by' => $user->id,
            ]);

            return $entry;
        });
    }

    public function approve(JournalEntry $entry, string $reason, User $user): JournalEntry
    {
        return DB::transaction(function () use ($entry, $reason, $user) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $status = $this->transition($entry->status, 'post');
            $this->assertReadyToPost($entry);
            $entry->update([
                'status' => $status,
                'posted_by' => $user->id,
                'posted_at' => now(),
                'posting_reason' => $reason,
                'updated_by' => $user->id,
            ]);

            return $entry;
        });
    }

    public function reverse(JournalEntry $entry, string $reversalDate, string $reason, ?User $user, array $source = []): JournalEntry
    {
        return DB::transaction(fn (): JournalEntry => $this->reverseWithinTransaction($entry, $reversalDate, $reason, $user, $source), 3);
    }

    public function reverseWithinTransaction(JournalEntry $entry, string $reversalDate, string $reason, ?User $user, array $source = []): JournalEntry
    {
        $book = JournalBook::query()->lockForUpdate()->findOrFail($entry->journal_book_id);
        $entry = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($entry->id);
        if ($source && $entry->status === 'REVERSED') {
            $existing = JournalEntry::query()
                ->where('reversal_of_id', $entry->id)
                ->where('idempotency_key', $source['idempotency_key'])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (! hash_equals((string) $existing->posting_hash, $source['posting_hash'])) {
                    throw ValidationException::withMessages(['source_id' => 'รายการนี้เคยกลับด้วยข้อมูลคนละชุด กรุณาตรวจสอบรายการเดิม']);
                }

                return $existing;
            }
        }
        $status = $this->transition($entry->status, 'reverse');
        $this->assertBalanced($entry);

        $period = $this->openPeriod($reversalDate);
        $sequence = (int) JournalEntry::query()
            ->whereBelongsTo($book, 'book')->whereBelongsTo($period, 'period')->max('sequence_number') + 1;
        $reversal = JournalEntry::query()->create([
            'journal_book_id' => $book->id,
            'fiscal_period_id' => $period->id,
            'branch_id' => $entry->branch_id,
            'warehouse_id' => $entry->warehouse_id,
            'sequence_number' => $sequence,
            'entry_number' => sprintf('%s-%s-%06d', $book->sequence_prefix, CarbonImmutable::parse($reversalDate)->format('Ym'), $sequence),
            'entry_date' => $reversalDate,
            'document_date' => $reversalDate,
            'source_type' => $source['source_type'] ?? 'REVERSAL',
            'source_event' => $source['source_event'] ?? null,
            'source_id' => $source['source_id'] ?? null,
            'source_reference' => $entry->entry_number,
            'idempotency_key' => $source['idempotency_key'] ?? null,
            'posting_hash' => $source['posting_hash'] ?? null,
            'posting_metadata' => $entry->posting_metadata === null ? null : [
                'reversal_of_id' => $entry->id,
                'original_posting_metadata' => $entry->posting_metadata,
            ],
            'description' => Str::limit("กลับรายการ {$entry->entry_number}: {$reason}", 500, ''),
            'currency_code' => $entry->currency_code,
            'exchange_rate' => $entry->exchange_rate,
            'status' => 'POSTED',
            'reversal_of_id' => $entry->id,
            'validated_by' => $user?->id,
            'validated_at' => now(),
            'validation_reason' => $reason,
            'posted_by' => $user?->id,
            'posted_at' => now(),
            'posting_reason' => $reason,
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);
        $this->replaceLines($reversal, $entry->lines->map(fn ($line) => [
            'account_id' => $line->account_id,
            'tax_code_id' => $line->tax_code_id,
            'subledger_type' => $line->subledger_type,
            'subledger_id' => $line->subledger_id,
            'description' => $line->description,
            'debit' => $line->credit,
            'credit' => $line->debit,
            'tax_base' => $line->tax_base,
            'tax_amount' => $line->tax_amount,
            'tax_point_date' => $line->tax_point_date?->format('Y-m-d'),
            'tax_settlement_date' => $line->tax_settlement_date?->format('Y-m-d'),
        ])->all());
        $entry->update([
            'status' => $status,
            'reversed_by' => $user?->id,
            'reversed_at' => now(),
            'reversal_reason' => $reason,
            'updated_by' => $user?->id,
        ]);

        return $reversal;
    }

    private function openPeriod(string $entryDate): FiscalPeriod
    {
        $period = FiscalPeriod::query()
            ->whereDate('start_date', '<=', $entryDate)
            ->whereDate('end_date', '>=', $entryDate)
            ->where('status', 'OPEN')
            ->lockForUpdate()
            ->first();

        if (! $period) {
            throw ValidationException::withMessages(['entry_date' => 'วันที่ลงรายการต้องอยู่ในงวดบัญชีที่เปิดอยู่']);
        }

        return $period;
    }

    private function assertReadyToPost(JournalEntry $entry): void
    {
        $period = $this->openPeriod($entry->entry_date->format('Y-m-d'));
        if ($period->id !== $entry->fiscal_period_id) {
            throw ValidationException::withMessages(['entry_date' => 'งวดบัญชีของรายการไม่ตรงกับวันที่ลงบัญชี']);
        }

        $entry->loadMissing('lines.account');
        $this->assertBalanced($entry);
        if ($entry->lines->contains(fn ($line) => ! $line->account || ! $line->account->is_active || ! $line->account->is_postable || $line->account->control_account_type !== null)) {
            throw ValidationException::withMessages(['lines' => 'ทุกรายการต้องใช้บัญชีย่อยที่เปิดใช้งาน ลงรายการได้ และไม่ใช่บัญชีคุม']);
        }
    }

    private function assertBalanced(JournalEntry $entry): void
    {
        $entry->loadMissing('lines');
        $totals = JournalBalance::totals($entry->lines->map->only(['debit', 'credit'])->all());
        $hasInvalidLine = $entry->lines->contains(function ($line) {
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;

            return $debit < 0 || $credit < 0 || (($debit > 0) === ($credit > 0));
        });
        if ($entry->lines->count() < 2 || $hasInvalidLine || $totals['debit'] <= 0 || $totals['debit'] !== $totals['credit']) {
            throw ValidationException::withMessages(['lines' => 'รายการต้องมีอย่างน้อย 2 บรรทัด และยอดเดบิตต้องเท่ากับเครดิต']);
        }
    }

    private function transition(string $currentStatus, string $transition): string
    {
        try {
            return JournalEntryState::{$transition}($currentStatus);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }
    }

    private function replaceLines(JournalEntry $entry, array $lines): void
    {
        $entry->lines()->delete();
        $entry->lines()->createMany(collect($lines)->values()->map(fn (array $line, int $index) => [
            ...$line,
            'line_number' => $index + 1,
            'description' => trim((string) ($line['description'] ?? '')) ?: null,
        ])->all());
    }
}
