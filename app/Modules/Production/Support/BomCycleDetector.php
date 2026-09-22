<?php

namespace App\Modules\Production\Support;

use Illuminate\Validation\ValidationException;

final class BomCycleDetector
{
    /** @param list<int> $components @param array<int,list<int>> $edges */
    public static function assertAcyclic(int $finishedItemId, array $components, array $edges): void
    {
        foreach ($components as $component) {
            $queue = [(int) $component];
            $visited = [];
            while ($queue !== []) {
                $item = array_pop($queue);
                if ($item === $finishedItemId) {
                    throw ValidationException::withMessages(['lines' => 'BOM นี้ทำให้เกิดวงจรการผลิต กรุณาตรวจโครงสร้างวัตถุดิบ']);
                }
                if (isset($visited[$item])) {
                    continue;
                }
                $visited[$item] = true;
                array_push($queue, ...($edges[$item] ?? []));
            }
        }
    }
}
