<?php

namespace App\Modules\Asset\Services;

use App\Modules\Asset\Models\AssetDepreciationBook;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Builds an illustrative straight-line schedule only. Posted depreciation is
 * deliberately owned by the Phase 3 run service, not this display helper.
 */
class DepreciationPreviewCalculator
{
    public function calculate(AssetDepreciationBook $book, string $proration): array
    {
        if ($book->method !== 'STRAIGHT_LINE' || ! in_array($proration, ['FULL_MONTH', 'DAILY'], true)) {
            throw new InvalidArgumentException('Unsupported depreciation preview.');
        }

        $months = (int) $book->useful_life_months;
        $start = $book->start_date ? CarbonImmutable::parse($book->start_date->toDateString()) : null;
        if (! $start || $months < 1) {
            return ['rows' => [], 'start_date' => null, 'end_date' => null, 'total_days' => null];
        }

        $cost = $this->toCents($book->depreciable_cost);
        $residual = $this->toCents($book->residual_value);
        $amount = max(0, $cost - $residual);
        $end = ($proration === 'FULL_MONTH'
            ? $start->startOfMonth()->addMonthsNoOverflow($months)->subDay()
            : $start->addMonthsNoOverflow($months)->subDay())->startOfDay();
        $rows = $proration === 'DAILY'
            ? $this->dailyRows($start, $end, $amount, $residual)
            : $this->fullMonthRows($start, $months, $amount, $residual);

        return [
            'rows' => $rows,
            'start_date' => $start,
            'end_date' => $end,
            'total_days' => $proration === 'DAILY' ? ((int) $start->diffInDays($end)) + 1 : null,
            'depreciable_amount' => $this->fromCents($amount),
            'residual_value' => $this->fromCents($residual),
        ];
    }

    /**
     * Returns the immutable cumulative target that a depreciation run needs.
     * The caller owns persistence and decides how to present any catch-up.
     */
    public function calculateThrough(AssetDepreciationBook $book, string $proration, string|DateTimeInterface $runThroughDate, mixed $postedAccumulatedDepreciation = '0.00'): array
    {
        $schedule = $this->calculate($book, $proration);
        if (! $schedule['start_date']) {
            return $this->throughResult($runThroughDate, 0, 0, 0, 0);
        }

        $start = $schedule['start_date'];
        $end = $schedule['end_date'];
        $runThrough = $runThroughDate instanceof DateTimeInterface
            ? CarbonImmutable::instance($runThroughDate)->startOfDay()
            : CarbonImmutable::parse($runThroughDate)->startOfDay();
        $effectiveThrough = $runThrough->greaterThan($end) ? $end : $runThrough;
        $amount = $this->toCents($schedule['depreciable_amount']);
        $target = 0;

        if ($effectiveThrough->greaterThanOrEqualTo($start)) {
            if ($proration === 'FULL_MONTH') {
                foreach ($schedule['rows'] as $row) {
                    if ($row['period_start']->greaterThan($effectiveThrough)) {
                        break;
                    }

                    $target = $this->toCents($row['accumulated_depreciation']);
                }
            } else {
                $elapsedDays = ((int) $start->diffInDays($effectiveThrough)) + 1;
                $target = intdiv($elapsedDays * $amount, $schedule['total_days']);
            }
        }

        return $this->throughResult($runThrough, $target, $this->toCents($postedAccumulatedDepreciation), $amount, $this->toCents($schedule['residual_value']));
    }

    private function fullMonthRows(CarbonImmutable $start, int $months, int $amount, int $residual): array
    {
        $rows = [];
        $previous = 0;
        for ($index = 0; $index < $months; $index++) {
            $periodStart = $start->startOfMonth()->addMonthsNoOverflow($index);
            $periodEnd = $periodStart->endOfMonth();
            $target = intdiv(($index + 1) * $amount, $months);
            $rows[] = $this->row($index + 1, $periodStart, $periodEnd, $target - $previous, $target, $amount, $residual);
            $previous = $target;
        }

        return $rows;
    }

    private function dailyRows(CarbonImmutable $start, CarbonImmutable $end, int $amount, int $residual): array
    {
        $totalDays = ((int) $start->diffInDays($end)) + 1;
        $cursor = $start->startOfDay();
        $previous = 0;
        $elapsedDays = 0;
        $rows = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $monthEnd = $cursor->endOfMonth()->startOfDay();
            $periodEnd = $monthEnd->greaterThan($end) ? $end : $monthEnd;
            $days = ((int) $cursor->diffInDays($periodEnd)) + 1;
            $elapsedDays += $days;
            $target = intdiv($elapsedDays * $amount, $totalDays);
            $rows[] = $this->row(count($rows) + 1, $cursor, $periodEnd, $target - $previous, $target, $amount, $residual, $days);
            $previous = $target;
            $cursor = $periodEnd->addDay();
        }

        return $rows;
    }

    private function row(int $number, CarbonImmutable $start, CarbonImmutable $end, int $depreciation, int $accumulated, int $amount, int $residual, ?int $days = null): array
    {
        return [
            'number' => $number,
            'period_start' => $start,
            'period_end' => $end,
            'days' => $days,
            'depreciation' => $this->fromCents($depreciation),
            'accumulated_depreciation' => $this->fromCents($accumulated),
            'closing_value' => $this->fromCents($residual + max(0, $amount - $accumulated)),
        ];
    }

    private function throughResult(string|DateTimeInterface $runThroughDate, int $target, int $posted, int $amount, int $residual): array
    {
        $due = max(0, $target - $posted);

        return [
            'run_through_date' => $runThroughDate instanceof DateTimeInterface
                ? CarbonImmutable::instance($runThroughDate)->startOfDay()
                : CarbonImmutable::parse($runThroughDate)->startOfDay(),
            'target_accumulated_depreciation' => $this->fromCents($target),
            'posted_accumulated_depreciation' => $this->fromCents($posted),
            'depreciation_due' => $this->fromCents($due),
            'catch_up_depreciation' => $this->fromCents($due),
            'over_depreciated_amount' => $this->fromCents(max(0, $posted - $target)),
            'closing_value' => $this->fromCents($residual + max(0, $amount - $target)),
        ];
    }

    private function toCents(mixed $amount): int
    {
        $value = str_replace(',', '', trim((string) $amount));
        if (! preg_match('/^(\d+)(?:\.(\d*))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Invalid depreciation amount.');
        }

        $fraction = str_pad($matches[2] ?? '', 3, '0');
        $cents = ((int) $matches[1] * 100) + (int) substr($fraction, 0, 2);

        return $cents + ((int) $fraction[2] >= 5 ? 1 : 0);
    }

    private function fromCents(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }
}
