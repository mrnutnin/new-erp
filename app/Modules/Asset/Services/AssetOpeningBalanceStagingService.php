<?php

namespace App\Modules\Asset\Services;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetOpeningBalanceBatch;
use App\Modules\Asset\Models\AssetOpeningBalanceLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opening balances are staged and reconciled here. Capitalization owns the
 * later asset/value-event projection; opening staging never creates a GL entry.
 */
final class AssetOpeningBalanceStagingService
{
    public const SOURCE_TYPE = 'OPENING';

    public function create(Branch $branch, array $attributes, User $actor): AssetOpeningBalanceBatch
    {
        return AssetOpeningBalanceBatch::query()->create([
            'branch_id' => $branch->id,
            'batch_reference' => trim((string) ($attributes['batch_reference'] ?? '')),
            'source_system' => trim((string) ($attributes['source_system'] ?? 'OPENING_BALANCE')),
            'cutover_date' => $attributes['cutover_date'] ?? null,
            'reconciliation_reference' => trim((string) ($attributes['reconciliation_reference'] ?? '')),
            'created_by' => $actor->id,
        ]);
    }

    public function addLine(AssetOpeningBalanceBatch $batch, array $attributes): AssetOpeningBalanceLine
    {
        return DB::transaction(function () use ($batch, $attributes): AssetOpeningBalanceLine {
            $batch = AssetOpeningBalanceBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->assertDraft($batch);
            $values = $this->normalizeLine($attributes);

            $line = $batch->lines()->create($values);
            $this->refreshTotals($batch);

            return $line;
        });
    }

    public function validate(AssetOpeningBalanceBatch $batch, User $actor): AssetOpeningBalanceBatch
    {
        return DB::transaction(function () use ($batch, $actor): AssetOpeningBalanceBatch {
            $batch = AssetOpeningBalanceBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->assertDraft($batch);
            if ($batch->total_rows < 1) {
                throw ValidationException::withMessages(['opening_batch' => 'Opening balance ต้องมีอย่างน้อยหนึ่งรายการก่อนตรวจสอบ']);
            }

            $batch->update(['status' => 'VALIDATED', 'validated_by' => $actor->id, 'validated_at' => now()]);

            return $batch;
        });
    }

    /**
     * The capitalization transaction calls this only after every line has an
     * Asset and its immutable OPENING value event. No Journal is created here.
     */
    public function markCommitted(AssetOpeningBalanceBatch $batch, User $actor): AssetOpeningBalanceBatch
    {
        return DB::transaction(function () use ($batch, $actor): AssetOpeningBalanceBatch {
            $batch = AssetOpeningBalanceBatch::query()->with('lines')->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== 'VALIDATED' || $batch->lines->contains(fn (AssetOpeningBalanceLine $line): bool => ! $this->isCommittedLine($batch, $line))) {
                throw ValidationException::withMessages(['opening_batch' => 'Opening balance ต้องมีสินทรัพย์ครบทุกบรรทัดก่อนยืนยัน']);
            }

            $batch->update(['status' => 'COMMITTED', 'committed_by' => $actor->id, 'committed_at' => now()]);

            return $batch;
        });
    }

    /**
     * Called by capitalization inside its posting transaction before projecting
     * this line into an Asset and its OPENING value event.
     */
    public function assertReadyForCommit(AssetOpeningBalanceLine $line): void
    {
        $line = AssetOpeningBalanceLine::query()->with('batch')->lockForUpdate()->findOrFail($line->id);
        if ($line->batch->status !== 'VALIDATED') {
            throw ValidationException::withMessages(['opening_batch' => 'Opening balance ต้องผ่านการตรวจสอบก่อนนำเข้า']);
        }
        if ($line->asset_id || Asset::withTrashed()->where([
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $line->asset_opening_balance_batch_id,
            'source_line_id' => $line->id,
        ])->exists()) {
            throw ValidationException::withMessages(['opening_line' => 'Opening balance รายการนี้ถูกนำเข้าแล้ว']);
        }
    }

    private function assertDraft(AssetOpeningBalanceBatch $batch): void
    {
        if ($batch->status !== 'DRAFT') {
            throw ValidationException::withMessages(['opening_batch' => 'แก้ไข Opening balance ได้เฉพาะชุดร่าง']);
        }
    }

    private function normalizeLine(array $attributes): array
    {
        $cost = $this->decimal($attributes['opening_cost'] ?? null, 'opening_cost');
        $depreciation = $this->decimal($attributes['opening_accumulated_depreciation'] ?? 0, 'opening_accumulated_depreciation');
        $impairment = $this->decimal($attributes['opening_accumulated_impairment'] ?? 0, 'opening_accumulated_impairment');
        $bookValue = $cost->minus($depreciation)->minus($impairment);

        if ($cost->isNegative() || $depreciation->isNegative() || $impairment->isNegative() || $bookValue->isNegative()) {
            throw ValidationException::withMessages(['opening_cost' => 'ยอดยกมาต้นทุน ค่าเสื่อม และด้อยค่าต้องไม่ทำให้มูลค่าตามบัญชีติดลบ']);
        }
        if (trim((string) ($attributes['row_key'] ?? '')) === '' || ! is_array($attributes['asset_payload'] ?? null)) {
            throw ValidationException::withMessages(['row_key' => 'row_key และข้อมูลทะเบียนสินทรัพย์ต้องครบถ้วน']);
        }

        return [
            'row_key' => trim((string) $attributes['row_key']),
            'source_reference' => $this->nullableText($attributes['source_reference'] ?? null),
            'asset_payload' => $attributes['asset_payload'],
            'opening_cost' => $cost->__toString(),
            'opening_accumulated_depreciation' => $depreciation->__toString(),
            'opening_accumulated_impairment' => $impairment->__toString(),
            'opening_book_value' => $bookValue->__toString(),
        ];
    }

    private function refreshTotals(AssetOpeningBalanceBatch $batch): void
    {
        $lines = $batch->lines()->get();
        $sum = fn (string $column): string => $lines->reduce(
            fn (BigDecimal $total, AssetOpeningBalanceLine $line): BigDecimal => $total->plus((string) $line->{$column}),
            BigDecimal::zero(),
        )->toScale(2, RoundingMode::HALF_UP)->__toString();

        $batch->update([
            'total_rows' => $lines->count(),
            'total_opening_cost' => $sum('opening_cost'),
            'total_accumulated_depreciation' => $sum('opening_accumulated_depreciation'),
            'total_accumulated_impairment' => $sum('opening_accumulated_impairment'),
        ]);
    }

    private function isCommittedLine(AssetOpeningBalanceBatch $batch, AssetOpeningBalanceLine $line): bool
    {
        return $line->asset_id !== null && Asset::withTrashed()->whereKey($line->asset_id)->where([
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $batch->id,
            'source_line_id' => $line->id,
        ])->exists();
    }

    private function decimal(mixed $value, string $field): BigDecimal
    {
        try {
            return BigDecimal::of((string) $value)->toScale(2, RoundingMode::HALF_UP);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'ยอดเงินไม่ถูกต้อง']);
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
