<?php

namespace App\Modules\Accounting\Support;

class JournalBalance
{
    /** @param array<int, array{debit: mixed, credit: mixed}> $lines */
    public static function totals(array $lines): array
    {
        return array_reduce($lines, fn (array $total, array $line) => [
            'debit' => $total['debit'] + self::cents($line['debit']),
            'credit' => $total['credit'] + self::cents($line['credit']),
        ], ['debit' => 0, 'credit' => 0]);
    }

    public static function decimal(mixed $value): string
    {
        $cents = self::cents($value);

        return self::formatCents($cents);
    }

    public static function add(mixed $left, mixed $right): string
    {
        return self::formatCents(self::cents($left) + self::cents($right));
    }

    public static function subtract(mixed $left, mixed $right): string
    {
        return self::formatCents(self::cents($left) - self::cents($right));
    }

    private static function cents(mixed $value): int
    {
        $value = trim((string) $value);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $parts)) {
            throw new \InvalidArgumentException('จำนวนเงินต้องมีทศนิยมไม่เกิน 2 ตำแหน่ง');
        }

        $cents = ((int) $parts[2] * 100) + (int) str_pad($parts[3] ?? '', 2, '0');

        return ($parts[1] ?? '') === '-' ? -$cents : $cents;
    }

    private static function formatCents(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
