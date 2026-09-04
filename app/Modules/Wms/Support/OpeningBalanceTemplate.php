<?php

namespace App\Modules\Wms\Support;

use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

final class OpeningBalanceTemplate
{
    public const TYPE = 'WMS_OPENING_BALANCE';
    public const VERSION = 'WMS-OB-1.1';
    public const HEADERS = [
        'row_key', 'branch_code', 'warehouse_code', 'item_code', 'uom_code', 'quantity', 'total_value',
    ];

    public static function sheets(): array
    {
        return [
            ['title' => 'Opening Balance', 'headings' => self::HEADERS, 'rows' => [], 'formats' => [
                'A' => NumberFormat::FORMAT_TEXT, 'B' => NumberFormat::FORMAT_TEXT,
                'C' => NumberFormat::FORMAT_TEXT, 'D' => NumberFormat::FORMAT_TEXT,
                'E' => NumberFormat::FORMAT_TEXT,
            ]],
            ['title' => 'Examples', 'headings' => self::HEADERS, 'rows' => [[
                'OB-001', 'HQ', 'MAIN', 'ITEM-001', 'PCS', 100, 125000,
            ]], 'formats' => ['A' => NumberFormat::FORMAT_TEXT, 'B' => NumberFormat::FORMAT_TEXT, 'C' => NumberFormat::FORMAT_TEXT, 'D' => NumberFormat::FORMAT_TEXT, 'E' => NumberFormat::FORMAT_TEXT]],
            ['title' => 'Data Dictionary', 'headings' => ['column', 'required', 'description', 'allowed/example'], 'rows' => [
                ['row_key', 'Yes', 'รหัสแถวที่ไม่ซ้ำ ใช้ป้องกันการนำเข้าซ้ำ', 'OB-001'],
                ['branch_code', 'Yes', 'รหัสสาขาที่ผู้ใช้มีสิทธิ์', 'HQ'],
                ['warehouse_code', 'Yes', 'รหัสคลังที่อยู่ในสาขา', 'MAIN'],
                ['item_code', 'Yes', 'รหัสสินค้า Stock ที่ใช้งานอยู่', 'ITEM-001'],
                ['uom_code', 'Yes', 'หน่วยฐานของสินค้า', 'PCS'],
                ['quantity', 'Yes', 'จำนวนคงเหลือ ต้องมากกว่า 0', 'ตัวเลขทศนิยมตาม Global Setting'],
                ['total_value', 'Yes', 'ต้นทุนรวม ระบบคำนวณต้นทุนต่อหน่วยให้', 'ตัวเลขทศนิยมไม่น้อยกว่า 0'],
            ]],
            ['title' => '_meta', 'headings' => ['key', 'value'], 'rows' => [['template_type', self::TYPE], ['template_version', self::VERSION]]],
        ];
    }
}
