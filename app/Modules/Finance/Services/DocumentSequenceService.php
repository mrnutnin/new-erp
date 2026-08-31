<?php

namespace App\Modules\Finance\Services;

use App\Models\Branch;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\DocumentSequenceCounter;
use App\Modules\Finance\Models\DocumentSequenceHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DocumentSequenceService
{
    public function issue(DocumentSequence $sequence, CarbonInterface $date): string
    {
        return DB::transaction(function () use ($sequence, $date): string {
            $sequence = DocumentSequence::query()->lockForUpdate()->findOrFail($sequence->id);
            if (! $sequence->is_active) {
                throw ValidationException::withMessages(['document_sequence' => 'รูปแบบเลขเอกสารถูกปิดใช้งาน']);
            }
            $resetKey = match ($sequence->reset_rule) {
                'MONTHLY' => $date->format('Ym'),
                'YEARLY' => $date->format('Y'),
                default => 'NEVER',
            };
            [$sequence->next_number, $sequence->last_reset_key] = $this->resolveCounter(
                $sequence->reset_rule,
                $sequence->last_reset_key,
                $resetKey,
                (int) $sequence->next_number,
            );
            $number = (int) $sequence->next_number;
            $sequence->next_number = $number + 1;
            $sequence->save();

            return $this->format($sequence, $date, $number);
        });
    }

    public function issueForBranch(DocumentSequence $sequence, Branch $branch, CarbonInterface $date): string
    {
        return DB::transaction(function () use ($sequence, $branch, $date): string {
            $sequence = DocumentSequence::query()->lockForUpdate()->findOrFail($sequence->id);
            if (! $sequence->is_active) {
                throw ValidationException::withMessages(['document_sequence' => 'รูปแบบเลขเอกสารถูกปิดใช้งาน']);
            }
            $counter = DocumentSequenceCounter::query()->firstOrCreate(
                ['document_sequence_id' => $sequence->id, 'branch_id' => $branch->id],
                ['next_number' => 1],
            );
            $counter = DocumentSequenceCounter::query()->lockForUpdate()->findOrFail($counter->id);
            $resetKey = match ($sequence->reset_rule) {
                'MONTHLY' => $date->format('Ym'), 'YEARLY' => $date->format('Y'), default => 'NEVER',
            };
            [$counter->next_number, $counter->last_reset_key] = $this->resolveCounter($sequence->reset_rule, $counter->last_reset_key, $resetKey, (int) $counter->next_number);
            $number = (int) $counter->next_number;
            $counter->next_number = $number + 1;
            $counter->save();

            return $this->format($sequence, $date, $number, $branch->code);
        });
    }

    /** @param callable(string): bool $exists */
    public function issueAvailable(DocumentSequence $sequence, CarbonInterface $date, callable $exists): string
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $number = $this->issue($sequence, $date);
            if (! $exists($number)) {
                return $number;
            }
        }

        throw ValidationException::withMessages(['document_number' => 'ไม่สามารถออกเลขเอกสารที่ไม่ซ้ำได้']);
    }

    /** @param callable(string): bool $exists */
    public function issueAvailableForBranch(DocumentSequence $sequence, Branch $branch, CarbonInterface $documentDate, callable $exists): string
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $number = $this->issueForBranch($sequence, $branch, $documentDate);
            if (! $exists($number)) {
                return $number;
            }
        }

        throw ValidationException::withMessages(['document_number' => 'ไม่สามารถออกเลขเอกสารที่ไม่ซ้ำได้']);
    }

    public function resolveCounter(string $resetRule, ?string $lastResetKey, string $requestedKey, int $nextNumber): array
    {
        if ($lastResetKey === null || $resetRule === 'NEVER') {
            return [$nextNumber, $lastResetKey ?? $requestedKey];
        }
        if ($requestedKey < $lastResetKey) {
            throw ValidationException::withMessages(['document_date' => 'วันที่เอกสารเก่ากว่ารอบเลขเอกสารล่าสุด']);
        }

        return $requestedKey === $lastResetKey ? [$nextNumber, $lastResetKey] : [1, $requestedKey];
    }

    public function format(DocumentSequence $sequence, CarbonInterface $date, int $number, ?string $branchCode = null): string
    {
        $format = preg_replace_callback('/\{NUMBER:(\d+)\}/', fn (array $match) => str_pad((string) $number, (int) $match[1], '0', STR_PAD_LEFT), (string) $sequence->number_format);

        return strtr((string) $format, [
            '{PREFIX}' => $sequence->prefix,
            '{BRANCH}' => strtoupper((string) $branchCode),
            '{YY}' => $date->format('y'),
            '{YYMM}' => $date->format('ym'),
            '{YYYY}' => $date->format('Y'),
            '{MM}' => $date->format('m'),
        ]);
    }

    public function recordIssued(DocumentSequence $sequence, string $number, string $sourceType, int $sourceId, CarbonInterface $date, ?int $userId = null): DocumentSequenceHistory
    {
        return DocumentSequenceHistory::query()->create([
            'document_sequence_id' => $sequence->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'document_number' => $number,
            'document_date' => $date->toDateString(),
            'status' => 'ACTIVE',
            'created_by' => $userId,
        ]);
    }

    public function replaceDraftNumber(DocumentSequence $sequence, string $oldNumber, string $sourceType, int $sourceId, CarbonInterface $date, ?int $userId = null): string
    {
        DocumentSequenceHistory::query()
            ->where('document_sequence_id', $sequence->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('document_number', $oldNumber)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->update(['status' => 'SUPERSEDED']);

        $number = $this->issue($sequence, $date);
        $this->recordIssued($sequence->fresh(), $number, $sourceType, $sourceId, $date, $userId);

        return $number;
    }

    public function replaceDraftNumberForBranch(DocumentSequence $sequence, Branch $branch, string $oldNumber, string $sourceType, int $sourceId, CarbonInterface $documentDate, ?int $userId = null): string
    {
        DocumentSequenceHistory::query()->where('document_sequence_id', $sequence->id)->where('source_type', $sourceType)->where('source_id', $sourceId)->where('document_number', $oldNumber)->where('status', 'ACTIVE')->lockForUpdate()->update(['status' => 'SUPERSEDED']);
        $number = $this->issueForBranch($sequence, $branch, $documentDate);
        $this->recordIssued($sequence->fresh(), $number, $sourceType, $sourceId, $documentDate, $userId);

        return $number;
    }
}
