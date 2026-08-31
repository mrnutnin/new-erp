<?php

namespace App\Modules\Finance\Support;

use App\Modules\Accounting\Support\JournalBalance;
use InvalidArgumentException;

final class AdvanceDepositContract
{
    public static function assertPartyDirection(string $partyType, string $direction): void
    {
        $valid = match (strtoupper($partyType)) {
            'CUSTOMER' => strtoupper($direction) === 'RECEIPT',
            'SUPPLIER' => strtoupper($direction) === 'PAYMENT',
            default => false,
        };

        if (! $valid) {
            throw new InvalidArgumentException('Customer ใช้เงินรับล่วงหน้า และ Supplier ใช้เงินจ่ายล่วงหน้าเท่านั้น');
        }
    }

    public static function state(string $status, string $transition): string
    {
        $status = strtoupper($status);
        $transition = strtoupper($transition);

        return match ([$status, $transition]) {
            ['DRAFT', 'POST'] => 'POSTED',
            ['POSTED', 'APPLY'] => 'PARTIAL',
            ['PARTIAL', 'APPLY'] => 'PARTIAL',
            ['PARTIAL', 'CLOSE'] => 'APPLIED',
            ['APPLIED', 'REVERSE'], ['POSTED', 'REVERSE'], ['PARTIAL', 'REVERSE'] => 'REVERSED',
            ['DRAFT', 'VOID'] => 'VOID',
            default => throw new InvalidArgumentException("ไม่สามารถ {$transition} เอกสารสถานะ {$status}"),
        };
    }

    public static function assertApplicationAmount(mixed $original, mixed $applied, mixed $amount): void
    {
        $originalCents = self::cents($original);
        $appliedCents = self::cents($applied);
        $amountCents = self::cents($amount);

        if ($originalCents <= 0 || $amountCents <= 0 || $appliedCents < 0 || $appliedCents + $amountCents > $originalCents) {
            throw new InvalidArgumentException('ยอดนำมัดจำไปตัดต้องมากกว่าศูนย์และไม่เกินยอดคงเหลือ');
        }
    }

    /**
     * Advance applications are deliberately kept outside finance_allocations.
     * Keep this boundary strict so a customer advance can only settle AR and a
     * supplier prepayment can only settle AP in the same warehouse/party.
     */
    public static function assertApplicationScope(
        string|int $advanceWarehouse,
        string $advancePartyType,
        string|int $advanceParty,
        string $openItemLedger,
        string $openItemPartyType,
        string|int $openItemParty,
        string|int $openItemWarehouse,
        string $openItemBalanceSide,
    ): void {
        $partyType = strtoupper($advancePartyType);
        $expectedLedger = $partyType === 'CUSTOMER' ? 'AR' : ($partyType === 'SUPPLIER' ? 'AP' : null);
        $expectedSide = $partyType === 'CUSTOMER' ? 'DEBIT' : ($partyType === 'SUPPLIER' ? 'CREDIT' : null);

        if ($expectedLedger === null
            || (string) $advanceWarehouse !== (string) $openItemWarehouse
            || (string) $advanceParty !== (string) $openItemParty
            || strtoupper($openItemPartyType) !== $partyType
            || strtoupper($openItemLedger) !== $expectedLedger
            || strtoupper($openItemBalanceSide) !== $expectedSide) {
            throw new InvalidArgumentException('Advance/Deposit และ Open Item ต้องอยู่คลัง คู่ค้า และ ledger ที่ตรงกัน');
        }
    }

    public static function status(mixed $original, mixed $applied): string
    {
        $originalCents = self::cents($original);
        $appliedCents = self::cents($applied);

        if ($appliedCents <= 0) {
            return 'POSTED';
        }

        return $appliedCents >= $originalCents ? 'APPLIED' : 'PARTIAL';
    }

    private static function cents(mixed $amount): int
    {
        return JournalBalance::totals([['debit' => $amount, 'credit' => '0.00']])['debit'];
    }
}
