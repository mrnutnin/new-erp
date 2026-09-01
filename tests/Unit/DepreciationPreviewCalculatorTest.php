<?php

namespace Tests\Unit;

use App\Modules\Asset\Models\AssetDepreciationBook;
use App\Modules\Asset\Services\DepreciationPreviewCalculator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class DepreciationPreviewCalculatorTest extends TestCase
{
    public function test_full_month_schedule_balances_to_zero_without_float_drift(): void
    {
        $book = new AssetDepreciationBook;
        $book->setRawAttributes([
            'method' => 'STRAIGHT_LINE',
            'depreciable_cost' => '100.00',
            'residual_value' => '0.00',
            'useful_life_months' => 3,
            'start_date' => Carbon::parse('2026-01-15'),
        ]);

        $schedule = (new DepreciationPreviewCalculator)->calculate($book, 'FULL_MONTH');

        self::assertSame('2026-03-31', $schedule['end_date']->toDateString());
        self::assertSame(['33.33', '33.33', '33.34'], array_column($schedule['rows'], 'depreciation'));
        self::assertSame('0.00', $schedule['rows'][2]['closing_value']);
    }

    public function test_daily_schedule_allocates_partial_months_and_balances_to_zero(): void
    {
        $book = new AssetDepreciationBook;
        $book->setRawAttributes([
            'method' => 'STRAIGHT_LINE',
            'depreciable_cost' => '365.00',
            'residual_value' => '0.00',
            'useful_life_months' => 1,
            'start_date' => Carbon::parse('2024-02-15'),
        ]);

        $schedule = (new DepreciationPreviewCalculator)->calculate($book, 'DAILY');

        self::assertSame(29, $schedule['total_days']);
        self::assertSame(15, $schedule['rows'][0]['days']);
        self::assertSame(14, $schedule['rows'][1]['days']);
        self::assertSame('0.00', $schedule['rows'][1]['closing_value']);
    }

    public function test_residual_value_is_a_floor_and_final_rounding_balances_exactly(): void
    {
        $book = new AssetDepreciationBook;
        $book->setRawAttributes([
            'method' => 'STRAIGHT_LINE',
            'depreciable_cost' => '100.00',
            'residual_value' => '10.00',
            'useful_life_months' => 3,
            'start_date' => Carbon::parse('2026-01-01'),
        ]);

        $schedule = (new DepreciationPreviewCalculator)->calculate($book, 'FULL_MONTH');

        self::assertSame(['30.00', '30.00', '30.00'], array_column($schedule['rows'], 'depreciation'));
        self::assertSame('10.00', $schedule['rows'][2]['closing_value']);
        self::assertSame('90.00', $schedule['rows'][2]['accumulated_depreciation']);
    }

    public function test_non_depreciable_amount_never_drops_below_residual_value(): void
    {
        $book = new AssetDepreciationBook;
        $book->setRawAttributes([
            'method' => 'STRAIGHT_LINE',
            'depreciable_cost' => '50.00',
            'residual_value' => '50.00',
            'useful_life_months' => 2,
            'start_date' => Carbon::parse('2026-01-01'),
        ]);

        $schedule = (new DepreciationPreviewCalculator)->calculate($book, 'FULL_MONTH');

        self::assertSame(['0.00', '0.00'], array_column($schedule['rows'], 'depreciation'));
        self::assertSame('50.00', $schedule['rows'][1]['closing_value']);
    }

    public function test_run_through_uses_cumulative_full_month_target_and_catch_up(): void
    {
        $book = new AssetDepreciationBook;
        $book->setRawAttributes([
            'method' => 'STRAIGHT_LINE',
            'depreciable_cost' => '100.00',
            'residual_value' => '0.00',
            'useful_life_months' => 3,
            'start_date' => Carbon::parse('2026-01-15'),
        ]);

        $calculation = (new DepreciationPreviewCalculator)->calculateThrough($book, 'FULL_MONTH', '2026-02-15', '33.33');

        self::assertSame('66.66', $calculation['target_accumulated_depreciation']);
        self::assertSame('33.33', $calculation['depreciation_due']);
        self::assertSame('33.33', $calculation['catch_up_depreciation']);
        self::assertSame('33.34', $calculation['closing_value']);
    }

    public function test_run_through_uses_daily_target_for_leap_year_partial_month_and_caps_at_residual(): void
    {
        $book = new AssetDepreciationBook;
        $book->setRawAttributes([
            'method' => 'STRAIGHT_LINE',
            'depreciable_cost' => '365.00',
            'residual_value' => '10.00',
            'useful_life_months' => 1,
            'start_date' => Carbon::parse('2024-02-15'),
        ]);

        $calculation = (new DepreciationPreviewCalculator)->calculateThrough($book, 'DAILY', '2024-02-29', '100.00');

        self::assertSame('183.62', $calculation['target_accumulated_depreciation']);
        self::assertSame('83.62', $calculation['depreciation_due']);
        self::assertSame('181.38', $calculation['closing_value']);
        self::assertSame('355.00', (new DepreciationPreviewCalculator)->calculateThrough($book, 'DAILY', '2024-12-31')['target_accumulated_depreciation']);
    }
}
