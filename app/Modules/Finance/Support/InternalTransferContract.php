<?php

namespace App\Modules\Finance\Support;

use InvalidArgumentException;

final class InternalTransferContract
{
    public static function transition(string $status, string $action): string
    {
        return match ([strtoupper($status), strtoupper($action)]) {
            ['DRAFT', 'SUBMIT'] => 'SUBMITTED',
            ['SUBMITTED', 'APPROVE'] => 'APPROVED',
            ['SUBMITTED', 'VOID'], ['APPROVED', 'VOID'] => 'VOID',
            ['APPROVED', 'POST'] => 'POSTED',
            ['POSTED', 'REVERSE'] => 'REVERSED',
            default => throw new InvalidArgumentException("ไม่สามารถ {$action} เอกสารสถานะ {$status}"),
        };
    }
}
