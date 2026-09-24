<?php

namespace App\Modules\Production\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ProductionDayTimeline
{
    public static function forDay(Collection $orders, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        $start = $day->getTimestamp();
        $end = $day->addDay()->getTimestamp();
        $timed = [];
        $unscheduled = [];

        foreach ($orders as $order) {
            $from = $order->planned_start_at?->getTimestamp();
            $until = $order->planned_finish_at?->getTimestamp();
            if ($from !== null && $until !== null && $until > $from && ($from >= $end || $until <= $start)) continue;
            if ($from === null || $until === null || $until <= $from) {
                $unscheduled[] = $order;
                continue;
            }
            $timed[] = [
                'order' => $order,
                'from' => $from,
                'until' => $until,
                'left' => max(0, $from - $start) / ($end - $start) * 100,
                'width' => (min($end, $until) - max($start, $from)) / ($end - $start) * 100,
                'overlaps' => false,
            ];
        }
        usort($timed, fn ($a, $b) => ($a['from'] <=> $b['from']) ?: ($a['order']->id <=> $b['order']->id));
        // ponytail: O(n²) on at most 200 orders; use an interval index if the board limit grows.
        foreach ($timed as $i => &$row) {
            foreach ($timed as $j => $other) {
                if ($i !== $j && $row['order']->status !== 'CANCELLED' && $other['order']->status !== 'CANCELLED' && $row['from'] < $other['until'] && $other['from'] < $row['until']) {
                    $row['overlaps'] = true;
                    break;
                }
            }
        }
        unset($row);

        return ['timed' => $timed, 'unscheduled' => $unscheduled, 'overlapCount' => count(array_filter($timed, fn ($row) => $row['overlaps']))];
    }
}
