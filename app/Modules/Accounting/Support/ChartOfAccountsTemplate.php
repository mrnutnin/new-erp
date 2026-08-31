<?php

namespace App\Modules\Accounting\Support;

use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

final class ChartOfAccountsTemplate
{
    public const TYPE = 'CHART_OF_ACCOUNTS';

    public const VERSION = 'COA-1.0';

    public const HEADERS = ['row_key', 'code', 'name', 'account_type', 'parent_code', 'account_class', 'control_type', 'reporting_profile', 'is_active'];

    public static function sheets(): array
    {
        $examples = [
            ['ASSET-1', '10000', 'สินทรัพย์', 'ASSET', '', 'SUMMARY', '', '', 'TRUE'],
            ['ASSET-2', '11000', 'สินทรัพย์หมุนเวียน', 'ASSET', '10000', 'SUMMARY', '', '', 'TRUE'],
            ['ASSET-3', '11100', 'เงินสดและเงินฝากธนาคาร', 'ASSET', '11000', 'SUMMARY', '', '', 'TRUE'],
            ['ASSET-4', '11110', 'เงินฝากธนาคาร', 'ASSET', '11100', 'SUMMARY', '', '', 'TRUE'],
            ['ASSET-5', '11111', 'บัญชีเงินฝากธนาคาร', 'ASSET', '11110', 'CONTROL', 'BANK', '', 'TRUE'],
        ];

        return [
            ['title' => 'Accounts', 'headings' => self::HEADERS, 'rows' => [], 'formats' => ['A' => NumberFormat::FORMAT_TEXT, 'B' => NumberFormat::FORMAT_TEXT, 'E' => NumberFormat::FORMAT_TEXT]],
            ['title' => 'Examples', 'headings' => self::HEADERS, 'rows' => $examples, 'formats' => ['A' => NumberFormat::FORMAT_TEXT, 'B' => NumberFormat::FORMAT_TEXT, 'E' => NumberFormat::FORMAT_TEXT]],
            ['title' => 'Data Dictionary', 'headings' => ['column', 'required', 'description', 'allowed/example'], 'rows' => [
                ['row_key', 'Yes', 'รหัสอ้างอิงแถวที่ไม่ซ้ำ ใช้กัน import ซ้ำ', 'เช่น ASSET-001'],
                ['code', 'Yes', 'รหัสบัญชี เก็บเป็นข้อความ', 'สูงสุด 50 ตัวอักษร'],
                ['name', 'Yes', 'ชื่อบัญชี', 'สูงสุด 255 ตัวอักษร'],
                ['account_type', 'Yes', 'หมวดบัญชี', 'ASSET, LIABILITY, EQUITY, REVENUE, EXPENSE'],
                ['parent_code', 'No', 'รหัสบัญชีแม่ ต้องอยู่ก่อนบัญชีย่อยและเป็น SUMMARY', 'ว่างสำหรับระดับ 1'],
                ['account_class', 'Yes', 'ประเภทบัญชี', 'SUMMARY, SUBACCOUNT, CONTROL'],
                ['control_type', 'Control only', 'ประเภทบัญชีคุม', 'AR, AP, INVENTORY, CASH, BANK, CREDIT_CARD, CHEQUE, FIXED_ASSET, INPUT_VAT, OUTPUT_VAT, WITHHOLDING_TAX, WIP'],
                ['reporting_profile', 'No', 'จำกัด profile รายงาน', 'PAE, NPAE หรือว่างเพื่อใช้ทั้งสอง'],
                ['is_active', 'Yes', 'สถานะเริ่มต้น', 'TRUE หรือ FALSE'],
            ]],
            ['title' => '_meta', 'headings' => ['key', 'value'], 'rows' => [['template_type', self::TYPE], ['template_version', self::VERSION]]],
        ];
    }
}
