<?php

namespace Tests\Unit;

use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Support\ProductionDayTimeline;
use Tests\TestCase;

final class ProductionDayTimelineTest extends TestCase
{
    public function test_daily_hours_clip_overnight_jobs_and_mark_only_real_overlaps(): void
    {
        $orders = collect([
            $this->order(1, '2026-09-23 22:00', '2026-09-24 09:00'),
            $this->order(2, '2026-09-24 08:30', '2026-09-24 10:00'),
            $this->order(3, '2026-09-24 10:00', '2026-09-24 12:00'),
            $this->order(4, '2026-09-24 08:00', '2026-09-24 09:00', 'CANCELLED'),
            $this->order(5, null, null),
            $this->order(6, '2026-09-25 08:00', '2026-09-25 10:00'),
        ]);
        $day = ProductionDayTimeline::forDay($orders, '2026-09-24');
        self::assertSame([1, 4, 2, 3], array_map(fn ($row) => $row['order']->id, $day['timed']));
        self::assertSame(2, $day['overlapCount']);
        self::assertTrue($day['timed'][0]['overlaps']);
        self::assertFalse($day['timed'][1]['overlaps']);
        self::assertTrue($day['timed'][2]['overlaps']);
        self::assertFalse($day['timed'][3]['overlaps']);
        self::assertEquals(0, $day['timed'][0]['left']);
        self::assertEqualsWithDelta(37.5, $day['timed'][0]['width'], .01);
        self::assertEqualsWithDelta(35.4167, $day['timed'][2]['left'], .01);
        self::assertSame([5], array_map(fn ($order) => $order->id, $day['unscheduled']));
    }

    private function order(int $id, ?string $start, ?string $finish, string $status = 'RELEASED'): ProductionOrder
    {
        return (new ProductionOrder())->forceFill(['id' => $id, 'status' => $status, 'planned_start_at' => $start, 'planned_finish_at' => $finish]);
    }
}
