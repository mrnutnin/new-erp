<?php

namespace App\Modules\Finance\Support;

use InvalidArgumentException;

final class EmployeeAdvanceContract
{
    public const STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'PARTIAL', 'CLEARED', 'VOID', 'REVERSED'];

    public static function state(string $status, string $transition): string
    {
        return match ([strtoupper($status), strtoupper($transition)]) {
            ['DRAFT', 'SUBMIT'] => 'SUBMITTED',
            ['SUBMITTED', 'APPROVE'] => 'APPROVED',
            ['SUBMITTED', 'VOID'], ['APPROVED', 'VOID'] => 'VOID',
            ['APPROVED', 'POST'] => 'POSTED',
            ['POSTED', 'REVERSE'] => 'REVERSED',
            default => throw new InvalidArgumentException("ไม่สามารถ {$transition} เอกสารสถานะ {$status}"),
        };
    }

    public static function assertMutable(string $status): void
    {
        if (in_array(strtoupper($status), ['POSTED', 'PARTIAL', 'CLEARED', 'REVERSED', 'VOID'], true)) {
            throw new InvalidArgumentException('เอกสารที่ดำเนินการแล้วห้ามแก้ไข');
        }
    }

    public static function assertPostingMetadata(string $idempotencyKey, int|string|null $journalEntryId): void
    {
        if (strlen($idempotencyKey) !== 64 || $journalEntryId === null) {
            throw new InvalidArgumentException('การ Post ต้องมี idempotency key และ Journal Entry');
        }
    }
}
