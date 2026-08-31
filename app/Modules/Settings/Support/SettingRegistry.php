<?php

namespace App\Modules\Settings\Support;

use InvalidArgumentException;

class SettingRegistry
{
    public const DEFINITIONS = [
        'company_name' => ['name' => 'ชื่อบริษัท', 'description' => 'ชื่อบริษัทที่แสดงบนระบบและเอกสาร', 'type' => 'string', 'allowed' => null, 'default' => null, 'owner' => 'Settings', 'retroactive' => false],
        'company_address' => ['name' => 'ที่อยู่บริษัท', 'description' => 'ที่อยู่บริษัทที่แสดงบนเอกสาร', 'type' => 'string', 'allowed' => null, 'default' => null, 'owner' => 'Settings', 'retroactive' => false],
        'logo_path' => ['name' => 'โลโก้บริษัท', 'description' => 'ไฟล์โลโก้ที่แสดงบนเอกสาร PDF', 'type' => 'file', 'allowed' => null, 'default' => null, 'owner' => 'Settings', 'retroactive' => false],
        'tax_id' => ['name' => 'เลขประจำตัวผู้เสียภาษี', 'description' => 'เลข 13 หลักของบริษัท', 'type' => 'string', 'allowed' => null, 'default' => null, 'owner' => 'Settings', 'retroactive' => false],
        'locale' => ['name' => 'ภาษาเริ่มต้น', 'description' => 'ภาษาหลักของหน้าจอและรายงาน', 'type' => 'enum', 'allowed' => ['th', 'en'], 'default' => 'th', 'owner' => 'Settings', 'retroactive' => true],
        'timezone' => ['name' => 'เขตเวลา', 'description' => 'เขตเวลาสำหรับแสดงวันและเวลา', 'type' => 'timezone', 'allowed' => null, 'default' => 'Asia/Bangkok', 'owner' => 'Settings', 'retroactive' => true],
        'base_currency' => ['name' => 'สกุลเงินฐาน', 'description' => 'รหัส ISO 4217 ของสกุลเงินบริษัท', 'type' => 'string', 'allowed' => null, 'default' => 'THB', 'owner' => 'Accounting', 'retroactive' => false],
        'date_format' => ['name' => 'รูปแบบวันที่', 'description' => 'รูปแบบวันที่เริ่มต้นของหน้าจอและรายงาน', 'type' => 'enum', 'allowed' => ['d/m/Y', 'Y-m-d'], 'default' => 'd/m/Y', 'owner' => 'Settings', 'retroactive' => true],
        'business_profile' => ['name' => 'ประเภทธุรกิจ', 'description' => 'Trading สำหรับซื้อมาขายไป หรือ Manufacturing สำหรับธุรกิจที่มีการผลิต', 'type' => 'enum', 'allowed' => ['TRADING', 'MANUFACTURING'], 'default' => 'TRADING', 'owner' => 'Settings', 'retroactive' => false],
        'production_enabled' => ['name' => 'เปิดใช้งาน Production', 'description' => 'เปิดความสามารถด้าน BOM, Work Order และ WIP สำหรับบริษัทที่มีการผลิต', 'type' => 'boolean', 'allowed' => [true, false], 'default' => false, 'owner' => 'Settings', 'retroactive' => false],
        'accounting_profile' => ['name' => 'มาตรฐานรายงานบัญชี', 'description' => 'รูปแบบรายงาน PAE หรือ NPAE', 'type' => 'enum', 'allowed' => ['PAE', 'NPAE'], 'default' => null, 'owner' => 'Accounting', 'retroactive' => false],
        'inventory_costing_method' => ['name' => 'วิธีคำนวณต้นทุน', 'description' => 'AVG หรือ FIFO ใช้ร่วมกันทั้งบริษัท', 'type' => 'enum', 'allowed' => ['AVG', 'FIFO'], 'default' => null, 'owner' => 'Inventory', 'retroactive' => false],
        'allow_negative_stock' => ['name' => 'อนุญาตสต็อกติดลบ', 'description' => 'อนุญาตให้จ่ายสินค้าเกินยอดคงเหลือ', 'type' => 'boolean', 'allowed' => [true, false], 'default' => null, 'owner' => 'Inventory', 'retroactive' => false],
        'negative_stock_cost_method' => ['name' => 'ต้นทุนชั่วคราวเมื่อสต็อกติดลบ', 'description' => 'วิธีกำหนดต้นทุนก่อน recost', 'type' => 'enum', 'allowed' => ['CURRENT_AVERAGE', 'LAST_KNOWN', 'STANDARD'], 'default' => null, 'owner' => 'Inventory', 'retroactive' => false],
        'fiscal_year_start_month' => ['name' => 'เดือนเริ่มปีบัญชี', 'description' => 'เลขเดือน 1 ถึง 12', 'type' => 'integer', 'allowed' => [1, 12], 'default' => null, 'owner' => 'Accounting', 'retroactive' => false],
        'default_vat_rate' => ['name' => 'อัตรา VAT เริ่มต้น', 'description' => 'อัตราเริ่มต้นที่เอกสารนำไป snapshot', 'type' => 'decimal', 'allowed' => [0, 100], 'default' => null, 'owner' => 'Accounting', 'retroactive' => false],
        'default_withholding_tax_rate' => ['name' => 'อัตราหัก ณ ที่จ่ายเริ่มต้น', 'description' => 'อัตราเริ่มต้นซึ่งผู้ใช้เปลี่ยนตามประเภทรายการได้', 'type' => 'decimal', 'allowed' => [0, 100], 'default' => null, 'owner' => 'Accounting', 'retroactive' => false],
        'tax_decimal_places' => ['name' => 'ทศนิยมการคำนวณภาษี', 'description' => 'จำนวนทศนิยมสำหรับการปัดเศษภาษีทั้งระดับบรรทัดและเอกสาร', 'type' => 'integer', 'allowed' => [0, 4], 'default' => 2, 'owner' => 'Accounting', 'retroactive' => false],
        'manual_discount_approval_threshold' => ['name' => 'เพดานส่วนลดที่ต้องระบุเหตุผล (%)', 'description' => 'ส่วนลดที่กรอกนอก Price List และเกินเพดานนี้ต้องระบุเหตุผลก่อนอนุมัติเอกสารขาย', 'type' => 'decimal', 'allowed' => [0, 100], 'default' => 0, 'owner' => 'POS', 'retroactive' => false],
        'document_sequence_reset' => ['name' => 'รอบเริ่มเลขเอกสารใหม่', 'description' => 'ไม่เริ่มใหม่ รายปี หรือรายเดือน', 'type' => 'enum', 'allowed' => ['NEVER', 'YEARLY', 'MONTHLY'], 'default' => null, 'owner' => 'Settings', 'retroactive' => false],
        'posting_sla_minutes' => ['name' => 'SLA การลงบัญชี', 'description' => 'เวลาสูงสุดก่อนแจ้งเตือนรายการรอลงบัญชี', 'type' => 'integer', 'allowed' => [1, 10080], 'default' => null, 'owner' => 'Accounting', 'retroactive' => true],
        'recost_sla_minutes' => ['name' => 'SLA การคำนวณต้นทุนใหม่', 'description' => 'เวลาสูงสุดก่อนแจ้งเตือน recost ค้าง', 'type' => 'integer', 'allowed' => [1, 10080], 'default' => null, 'owner' => 'Inventory', 'retroactive' => true],
        'audit_retention_days' => ['name' => 'อายุการเก็บ Audit', 'description' => 'จำนวนวันที่เก็บประวัติการเปลี่ยนแปลง', 'type' => 'integer', 'allowed' => [1, 36500], 'default' => null, 'owner' => 'Platform', 'retroactive' => true],
        'file_retention_days' => ['name' => 'อายุการเก็บไฟล์', 'description' => 'จำนวนวันที่เก็บไฟล์เอกสาร', 'type' => 'integer', 'allowed' => [1, 36500], 'default' => null, 'owner' => 'Platform', 'retroactive' => true],
    ];

    public const REQUIRED = [
        'accounting' => ['accounting_profile', 'fiscal_year_start_month', 'default_vat_rate', 'default_withholding_tax_rate', 'tax_decimal_places', 'posting_sla_minutes'],
        'inventory' => ['inventory_costing_method', 'allow_negative_stock', 'recost_sla_minutes'],
        'documents' => ['document_sequence_reset'],
        'operations' => ['audit_retention_days', 'file_retention_days'],
    ];

    public function definition(string $key): array
    {
        return self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown global setting [{$key}].");
    }

    public function missing(string $module, array $values): array
    {
        $keys = self::REQUIRED[$module] ?? throw new InvalidArgumentException("Unknown readiness module [{$module}].");

        if (($values['allow_negative_stock'] ?? null) === true || ($values['allow_negative_stock'] ?? null) === 1) {
            $keys[] = 'negative_stock_cost_method';
        }

        return array_values(array_filter($keys, fn (string $key) => ! array_key_exists($key, $values) || $values[$key] === null || $values[$key] === ''));
    }
}
