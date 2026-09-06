<?php

namespace Database\Seeders;

use App\Models\Program;
use App\Modules\Finance\Models\DocumentSequence;
use Illuminate\Database\Seeder;

/** Installs global document-number templates used by the enabled modules. */
class SystemDocumentSequenceSeeder extends Seeder
{
    private const DEFAULT_FORMAT = '{PREFIX}{BRANCH}{YYMM}{NUMBER:6}';

    private const DEFAULT_RESET = 'MONTHLY';

    public function run(): void
    {
        foreach ($this->requiredDefinitions() as $definition) {
            $sequence = DocumentSequence::withTrashed()->firstOrNew([
                'warehouse_id' => null,
                'document_type' => $definition['type'],
            ]);

            $isNew = ! $sequence->exists;
            $sequence->deleted_at = null;
            if ($isNew) {
                $sequence->fill([
                    'name' => $definition['name'],
                    'prefix' => $definition['prefix'],
                    'is_active' => true,
                    'number_reuse_policy' => 'NEVER_REUSE',
                ]);
                $sequence->next_number = 1;
                $sequence->number_format = $definition['format'] ?? self::DEFAULT_FORMAT;
                $sequence->reset_rule = $definition['reset'] ?? self::DEFAULT_RESET;
            } elseif ((int) $sequence->next_number === 1 && $sequence->getAttribute('last_reset_key') === null) {
                // Migrate an untouched installer default, but preserve customer changes.
                $sequence->number_format = $definition['format'] ?? self::DEFAULT_FORMAT;
                $sequence->reset_rule = $definition['reset'] ?? self::DEFAULT_RESET;
            }
            $sequence->save();
        }
    }

    /** @return array<int, array{module:string,type:string,name:string,prefix:string,format?:string,reset?:string}> */
    public static function definitions(): array
    {
        return [
            ['module' => 'core', 'type' => 'RECEIPT', 'name' => 'ใบรับเงิน', 'prefix' => 'RC'],
            ['module' => 'core', 'type' => 'PAYMENT', 'name' => 'ใบจ่ายเงิน', 'prefix' => 'PV'],
            ['module' => 'core', 'type' => 'SALES_INVOICE', 'name' => 'ใบแจ้งหนี้ขาย', 'prefix' => 'INV'],
            ['module' => 'core', 'type' => 'SALES_CREDIT_NOTE', 'name' => 'ใบลดหนี้ขาย', 'prefix' => 'CN'],
            ['module' => 'core', 'type' => 'PURCHASE_INVOICE', 'name' => 'ใบแจ้งหนี้ซื้อ', 'prefix' => 'PINV'],
            ['module' => 'core', 'type' => 'PURCHASE_CREDIT_NOTE', 'name' => 'ใบลดหนี้ซื้อ', 'prefix' => 'PCN'],
            ['module' => 'core', 'type' => 'CUSTOMER', 'name' => 'รหัสลูกค้า', 'prefix' => 'CUST'],
            ['module' => 'core', 'type' => 'SUPPLIER', 'name' => 'รหัสผู้ขาย/คู่ค้า', 'prefix' => 'SUP'],
            ['module' => 'purchasing', 'type' => 'PURCHASE_ORDER', 'name' => 'ใบสั่งซื้อ', 'prefix' => 'PO'],
            ['module' => 'purchasing', 'type' => 'PURCHASE_REQUISITION', 'name' => 'ใบขอซื้อ', 'prefix' => 'PR'],
            ['module' => 'purchasing', 'type' => 'GOODS_RECEIPT', 'name' => 'ใบรับสินค้า', 'prefix' => 'GR'],
            ['module' => 'purchasing', 'type' => 'PURCHASE_RETURN', 'name' => 'ใบคืนซื้อ', 'prefix' => 'PRT'],
            ['module' => 'wms', 'type' => 'INVENTORY_ADJUSTMENT', 'name' => 'ใบปรับปรุงสินค้าคงเหลือ', 'prefix' => 'ADJ'],
            ['module' => 'wms', 'type' => 'INVENTORY_ISSUE', 'name' => 'ใบเบิกสินค้า', 'prefix' => 'ISSUE'],
            ['module' => 'wms', 'type' => 'INVENTORY_RETURN', 'name' => 'ใบรับคืนจากการเบิก', 'prefix' => 'IRTN'],
            ['module' => 'wms', 'type' => 'WMS_TRANSFER', 'name' => 'ใบโอนสินค้า', 'prefix' => 'TR'],
            ['module' => 'wms', 'type' => 'STOCK_COUNT', 'name' => 'ใบนับสินค้า', 'prefix' => 'SC'],
            ['module' => 'pos', 'type' => 'SALES_RFQ', 'name' => 'ใบขอราคาขาย', 'prefix' => 'RFQ'],
            ['module' => 'pos', 'type' => 'SALES_INTAKE', 'name' => 'ใบรับข้อมูลเบื้องต้น', 'prefix' => 'SI'],
            ['module' => 'pos', 'type' => 'SALES_QUOTATION', 'name' => 'ใบเสนอราคา', 'prefix' => 'QT'],
            ['module' => 'pos', 'type' => 'SALES_ORDER', 'name' => 'ใบสั่งขาย', 'prefix' => 'SO'],
            ['module' => 'pos', 'type' => 'PHYSICAL_SALE_HS', 'name' => 'ใบขายสด/ใบกำกับภาษี', 'prefix' => 'HS'],
            ['module' => 'pos', 'type' => 'PHYSICAL_SALE_IV', 'name' => 'ใบส่งสินค้า/ใบกำกับภาษี', 'prefix' => 'IV'],
            ['module' => 'pos', 'type' => 'SALES_RETURN', 'name' => 'ใบรับคืนสินค้า', 'prefix' => 'SR'],
            ['module' => 'pos', 'type' => 'ADVANCE_DEPOSIT_AI', 'name' => 'ใบรับเงินล่วงหน้า', 'prefix' => 'AI'],
            ['module' => 'pos', 'type' => 'BILLING_NOTE', 'name' => 'ใบวางบิล', 'prefix' => 'BN'],
            ['module' => 'finance', 'type' => 'LANDED_COST', 'name' => 'ต้นทุนแฝงสินค้า', 'prefix' => 'LC'],
            ['module' => 'finance', 'type' => 'PETTY_CASH', 'name' => 'ใบสำคัญเงินสดย่อย', 'prefix' => 'PC'],
            ['module' => 'finance', 'type' => 'PETTY_CASH_TOP_UP', 'name' => 'ใบเติมเงินสดย่อย', 'prefix' => 'PCT'],
            ['module' => 'finance', 'type' => 'PETTY_CASH_CLEARING', 'name' => 'ใบเคลียร์เงินสดย่อย', 'prefix' => 'PCC'],
            ['module' => 'finance', 'type' => 'EMPLOYEE_ADVANCE', 'name' => 'ใบเงินทดรองจ่ายพนักงาน', 'prefix' => 'EA'],
            ['module' => 'finance', 'type' => 'EMPLOYEE_ADVANCE_CLEARING', 'name' => 'ใบเคลียร์เงินทดรองพนักงาน', 'prefix' => 'EAC'],
            ['module' => 'finance', 'type' => 'INTERNAL_TRANSFER', 'name' => 'โอนเงินระหว่างบัญชี', 'prefix' => 'TRF'],
            ['module' => 'asset', 'type' => 'ASSET_REGISTER', 'name' => 'ทะเบียนสินทรัพย์', 'prefix' => 'FA'],
            ['module' => 'asset', 'type' => 'ASSET_CAPITALIZATION', 'name' => 'ใบรับรู้สินทรัพย์', 'prefix' => 'AC'],
            ['module' => 'asset', 'type' => 'ASSET_ADDITION', 'name' => 'ใบเพิ่มมูลค่าสินทรัพย์', 'prefix' => 'AA'],
            ['module' => 'asset', 'type' => 'ASSET_TRANSFER', 'name' => 'ใบโอน/ย้ายสินทรัพย์', 'prefix' => 'AT'],
            ['module' => 'asset', 'type' => 'ASSET_COUNT', 'name' => 'ใบตรวจนับสินทรัพย์', 'prefix' => 'FC'],
            ['module' => 'asset', 'type' => 'ASSET_MAINTENANCE', 'name' => 'ใบแจ้งซ่อมสินทรัพย์', 'prefix' => 'MR'],
            ['module' => 'asset', 'type' => 'ASSET_DEPRECIATION', 'name' => 'ชุดคำนวณค่าเสื่อม', 'prefix' => 'DP'],
            ['module' => 'asset', 'type' => 'ASSET_IMPAIRMENT', 'name' => 'ใบบันทึกด้อยค่าสินทรัพย์', 'prefix' => 'IM'],
            ['module' => 'asset', 'type' => 'ASSET_DISPOSAL', 'name' => 'ใบจำหน่าย/ตัดออก', 'prefix' => 'AD'],
        ];
    }

    /** @return array<int, array{module:string,type:string,name:string,prefix:string,format?:string,reset?:string}> */
    public static function requiredDefinitions(): array
    {
        $enabled = Program::query()->where('is_enabled', true)->pluck('code')->all();

        return array_values(array_filter(self::definitions(), fn (array $definition): bool => $definition['module'] === 'core' || in_array($definition['module'], $enabled, true)));
    }
}
