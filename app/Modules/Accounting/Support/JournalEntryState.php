<?php

namespace App\Modules\Accounting\Support;

use DomainException;

final class JournalEntryState
{
    public static function validate(string $currentStatus): string
    {
        return self::transition($currentStatus, 'DRAFT', 'VALIDATED', 'ส่งอนุมัติได้เฉพาะรายการสถานะ Draft');
    }

    public static function post(string $currentStatus): string
    {
        return self::transition($currentStatus, 'VALIDATED', 'POSTED', 'อนุมัติได้เฉพาะรายการที่รออนุมัติ');
    }

    public static function reverse(string $currentStatus): string
    {
        return self::transition($currentStatus, 'POSTED', 'REVERSED', 'กลับรายการได้เฉพาะรายการที่ลงบัญชีแล้ว');
    }

    private static function transition(string $currentStatus, string $from, string $to, string $message): string
    {
        if ($currentStatus !== $from) {
            throw new DomainException($message);
        }

        return $to;
    }
}
