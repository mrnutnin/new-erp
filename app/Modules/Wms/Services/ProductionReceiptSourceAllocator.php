<?php

namespace App\Modules\Wms\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class ProductionReceiptSourceAllocator
{
    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public function allocate(array $rows, string $consumedValue): array
    {
        $rows = collect($rows)->sortBy('source_allocation_id')->values();
        $available = $rows->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus((string) $row['available_value']), BigDecimal::zero());
        $wanted = BigDecimal::of($consumedValue);
        if ($wanted->isLessThanOrEqualTo(0) || $wanted->isGreaterThan($available)) {
            throw ValidationException::withMessages(['sources' => 'ต้นทุนที่ใช้ต้องมากกว่า 0 และไม่เกินต้นทุนคงเหลือของใบเบิก']);
        }

        $ratio = $wanted->dividedBy($available, 16, RoundingMode::HALF_UP);
        $distributed = BigDecimal::zero();

        return $rows->map(function (array $row, int $index) use ($rows, $ratio, $wanted, &$distributed): array {
            $value = $index === $rows->count() - 1
                ? $wanted->minus($distributed)
                : BigDecimal::of((string) $row['available_value'])->multipliedBy($ratio)->toScale(8, RoundingMode::HALF_UP);
            $distributed = $distributed->plus($value);

            return [
                ...$row,
                'consumed_quantity' => BigDecimal::of((string) $row['available_quantity'])->multipliedBy($ratio)->toScale(8, RoundingMode::HALF_UP)->__toString(),
                'consumed_value' => $value->toScale(8, RoundingMode::HALF_UP)->__toString(),
            ];
        })->all();
    }
}
