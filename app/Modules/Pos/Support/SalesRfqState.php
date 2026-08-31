<?php

namespace App\Modules\Pos\Support;

use DomainException;

final class SalesRfqState
{
    public static function approve(string $status): string
    {
        if ($status !== 'WAIT') {
            throw new DomainException('อนุมัติได้เฉพาะ RFQ ที่รอพิจารณา');
        }

        return 'APPROVED';
    }

    public static function reject(string $status): string
    {
        if ($status !== 'WAIT') {
            throw new DomainException('ปฏิเสธได้เฉพาะ RFQ ที่รอพิจารณา');
        }

        return 'REJECTED';
    }

    public static function cancel(string $status): string
    {
        if ($status !== 'WAIT') {
            throw new DomainException('ยกเลิกได้เฉพาะ RFQ ที่รอพิจารณา');
        }

        return 'CANCELLED';
    }

    public static function editable(string $status): bool
    {
        return false;
    }
}
