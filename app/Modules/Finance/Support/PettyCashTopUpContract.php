<?php

namespace App\Modules\Finance\Support;

use App\Modules\Finance\Models\BankAccount;
use InvalidArgumentException;

final class PettyCashTopUpContract
{
    public static function state(string $status, string $transition): string
    {
        return match ([strtoupper($status), strtoupper($transition)]) {
            ['DRAFT', 'SUBMIT'] => 'SUBMITTED', ['SUBMITTED', 'APPROVE'] => 'APPROVED', ['APPROVED', 'POST'] => 'POSTED', ['POSTED', 'REVERSE'] => 'REVERSED',
            ['SUBMITTED', 'VOID'], ['APPROVED', 'VOID'] => 'VOID',
            default => throw new InvalidArgumentException("ไม่สามารถ {$transition} เอกสารสถานะ {$status}"),
        };
    }

    public static function assertSourceBankAccount(BankAccount $account, int|string $warehouseId): void
    {
        if (! $account->is_active || $account->type !== 'BANK' || (string) $account->warehouse_id !== (string) $warehouseId || ! $account->account?->is_active || ! $account->account?->is_postable) {
            throw new InvalidArgumentException('บัญชีต้นทางต้องเป็น BANK ที่เปิดใช้งาน ลงรายการได้ และอยู่ในคลังเดียวกัน');
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
