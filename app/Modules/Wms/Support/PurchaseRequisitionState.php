<?php

namespace App\Modules\Wms\Support;

use DomainException;

final class PurchaseRequisitionState
{
    public static function submit(string $status): string
    {
        if (! in_array($status, ['DRAFT', 'REJECTED'], true)) {
            throw new DomainException('ส่งอนุมัติได้เฉพาะ Draft หรือรายการที่ถูกตีกลับ');
        }

        return 'SUBMITTED';
    }

    public static function approve(string $status): string
    {
        if ($status !== 'SUBMITTED') {
            throw new DomainException('อนุมัติได้เฉพาะใบขอซื้อที่ส่งอนุมัติแล้ว');
        }

        return 'APPROVED';
    }

    public static function reject(string $status): string
    {
        if ($status !== 'SUBMITTED') {
            throw new DomainException('ตีกลับได้เฉพาะใบขอซื้อที่ส่งอนุมัติแล้ว');
        }

        return 'REJECTED';
    }

    public static function void(string $status): string
    {
        if (! in_array($status, ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED'], true)) {
            throw new DomainException('ยกเลิกได้เฉพาะใบขอซื้อที่ยังไม่ถูกยกเลิก');
        }

        return 'VOID';
    }
}
