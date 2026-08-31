<?php

namespace App\Modules\Finance\Support;

use App\Modules\Accounting\Support\JournalBalance;
use DateTimeImmutable;
use InvalidArgumentException;

final class OpenItemBalance
{
    /** @param array<int, array{allocation_date: string, amount: mixed, reversal_date?: ?string}> $allocations */
    public static function assertAllocationFitsTimeline(mixed $original, string $date, mixed $amount, array $allocations): void
    {
        self::date($date);
        $events = [];
        $activeCents = 0;

        foreach ($allocations as $allocation) {
            $allocationDate = self::date($allocation['allocation_date'])->format('Y-m-d');
            $reversalDate = isset($allocation['reversal_date']) ? self::date($allocation['reversal_date'])->format('Y-m-d') : null;
            $cents = self::moneyCents($allocation['amount']);

            if ($allocationDate <= $date && ($reversalDate === null || $reversalDate > $date)) {
                $activeCents += $cents;
            }
            if ($allocationDate > $date) {
                $events[$allocationDate]['add'] = ($events[$allocationDate]['add'] ?? 0) + $cents;
            }
            if ($reversalDate !== null && $reversalDate > $date) {
                $events[$reversalDate]['subtract'] = ($events[$reversalDate]['subtract'] ?? 0) + $cents;
            }
        }

        ksort($events);
        $peakCents = $activeCents;
        foreach ($events as $event) {
            $activeCents -= $event['subtract'] ?? 0;
            $activeCents += $event['add'] ?? 0;
            $peakCents = max($peakCents, $activeCents);
        }

        $amountCents = self::moneyCents($amount);
        $originalCents = self::moneyCents($original);
        if ($amountCents <= 0 || $originalCents <= 0 || $peakCents + $amountCents > $originalCents) {
            throw new InvalidArgumentException('ยอดจัดสรรเกินยอดคงเหลือของ Open Item ในช่วงเวลาถัดไป');
        }
    }

    public static function remaining(mixed $original, mixed $allocated): string
    {
        ['debit' => $originalCents, 'credit' => $allocatedCents] = JournalBalance::totals([
            ['debit' => $original, 'credit' => $allocated],
        ]);

        if ($originalCents <= 0 || $allocatedCents < 0 || $allocatedCents > $originalCents) {
            throw new InvalidArgumentException('ยอดจัดสรรต้องอยู่ระหว่างศูนย์และยอดตั้งต้น');
        }

        return JournalBalance::subtract($original, $allocated);
    }

    public static function status(mixed $original, mixed $allocated): string
    {
        $remaining = self::remaining($original, $allocated);

        if (JournalBalance::decimal($allocated) === '0.00') {
            return 'OPEN';
        }

        return $remaining === '0.00' ? 'CLOSED' : 'PARTIAL';
    }

    public static function signed(string $balanceSide, mixed $amount): string
    {
        return match (strtoupper($balanceSide)) {
            'DEBIT' => JournalBalance::decimal($amount),
            'CREDIT' => JournalBalance::subtract('0.00', $amount),
            default => throw new InvalidArgumentException('ฝั่งยอดต้องเป็น DEBIT หรือ CREDIT'),
        };
    }

    public static function agingBucket(string $dueDate, string $asOfDate): string
    {
        $due = self::date($dueDate);
        $asOf = self::date($asOfDate);

        if ($due >= $asOf) {
            return 'CURRENT';
        }

        return match (true) {
            $due->diff($asOf)->days <= 30 => '1_30',
            $due->diff($asOf)->days <= 60 => '31_60',
            $due->diff($asOf)->days <= 90 => '61_90',
            default => 'OVER_90',
        };
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('วันที่ต้องอยู่ในรูปแบบ Y-m-d');
        }

        return $date;
    }

    private static function moneyCents(mixed $value): int
    {
        return JournalBalance::totals([['debit' => $value, 'credit' => '0.00']])['debit'];
    }
}
