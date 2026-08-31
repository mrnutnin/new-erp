<?php

namespace App\Modules\Pos\Support;

use InvalidArgumentException;

final class SalesDocumentPrecision
{
    private function __construct() {}

    /** Prevent silent truncation while GL/Open Item storage remains 2 decimals. */
    public static function assertStorageCompatible(int $decimalPlaces): void
    {
        if ($decimalPlaces > 2) {
            throw new InvalidArgumentException('เอกสารขายรองรับทศนิยมสูงสุด 2 ตำแหน่งในขณะนี้ กรุณาตั้งค่า Global Setting เป็น 0–2 ตำแหน่งก่อนบันทึก จนกว่าจะขยาย precision ของ GL และ Open Item พร้อมกัน');
        }
    }
}
