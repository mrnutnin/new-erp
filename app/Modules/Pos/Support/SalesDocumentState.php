<?php

namespace App\Modules\Pos\Support;

use DomainException;

final class SalesDocumentState
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
            throw new DomainException('ยกเลิกได้เฉพาะเอกสารที่ยังไม่ Post');
        }

        return 'VOID';
    }

    public static function post(string $status): string
    {
        if ($status !== 'APPROVED') {
            throw new DomainException('Post ได้เฉพาะเอกสารที่อนุมัติแล้ว');
        }

        return 'POSTED';
    }
}
