<?php

namespace Tests\Unit;

use App\Modules\Accounting\Support\FiscalPeriodSchedule;
use PHPUnit\Framework\TestCase;

class FiscalPeriodScheduleTest extends TestCase
{
    public function test_it_builds_twelve_contiguous_monthly_periods_for_a_non_calendar_year(): void
    {
        $periods = FiscalPeriodSchedule::periods('2026-04-01');

        $this->assertCount(12, $periods);
        $this->assertSame('2026-04-01', $periods[0]['start_date']);
        $this->assertSame('2026-04-30', $periods[0]['end_date']);
        $this->assertSame('2027-03-01', $periods[11]['start_date']);
        $this->assertSame('2027-03-31', $periods[11]['end_date']);
        $this->assertSame('2027-03-31', FiscalPeriodSchedule::endDate('2026-04-01'));

        foreach (array_slice($periods, 1) as $index => $period) {
            $this->assertSame(date('Y-m-d', strtotime($periods[$index]['end_date'].' +1 day')), $period['start_date']);
        }
    }

    public function test_it_keeps_leap_day_inside_february_period(): void
    {
        $periods = FiscalPeriodSchedule::periods('2023-04-01');

        $this->assertSame('2024-02-29', $periods[10]['end_date']);
    }
}
