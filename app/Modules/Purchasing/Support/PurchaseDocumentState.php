<?php

namespace App\Modules\Purchasing\Support;

use DomainException;

final class PurchaseDocumentState
{
    public static function approve(string $status): string
    {
        if ($status !== 'DRAFT') {
            throw new DomainException('อนุมัติได้เฉพาะเอกสาร Draft');
        }

        return 'APPROVED';
    }

    public static function void(string $status): string
    {
        if (! in_array($status, ['DRAFT', 'APPROVED'], true)) {
            throw new DomainException('ยกเลิกได้เฉพาะเอกสาร Draft หรือ Approved ที่ยังไม่ Post');
        }

        return 'VOID';
    }

    public static function post(string $status): string
    {
        if ($status !== 'APPROVED') {
            throw new DomainException('Post ได้เฉพาะเอกสาร Approved');
        }

        return 'POSTED';
    }
}
