<?php

namespace App\Modules\Wms\Support;

use InvalidArgumentException;

final class TransferState
{
    private const TRANSITIONS = [
        'DRAFT' => ['DISPATCHED', 'VOID'],
        'DISPATCHED' => ['ACCEPTED', 'PARTIALLY_ACCEPTED', 'REJECTED'],
        'PARTIALLY_ACCEPTED' => ['ACCEPTED', 'REJECTED'],
        'REJECTED' => ['VOID'],
    ];

    public static function assert(string $from, string $to): void
    {
        if (! in_array($to, self::TRANSITIONS[strtoupper($from)] ?? [], true)) {
            throw new InvalidArgumentException("ไม่สามารถเปลี่ยน Transfer จาก {$from} เป็น {$to} ได้");
        }
    }
}
