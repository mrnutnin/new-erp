<?php

namespace App\Modules\Finance\Support;

use App\Modules\Finance\Models\BankAccount;
use InvalidArgumentException;

final class PettyCashVoucherContract
{
    public const STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED', 'VOID'];

    public static function state(string $status, string $transition): string
    {
        return match ([strtoupper($status), strtoupper($transition)]) {
            ['DRAFT', 'SUBMIT'] => 'SUBMITTED',
            ['SUBMITTED', 'VOID'], ['APPROVED', 'VOID'] => 'VOID',
            ['SUBMITTED', 'APPROVE'] => 'APPROVED',
            ['APPROVED', 'POST'] => 'POSTED',
            ['POSTED', 'REVERSE'] => 'REVERSED',
            default => throw new InvalidArgumentException("ไม่สามารถ {$transition} เอกสารสถานะ {$status}"),
        };
    }

    public static function assertCashFundBankAccount(BankAccount $bankAccount, int|string $warehouseId): void
    {
        if (! $bankAccount->is_active || $bankAccount->type !== 'CASH' || (string) $bankAccount->warehouse_id !== (string) $warehouseId) {
            throw new InvalidArgumentException('กองเงินสดย่อยต้องใช้บัญชีเงินสดที่เปิดใช้งานและอยู่ในคลังเดียวกัน');
        }
    }

    public static function assertMutable(string $status): void
    {
        if (in_array(strtoupper($status), ['POSTED', 'REVERSED', 'VOID'], true)) {
            throw new InvalidArgumentException('เอกสารที่ Post, Reverse หรือ Void แล้วห้ามแก้ไข');
        }
    }

    public static function assertPostingMetadata(string $idempotencyKey, int|string|null $journalEntryId): void
    {
        if (strlen($idempotencyKey) !== 64 || $journalEntryId === null) {
            throw new InvalidArgumentException('การ Post ต้องมี idempotency key และ Journal Entry');
        }
    }
}
