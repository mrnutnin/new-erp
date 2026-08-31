<?php

namespace App\Modules\Pos\Support;

use DomainException;

final class SalesQuotationState
{
    public static function canCreateFromRfq(string $rfqStatus): bool
    {
        return $rfqStatus === 'APPROVED';
    }

    public static function assertCreateFromRfq(string $rfqStatus): void
    {
        if (! self::canCreateFromRfq($rfqStatus)) {
            throw new DomainException('สร้างใบเสนอราคาได้เฉพาะ RFQ ที่อนุมัติแล้ว');
        }
    }

    public static function editable(string $status): bool
    {
        return $status === 'DRAFT';
    }

    public static function assertEditable(string $status): void
    {
        if (! self::editable($status)) {
            throw new DomainException('แก้ไขได้เฉพาะใบเสนอราคาสถานะร่างเท่านั้น');
        }
    }

    public static function send(string $status): string
    {
        if ($status !== 'DRAFT') {
            throw new DomainException('ส่งใบเสนอราคาได้เฉพาะเอกสารสถานะร่างเท่านั้น');
        }

        return 'SENT';
    }

    public static function accept(string $status): string
    {
        if ($status !== 'SENT') {
            throw new DomainException('ตอบรับได้เฉพาะใบเสนอราคาที่ส่งแล้วเท่านั้น');
        }

        return 'ACCEPTED';
    }

    public static function reject(string $status): string
    {
        if ($status !== 'SENT') {
            throw new DomainException('ปฏิเสธได้เฉพาะใบเสนอราคาที่ส่งแล้วเท่านั้น');
        }

        return 'REJECTED';
    }

    public static function cancel(string $status): string
    {
        if (! in_array($status, ['DRAFT', 'SENT'], true)) {
            throw new DomainException('ยกเลิกได้เฉพาะใบเสนอราคาที่ยังไม่ตอบรับเท่านั้น');
        }

        return 'CANCELLED';
    }
}
