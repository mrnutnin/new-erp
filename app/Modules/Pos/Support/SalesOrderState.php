<?php

namespace App\Modules\Pos\Support;

use DomainException;

final class SalesOrderState
{
    public static function confirm(string $status): string
    {
        if ($status !== 'DRAFT') {
            throw new DomainException('ยืนยันได้เฉพาะใบสั่งขายสถานะร่างเท่านั้น');
        }

        return 'CONFIRMED';
    }

    public static function cancel(string $status): string
    {
        if (! in_array($status, ['DRAFT', 'CONFIRMED'], true)) {
            throw new DomainException('ยกเลิกได้เฉพาะใบสั่งขายที่ยังไม่ดำเนินการเท่านั้น');
        }

        return 'CANCELLED';
    }
}
