<?php

namespace App\Modules\Asset\Services;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Asset\Models\AssetDepreciationBook;
use App\Modules\Asset\Models\AssetDepreciationPolicyChange;
use App\Modules\Asset\Models\AssetHistory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Keeps policy changes prospective; calculator application remains separate. */
final class AssetDepreciationPolicyChangeService
{
    /** @return Collection<int, AssetDepreciationPolicyChange> */
    public function createDrafts(Branch $branch, array $attributes, User $actor): Collection
    {
        return DB::transaction(function () use ($branch, $attributes, $actor): Collection {
            $values = $this->validated($attributes);
            $this->futureOpenPeriod($values['effective_date']);
            $books = $this->books($branch, $values['book_type'], $values['asset_ids']);
            if ($books->count() !== count($values['asset_ids'])) {
                throw ValidationException::withMessages(['asset_ids' => 'พบสินทรัพย์ที่ไม่พร้อมเปลี่ยนนโยบายในสาขาหรือ Book ที่เลือก']);
            }
            if (AssetDepreciationPolicyChange::query()->whereIn('asset_depreciation_book_id', $books->pluck('id'))
                ->whereDate('effective_date', $values['effective_date'])->exists()) {
                throw ValidationException::withMessages(['effective_date' => 'มีคำขอเปลี่ยนนโยบายของสินทรัพย์บางรายการในวันมีผลนี้แล้ว']);
            }

            return $books->map(fn (AssetDepreciationBook $book) => AssetDepreciationPolicyChange::query()->create([
                'asset_depreciation_book_id' => $book->id,
                'effective_date' => $values['effective_date'],
                'status' => 'DRAFT',
                'profile_snapshot' => ['requested_profile' => $this->requestedProfile($values), 'book_type' => $book->book_type],
                'reason' => $values['reason'],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]));
        }, 3);
    }

    /** @param array<int, int|string> $policyChangeIds @return Collection<int, AssetDepreciationPolicyChange> */
    public function approveMany(Branch $branch, array $policyChangeIds, User $actor): Collection
    {
        return DB::transaction(function () use ($branch, $policyChangeIds, $actor): Collection {
            $changes = AssetDepreciationPolicyChange::query()->with('depreciationBook.asset')
                ->whereKey(array_values(array_unique(array_map('intval', $policyChangeIds))))->lockForUpdate()->get();
            if ($changes->count() !== count(array_unique($policyChangeIds))) {
                throw ValidationException::withMessages(['policy_change_ids' => 'ไม่พบคำขอเปลี่ยนนโยบายที่เลือก']);
            }

            foreach ($changes as $change) {
                if ($change->status !== 'DRAFT') {
                    throw ValidationException::withMessages(['policy_change_ids' => 'อนุมัติได้เฉพาะคำขอสถานะร่าง']);
                }
                $book = AssetDepreciationBook::query()->with('asset')->lockForUpdate()->findOrFail($change->asset_depreciation_book_id);
                if ($book->asset->branch_id !== $branch->id || ! $book->is_active || $book->asset->status !== 'ACTIVE') {
                    throw ValidationException::withMessages(['policy_change_ids' => 'มีสินทรัพย์ที่ไม่พร้อมเปลี่ยนนโยบายในสาขาปัจจุบัน']);
                }
                $this->futureOpenPeriod($change->effective_date->toDateString());
                $baseline = $this->baseline($book, $change->effective_date);
                if (BigDecimal::of($change->profile_snapshot['requested_profile']['residual_value'])->isGreaterThan(BigDecimal::of($baseline['remaining_book_value']))) {
                    throw ValidationException::withMessages(['policy_change_ids' => 'มูลค่าซากใหม่ต้องไม่เกินมูลค่าตามบัญชีคงเหลือ']);
                }
                $change->update([
                    'status' => 'APPROVED',
                    'profile_snapshot' => [...$change->profile_snapshot, 'approval_baseline' => $baseline],
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'updated_by' => $actor->id,
                ]);
                AssetHistory::query()->create([
                    'asset_id' => $book->asset_id, 'event_type' => 'DEPRECIATION_POLICY_APPROVED', 'occurred_at' => now(),
                    'source_type' => 'ASSET_DEPRECIATION_POLICY_CHANGE', 'source_id' => $change->id, 'actor_id' => $actor->id,
                    'reason' => $change->reason, 'old_values' => $baseline['book_profile'], 'new_values' => $change->profile_snapshot['requested_profile'],
                ]);
            }

            return $changes->fresh(['depreciationBook.asset', 'approvedBy']);
        }, 3);
    }

    public function cancel(AssetDepreciationPolicyChange $change, string $reason, User $actor): AssetDepreciationPolicyChange
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['cancellation_reason' => 'เหตุผลการยกเลิกต้องมี 10–500 ตัวอักษร']);
        }

        return DB::transaction(function () use ($change, $reason, $actor): AssetDepreciationPolicyChange {
            $change = AssetDepreciationPolicyChange::query()->lockForUpdate()->findOrFail($change->id);
            if ($change->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'ยกเลิกได้เฉพาะคำขอร่างที่ยังไม่อนุมัติ']);
            }

            $change->update(['status' => 'VOID', 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'updated_by' => $actor->id]);

            return $change->fresh(['depreciationBook.asset', 'cancelledBy']);
        }, 3);
    }

    private function validated(array $attributes): array
    {
        $assetIds = array_values(array_unique(array_map('intval', $attributes['asset_ids'] ?? [])));
        if ($assetIds === [] || ! in_array($attributes['book_type'] ?? null, ['BOOK', 'TAX'], true)
            || ($attributes['method'] ?? null) !== 'STRAIGHT_LINE' || ! is_numeric($attributes['useful_life_months'] ?? null)
            || (int) $attributes['useful_life_months'] < 1 || (int) $attributes['useful_life_months'] > 1200
            || ! is_numeric($attributes['residual_value'] ?? null) || BigDecimal::of($attributes['residual_value'])->isNegative()
            || ! is_string($attributes['effective_date'] ?? null) || ! is_string($attributes['reason'] ?? null)) {
            throw ValidationException::withMessages(['policy' => 'ข้อมูลเปลี่ยนนโยบายค่าเสื่อมไม่ถูกต้อง']);
        }

        $reason = trim($attributes['reason']);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'เหตุผลต้องมี 10–500 ตัวอักษร']);
        }

        return [...$attributes, 'asset_ids' => $assetIds, 'reason' => $reason];
    }

    /** @param array<int, int> $assetIds @return Collection<int, AssetDepreciationBook> */
    private function books(Branch $branch, string $bookType, array $assetIds): Collection
    {
        return AssetDepreciationBook::query()->with('asset')->where('book_type', $bookType)->where('is_active', true)
            ->whereIn('asset_id', $assetIds)->whereHas('asset', fn ($query) => $query->where('branch_id', $branch->id)->where('status', 'ACTIVE'))
            ->lockForUpdate()->get();
    }

    private function futureOpenPeriod(string $date): FiscalPeriod
    {
        $period = FiscalPeriod::query()->where('status', 'OPEN')->whereDate('start_date', $date)->first();
        if (! $period || Carbon::parse($date)->isBefore(today()->startOfDay())) {
            throw ValidationException::withMessages(['effective_date' => 'วันมีผลต้องเป็นวันแรกของงวดบัญชีเปิดในวันปัจจุบันหรืออนาคต']);
        }

        return $period;
    }

    private function requestedProfile(array $values): array
    {
        return ['method' => $values['method'], 'useful_life_months' => (int) $values['useful_life_months'], 'residual_value' => BigDecimal::of($values['residual_value'])->toScale(2, RoundingMode::UNNECESSARY)->__toString()];
    }

    private function baseline(AssetDepreciationBook $book, Carbon $effectiveDate): array
    {
        $asset = $book->asset;
        $remaining = $book->book_type === 'BOOK'
            ? BigDecimal::of($asset->book_value)
            : BigDecimal::of($book->depreciable_cost)->minus(BigDecimal::of($book->accumulated_depreciation));
        $remaining = $remaining->isNegative() ? BigDecimal::zero() : $remaining;

        return [
            'as_of_date' => $effectiveDate->subDay()->toDateString(),
            'book_profile' => [
                'method' => $book->method, 'depreciable_cost' => (string) $book->depreciable_cost,
                'residual_value' => (string) $book->residual_value, 'useful_life_months' => $book->useful_life_months,
                'start_date' => $book->start_date?->toDateString(), 'end_date' => $book->end_date?->toDateString(),
                'last_depreciation_date' => $book->last_depreciation_date?->toDateString(),
            ],
            'accumulated_depreciation' => (string) $book->accumulated_depreciation,
            'remaining_book_value' => $remaining->toScale(2, RoundingMode::UNNECESSARY)->__toString(),
            'asset_book_values' => [
                'book_cost' => (string) $asset->book_cost, 'book_accumulated_depreciation' => (string) $asset->book_accumulated_depreciation,
                'book_accumulated_impairment' => (string) $asset->book_accumulated_impairment, 'book_value' => (string) $asset->book_value,
            ],
        ];
    }
}
