<?php

namespace App\Modules\Finance\Support;

use DomainException;

final class SettlementState
{
    public static function approve(string $currentStatus): string
    {
        if ($currentStatus !== 'DRAFT') {
            throw new DomainException('อนุมัติได้เฉพาะเอกสารสถานะ Draft');
        }

        return 'APPROVED';
    }

    public static function void(string $currentStatus): string
    {
        if (! in_array($currentStatus, ['DRAFT', 'APPROVED'], true)) {
            throw new DomainException('ยกเลิกได้เฉพาะเอกสาร Draft หรือ Approved ที่ยังไม่ลงบัญชี');
        }

        return 'VOID';
    }

    public static function post(string $currentStatus): string
    {
        if ($currentStatus !== 'APPROVED') {
            throw new DomainException('ลงบัญชีได้เฉพาะเอกสารสถานะ Approved');
        }

        return 'POSTED';
    }

    public static function reverse(string $currentStatus): string
    {
        if ($currentStatus !== 'POSTED') {
            throw new DomainException('กลับรายการได้เฉพาะเอกสารที่ลงบัญชีแล้ว');
        }

        return 'VOID';
    }
}
