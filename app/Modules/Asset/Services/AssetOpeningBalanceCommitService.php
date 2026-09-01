<?php

namespace App\Modules\Asset\Services;

use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetDepreciationBook;
use App\Modules\Asset\Models\AssetHistory;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Asset\Models\AssetOpeningBalanceBatch;
use App\Modules\Asset\Models\AssetOpeningBalanceLine;
use App\Modules\Asset\Models\AssetValueEvent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Projects one validated cutover batch into the asset register. Opening data
 * already exists in the opening GL, so this service deliberately has no GL side effect.
 */
final class AssetOpeningBalanceCommitService
{
    public function __construct(private readonly AssetOpeningBalanceStagingService $staging) {}

    public function commit(AssetOpeningBalanceBatch $batch, User $actor): AssetOpeningBalanceBatch
    {
        return DB::transaction(function () use ($batch, $actor): AssetOpeningBalanceBatch {
            $batch = AssetOpeningBalanceBatch::query()->with('lines')->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'COMMITTED') {
                return $batch;
            }
            if ($batch->status !== 'VALIDATED') {
                throw ValidationException::withMessages(['opening_batch' => 'Opening balance ต้องผ่านการตรวจสอบก่อนนำเข้า']);
            }

            foreach ($batch->lines as $line) {
                $this->staging->assertReadyForCommit($line);
                $asset = $this->projectLine($batch, $line, $actor);
                $line->update(['asset_id' => $asset->id]);
            }

            return $this->staging->markCommitted($batch, $actor);
        }, 3);
    }

    private function projectLine(AssetOpeningBalanceBatch $batch, AssetOpeningBalanceLine $line, User $actor): Asset
    {
        $payload = $line->asset_payload;
        $category = $this->category($payload);
        $this->assertReferencesBelongToBranch($payload, $batch);
        $acquisitionDate = $this->requiredDate($payload, 'acquisition_date');
        $registrationDate = $this->date($payload['registration_date'] ?? null) ?? $acquisitionDate;
        $placedInServiceDate = $this->date($payload['placed_in_service_date'] ?? null);
        $cost = $this->amount($line->opening_cost);
        $depreciation = $this->amount($line->opening_accumulated_depreciation);
        $impairment = $this->amount($line->opening_accumulated_impairment);

        $asset = Asset::query()->create([
            'asset_number' => $this->requiredText($payload, 'asset_number'),
            'tag_number' => $this->text($payload, 'tag_number'),
            'barcode_value' => $this->text($payload, 'barcode_value'),
            'branch_id' => $batch->branch_id,
            'warehouse_id' => $this->nullableId($payload, 'warehouse_id'),
            'location_id' => $this->nullableId($payload, 'location_id'),
            'custodian_user_id' => $this->nullableId($payload, 'custodian_user_id'),
            'asset_category_id' => $category->id,
            'parent_asset_id' => $this->nullableId($payload, 'parent_asset_id'),
            'name' => $this->requiredText($payload, 'name'),
            'description' => $this->text($payload, 'description'),
            'brand' => $this->text($payload, 'brand'),
            'model' => $this->text($payload, 'model'),
            'serial_number' => $this->text($payload, 'serial_number'),
            'manufacturer' => $this->text($payload, 'manufacturer'),
            'registration_date' => $registrationDate,
            'acquisition_date' => $acquisitionDate,
            'placed_in_service_date' => $placedInServiceDate,
            'supplier_id' => $this->nullableId($payload, 'supplier_id'),
            'warranty_end_date' => $this->date($payload['warranty_end_date'] ?? null),
            'insurance_policy_number' => $this->text($payload, 'insurance_policy_number'),
            'insurance_end_date' => $this->date($payload['insurance_end_date'] ?? null),
            'original_cost' => $cost,
            'currency_code' => $this->currencyCode($payload),
            'exchange_rate' => $this->positiveAmount($payload['exchange_rate'] ?? 1, 6),
            'book_cost' => $cost,
            'book_accumulated_depreciation' => $depreciation,
            'book_accumulated_impairment' => $impairment,
            'book_value' => $this->amount($line->opening_book_value),
            'status' => 'ACTIVE',
            'is_depreciation_suspended' => (bool) ($payload['is_depreciation_suspended'] ?? false),
            'status_reason' => $this->text($payload, 'status_reason'),
            'source_type' => AssetOpeningBalanceStagingService::SOURCE_TYPE,
            'source_id' => $batch->id,
            'source_line_id' => $line->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->createDepreciationBooks(
            $asset,
            $category,
            $cost,
            $depreciation,
            $placedInServiceDate ?? $acquisitionDate,
            CarbonImmutable::parse($batch->cutover_date->toDateString()),
            $actor,
        );
        AssetValueEvent::query()->create([
            'asset_id' => $asset->id,
            'branch_id' => $batch->branch_id,
            'event_date' => $batch->cutover_date,
            'event_type' => 'OPENING',
            'cost_delta' => $cost,
            'depreciation_delta' => $depreciation,
            'impairment_delta' => $impairment,
            'source_type' => AssetOpeningBalanceStagingService::SOURCE_TYPE,
            'source_id' => $batch->id,
            'source_line_id' => $line->id,
            'idempotency_key' => hash('sha256', "asset-opening|{$batch->id}|{$line->id}"),
            'created_by' => $actor->id,
        ]);
        AssetHistory::query()->create([
            'asset_id' => $asset->id,
            'event_type' => 'OPENING_COMMITTED',
            'occurred_at' => now(),
            'source_type' => AssetOpeningBalanceStagingService::SOURCE_TYPE,
            'source_id' => $batch->id,
            'source_document_number' => $batch->batch_reference,
            'actor_id' => $actor->id,
            'new_branch_id' => $asset->branch_id,
            'new_location_id' => $asset->location_id,
            'new_custodian_user_id' => $asset->custodian_user_id,
            'new_status' => $asset->status,
            'new_values' => $asset->fresh()->toArray(),
        ]);

        return $asset;
    }

    private function createDepreciationBooks(Asset $asset, AssetCategory $category, string $cost, string $accumulatedDepreciation, CarbonImmutable $startDate, CarbonImmutable $cutoverDate, User $actor): void
    {
        if (! $category->is_depreciable) {
            return;
        }

        $this->createDepreciationBook($asset, $category, 'BOOK', $cost, $accumulatedDepreciation, $startDate, $cutoverDate, $actor);
        if ($category->tax_useful_life_months || $category->tax_rate_percent || $category->tax_cost_cap) {
            $this->createDepreciationBook($asset, $category, 'TAX', $cost, '0.00', $startDate, $cutoverDate, $actor);
        }
    }

    private function createDepreciationBook(Asset $asset, AssetCategory $category, string $type, string $cost, string $accumulatedDepreciation, CarbonImmutable $startDate, CarbonImmutable $cutoverDate, User $actor): void
    {
        $months = $type === 'BOOK' ? $category->book_useful_life_months : $category->tax_useful_life_months;
        $residual = $type === 'BOOK'
            ? BigDecimal::of($cost)->multipliedBy((string) $category->book_residual_value_percent)->dividedBy(100, 2, RoundingMode::HALF_UP)->__toString()
            : '0.00';

        AssetDepreciationBook::query()->create([
            'asset_id' => $asset->id,
            'book_type' => $type,
            'method' => $type === 'BOOK' ? $category->book_method : $category->tax_method,
            'depreciable_cost' => $cost,
            'residual_value' => $residual,
            'useful_life_months' => $months,
            'start_date' => $startDate,
            'end_date' => $months ? $startDate->addMonthsNoOverflow((int) $months)->subDay() : null,
            'tax_rate_percent' => $type === 'TAX' ? $category->tax_rate_percent : null,
            'tax_cost_cap' => $type === 'TAX' ? $category->tax_cost_cap : null,
            'accumulated_depreciation' => $accumulatedDepreciation,
            'last_depreciation_date' => $accumulatedDepreciation !== '0.00' ? $cutoverDate : null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function category(array $payload): AssetCategory
    {
        $id = $this->nullableId($payload, 'asset_category_id');
        $category = $id ? AssetCategory::query()->where('is_active', true)->find($id) : null;
        if (! $category) {
            throw ValidationException::withMessages(['asset_payload.asset_category_id' => 'ไม่พบหมวดสินทรัพย์ที่ใช้งานอยู่']);
        }

        return $category;
    }

    private function assertReferencesBelongToBranch(array $payload, AssetOpeningBalanceBatch $batch): void
    {
        $branchId = $batch->branch_id;
        foreach ([
            'warehouse_id' => Warehouse::query()->where('branch_id', $branchId)->where('is_active', true),
            'location_id' => AssetLocation::query()->where('branch_id', $branchId)->where('is_active', true),
            'parent_asset_id' => Asset::query()->where('branch_id', $branchId),
            'custodian_user_id' => User::query()->where('is_active', true)->whereHas('warehouses', fn ($query) => $query->where('warehouses.branch_id', $branchId)->where('warehouses.is_active', true)),
            'supplier_id' => Party::query()->where('is_active', true),
        ] as $field => $query) {
            $id = $this->nullableId($payload, $field);
            if ($id && ! $query->whereKey($id)->exists()) {
                throw ValidationException::withMessages(["asset_payload.{$field}" => 'ข้อมูลอ้างอิงไม่อยู่ในขอบเขตที่ใช้งานได้']);
            }
        }
    }

    private function requiredText(array $payload, string $field): string
    {
        $value = $this->text($payload, $field);
        if ($value === null) {
            throw ValidationException::withMessages(["asset_payload.{$field}" => 'ต้องระบุข้อมูลนี้']);
        }

        return $value;
    }

    private function text(array $payload, string $field): ?string
    {
        $value = trim((string) ($payload[$field] ?? ''));

        return $value === '' ? null : $value;
    }

    private function nullableId(array $payload, string $field): ?int
    {
        $value = $payload[$field] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function requiredDate(array $payload, string $field): CarbonImmutable
    {
        return $this->date($payload[$field] ?? null) ?? throw ValidationException::withMessages(["asset_payload.{$field}" => 'ต้องระบุวันที่']);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['asset_payload' => 'รูปแบบวันที่ไม่ถูกต้อง']);
        }
    }

    private function amount(mixed $value, int $scale = 2): string
    {
        try {
            return BigDecimal::of((string) $value)->toScale($scale, RoundingMode::HALF_UP)->__toString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['asset_payload' => 'จำนวนเงินไม่ถูกต้อง']);
        }
    }

    private function positiveAmount(mixed $value, int $scale): string
    {
        $amount = $this->amount($value, $scale);
        if (BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['asset_payload.exchange_rate' => 'อัตราแลกเปลี่ยนต้องมากกว่า 0']);
        }

        return $amount;
    }

    private function currencyCode(array $payload): string
    {
        $currency = strtoupper((string) ($payload['currency_code'] ?? 'THB'));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw ValidationException::withMessages(['asset_payload.currency_code' => 'รหัสสกุลเงินต้องเป็นอักษรอังกฤษ 3 ตัว']);
        }

        return $currency;
    }
}
