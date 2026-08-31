<?php

namespace App\Modules\Accounting\Support;

use DomainException;

final class FiscalPeriodState
{
    public static function softClose(string $currentStatus): string
    {
        if ($currentStatus !== 'OPEN') {
            throw new DomainException('Soft close ได้เฉพาะงวดที่เปิดอยู่');
        }

        return 'SOFT_CLOSE';
    }

    public static function reopen(string $currentStatus): string
    {
        if ($currentStatus === 'OPEN') {
            throw new DomainException('งวดบัญชีนี้เปิดอยู่แล้ว');
        }

        return 'OPEN';
    }

    public static function lock(string $currentStatus): string
    {
        if ($currentStatus !== 'SOFT_CLOSE') {
            throw new DomainException('Lock ได้เฉพาะงวดที่ Soft close แล้ว');
        }

        return 'LOCKED';
    }
}
