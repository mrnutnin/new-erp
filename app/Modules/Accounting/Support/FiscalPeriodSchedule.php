<?php

namespace App\Modules\Accounting\Support;

use Carbon\CarbonImmutable;

final class FiscalPeriodSchedule
{
    public static function endDate(string $startDate): string
    {
        return CarbonImmutable::parse($startDate)->addYear()->subDay()->toDateString();
    }

    public static function periods(string $startDate): array
    {
        $start = CarbonImmutable::parse($startDate);

        return array_map(function (int $index) use ($start) {
            $periodStart = $start->addMonthsNoOverflow($index);

            return [
                'period_number' => $index + 1,
                'name' => $periodStart->locale('th')->translatedFormat('F Y'),
                'start_date' => $periodStart->toDateString(),
                'end_date' => $periodStart->addMonthNoOverflow()->subDay()->toDateString(),
                'status' => 'OPEN',
            ];
        }, range(0, 11));
    }
}
