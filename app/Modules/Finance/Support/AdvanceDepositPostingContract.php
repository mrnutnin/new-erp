<?php

namespace App\Modules\Finance\Support;

use App\Modules\Accounting\Support\JournalBalance;
use InvalidArgumentException;

/**
 * Accounting line contract for Advance/Deposit.
 *
 * This is deliberately a pure line builder.  Settlement/Application posting
 * must call it inside one transaction after resolving mappings and locking the
 * source rows; this class must not create a Journal by itself.
 */
final class AdvanceDepositPostingContract
{
    public static function event(string $partyType, string $operation = 'POST'): string
    {
        $partyType = strtoupper($partyType);
        $operation = strtoupper($operation);

        return match ([$partyType, $operation]) {
            ['CUSTOMER', 'POST'] => 'customer_advance',
            ['SUPPLIER', 'POST'] => 'supplier_payment',
            ['CUSTOMER', 'APPLY'] => 'customer_advance',
            ['SUPPLIER', 'APPLY'] => 'supplier_payment',
            default => throw new InvalidArgumentException('ไม่รองรับทิศทาง Advance/Deposit นี้'),
        };
    }

    /**
     * Receipt: Dr Bank / Cr Customer Advance (liability).
     * Payment: Dr Supplier Advance (asset) / Cr Bank.
     */
    public static function sourceLines(string $partyType, int $bankAccountId, int $advanceAccountId, mixed $amount, string $description): array
    {
        $amount = JournalBalance::decimal($amount);
        if (JournalBalance::totals([['debit' => $amount, 'credit' => '0.00']])['debit'] <= 0) {
            throw new InvalidArgumentException('ยอด Advance/Deposit ต้องมากกว่าศูนย์');
        }

        $partyType = strtoupper($partyType);

        return match ($partyType) {
            'CUSTOMER' => [
                self::line($bankAccountId, 'BANK', (string) $bankAccountId, $description, $amount, '0.00'),
                self::line($advanceAccountId, null, null, $description, '0.00', $amount),
            ],
            'SUPPLIER' => [
                self::line($advanceAccountId, null, null, $description, $amount, '0.00'),
                self::line($bankAccountId, 'BANK', (string) $bankAccountId, $description, '0.00', $amount),
            ],
            default => throw new InvalidArgumentException('Advance/Deposit ต้องเป็น Customer หรือ Supplier'),
        };
    }

    /**
     * Application: Customer advance Dr / AR Cr; Supplier AP Dr / advance Cr.
     * The AR/AP line is intentionally supplied by the caller because it must
     * remain the actual Open Item control account and party subledger.
     */
    public static function applicationLines(string $partyType, int $advanceAccountId, int $openItemAccountId, int|string $partyId, mixed $amount, string $description): array
    {
        $amount = JournalBalance::decimal($amount);
        if (JournalBalance::totals([['debit' => $amount, 'credit' => '0.00']])['debit'] <= 0) {
            throw new InvalidArgumentException('ยอด Application ต้องมากกว่าศูนย์');
        }

        return match (strtoupper($partyType)) {
            'CUSTOMER' => [
                self::line($advanceAccountId, null, null, $description, $amount, '0.00'),
                self::line($openItemAccountId, 'CUSTOMER', (string) $partyId, $description, '0.00', $amount),
            ],
            'SUPPLIER' => [
                self::line($openItemAccountId, 'SUPPLIER', (string) $partyId, $description, $amount, '0.00'),
                self::line($advanceAccountId, null, null, $description, '0.00', $amount),
            ],
            default => throw new InvalidArgumentException('Advance/Deposit ต้องเป็น Customer หรือ Supplier'),
        };
    }

    public static function reverseLines(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            ...$line,
            'debit' => $line['credit'],
            'credit' => $line['debit'],
        ], $lines);
    }

    private static function line(int $accountId, ?string $subledgerType, ?string $subledgerId, string $description, string $debit, string $credit): array
    {
        return [
            'account_id' => $accountId,
            'subledger_type' => $subledgerType,
            'subledger_id' => $subledgerId,
            'description' => $description,
            'debit' => $debit,
            'credit' => $credit,
        ];
    }
}
