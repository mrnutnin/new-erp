<?php

namespace App\Modules\Accounting\Support;

use DomainException;

final class PostingEvent
{
    private const ROLES = [
        'ACCOUNTS_RECEIVABLE' => ['label' => 'บัญชีลูกหนี้การค้า', 'control' => 'AR'],
        'SALES_REVENUE' => ['label' => 'บัญชีรายได้จากการขาย', 'types' => ['REVENUE']],
        'ACCOUNTS_PAYABLE' => ['label' => 'บัญชีเจ้าหนี้การค้า', 'control' => 'AP'],
        'CUSTOMER_ADVANCE' => ['label' => 'บัญชีเงินรับล่วงหน้าลูกค้า', 'types' => ['LIABILITY']],
        'SUPPLIER_ADVANCE' => ['label' => 'บัญชีเงินจ่ายล่วงหน้าผู้ขาย', 'types' => ['ASSET']],
        'PURCHASE_EXPENSE' => ['label' => 'บัญชีค่าใช้จ่ายซื้อ', 'types' => ['EXPENSE', 'ASSET']],
        'DEFERRED_INPUT_VAT' => ['label' => 'บัญชีภาษีซื้อพักรอรับรู้', 'control' => 'INPUT_VAT'],
        'DEFERRED_OUTPUT_VAT' => ['label' => 'บัญชีภาษีขายพักรอรับรู้', 'control' => 'OUTPUT_VAT'],
        'INPUT_VAT' => ['label' => 'บัญชีภาษีซื้อ', 'control' => 'INPUT_VAT'],
        'OUTPUT_VAT' => ['label' => 'บัญชีภาษีขาย', 'control' => 'OUTPUT_VAT'],
        'WHT_RECEIVABLE' => ['label' => 'บัญชีภาษีหัก ณ ที่จ่ายรอรับ', 'control' => 'WITHHOLDING_TAX'],
        'WHT_PAYABLE' => ['label' => 'บัญชีภาษีหัก ณ ที่จ่ายรอจ่าย', 'control' => 'WITHHOLDING_TAX'],
        'INVENTORY' => ['label' => 'บัญชีสินค้าคงเหลือ', 'control' => 'INVENTORY'],
        'COGS' => ['label' => 'บัญชีต้นทุนขาย', 'types' => ['EXPENSE']],
        'COMMISSION_EXPENSE' => ['label' => 'บัญชีค่าใช้จ่ายคอมมิชชั่น', 'types' => ['EXPENSE']],
        'ADJUSTMENT_GAIN' => ['label' => 'บัญชีกำไรจากปรับปรุงสินค้าคงเหลือ', 'types' => ['REVENUE']],
        'ADJUSTMENT_LOSS' => ['label' => 'บัญชีขาดทุนจากปรับปรุงสินค้าคงเหลือ', 'types' => ['EXPENSE']],
        'RECOST_GAIN' => ['label' => 'บัญชีกำไรจากปรับต้นทุนสินค้า', 'types' => ['REVENUE']],
        'RECOST_LOSS' => ['label' => 'บัญชีขาดทุนจากปรับต้นทุนสินค้า', 'types' => ['EXPENSE']],
        // Fixed assets are posted to their FIXED_ASSET control account when a
        // subledger asset is present.  A company may also use a normal ASSET
        // account for an asset-cost mapping, so both are intentionally valid.
        'ASSET_COST' => ['label' => 'บัญชีสินทรัพย์', 'types' => ['ASSET'], 'controls' => ['FIXED_ASSET']],
        'CAPITALIZATION_CLEARING' => ['label' => 'บัญชีพักการรับรู้สินทรัพย์'],
        'DEPRECIATION_EXPENSE' => ['label' => 'บัญชีค่าเสื่อมราคา', 'types' => ['EXPENSE']],
        'ACCUMULATED_DEPRECIATION' => ['label' => 'บัญชีค่าเสื่อมราคาสะสม', 'types' => ['ASSET']],
        'IMPAIRMENT_LOSS' => ['label' => 'บัญชีขาดทุนจากการด้อยค่า', 'types' => ['EXPENSE']],
        'ACCUMULATED_IMPAIRMENT' => ['label' => 'บัญชีด้อยค่าสะสม', 'types' => ['ASSET']],
        'DISPOSAL_CLEARING' => ['label' => 'บัญชีพักเงินรับจากการจำหน่าย', 'types' => ['ASSET']],
        'DISPOSAL_GAIN' => ['label' => 'บัญชีกำไรจากการจำหน่าย', 'types' => ['REVENUE']],
        'DISPOSAL_LOSS' => ['label' => 'บัญชีขาดทุนจากการจำหน่าย', 'types' => ['EXPENSE']],
        'WIP' => ['label' => 'บัญชีงานระหว่างทำ', 'types' => ['ASSET']],
        'FINISHED_GOODS' => ['label' => 'บัญชีสินค้าสำเร็จรูป', 'control' => 'INVENTORY'],
        'PRODUCTION_VARIANCE' => ['label' => 'บัญชีผลต่างการผลิต', 'types' => ['EXPENSE', 'REVENUE']],
    ];

    private const CONTRACTS = [
        'supplier_invoice.inventory' => ['module' => 'Purchasing', 'document' => 'ใบตั้งหนี้สินค้า', 'book' => 'PURCHASE', 'status' => 'LIVE', 'roles' => ['INVENTORY', 'ACCOUNTS_PAYABLE'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'supplier_invoice.expense' => ['module' => 'Purchasing', 'document' => 'ใบตั้งหนี้ค่าใช้จ่าย', 'book' => 'PURCHASE', 'status' => 'LIVE', 'roles' => ['PURCHASE_EXPENSE', 'ACCOUNTS_PAYABLE', 'DEFERRED_INPUT_VAT'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'purchase_credit_note' => ['module' => 'Purchasing', 'document' => 'ใบลดหนี้ซื้อ', 'book' => 'PURCHASE', 'status' => 'LIVE', 'roles' => [], 'reversal' => 'ORIGINAL_JOURNAL'],
        'sales_invoice' => ['module' => 'Sales', 'document' => 'ใบกำกับขาย/ขายสด', 'book' => 'SALES', 'status' => 'LIVE', 'roles' => ['ACCOUNTS_RECEIVABLE', 'DEFERRED_OUTPUT_VAT', 'WHT_RECEIVABLE', 'CUSTOMER_ADVANCE'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'sales_cogs' => ['module' => 'Sales', 'document' => 'ตัดต้นทุนขาย', 'book' => 'SALES', 'status' => 'LIVE', 'roles' => [], 'reversal' => 'ORIGINAL_JOURNAL'],
        'sales_credit_note' => ['module' => 'Sales', 'document' => 'ใบลดหนี้ขาย', 'book' => 'SALES', 'status' => 'LIVE', 'roles' => [], 'reversal' => 'ORIGINAL_JOURNAL'],
        'customer_payment' => ['module' => 'Finance', 'document' => 'รับชำระเงิน', 'book' => 'RECEIPT', 'status' => 'LIVE', 'roles' => ['OUTPUT_VAT', 'WHT_RECEIVABLE', 'CUSTOMER_ADVANCE'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'customer_advance' => ['module' => 'Finance', 'document' => 'รับเงินมัดจำ', 'book' => 'RECEIPT', 'status' => 'LIVE', 'roles' => ['CUSTOMER_ADVANCE', 'WHT_RECEIVABLE'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'supplier_payment' => ['module' => 'Finance', 'document' => 'จ่ายชำระเงิน', 'book' => 'PAYMENT', 'status' => 'LIVE', 'roles' => ['INPUT_VAT', 'WHT_PAYABLE', 'SUPPLIER_ADVANCE'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'expense_payment' => ['module' => 'Finance', 'document' => 'จ่ายค่าใช้จ่าย', 'book' => 'PAYMENT', 'status' => 'DEFERRED', 'roles' => [], 'reversal' => 'ORIGINAL_JOURNAL'],
        'sales_commission_payout' => ['module' => 'Finance', 'document' => 'จ่ายคอมมิชชั่น', 'book' => 'PAYMENT', 'status' => 'LIVE', 'roles' => ['COMMISSION_EXPENSE'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'inventory_adjustment' => ['module' => 'WMS', 'document' => 'ปรับปรุงสินค้าคงเหลือ', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['INVENTORY', 'ADJUSTMENT_GAIN', 'ADJUSTMENT_LOSS'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'inventory.recost' => ['module' => 'WMS', 'document' => 'ปรับต้นทุนสินค้า', 'book' => 'GENERAL', 'status' => 'DEFERRED', 'roles' => ['INVENTORY'], 'reversal' => 'DELTA_OR_REVERSAL'],
        'inventory.receipt' => ['module' => 'WMS', 'document' => 'รับสินค้า', 'book' => 'PURCHASE', 'status' => 'DEFERRED', 'roles' => ['INVENTORY'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'production.material_issue' => ['module' => 'Production', 'document' => 'เบิกวัตถุดิบผลิต', 'book' => 'GENERAL', 'status' => 'DEFERRED', 'roles' => ['WIP', 'INVENTORY'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'production.finished_receipt' => ['module' => 'Production', 'document' => 'รับสินค้าสำเร็จรูป', 'book' => 'GENERAL', 'status' => 'DEFERRED', 'roles' => ['FINISHED_GOODS', 'WIP', 'PRODUCTION_VARIANCE'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.depreciation' => ['module' => 'Asset', 'document' => 'ค่าเสื่อมราคา', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['DEPRECIATION_EXPENSE', 'ACCUMULATED_DEPRECIATION'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.capitalization' => ['module' => 'Asset', 'document' => 'รับรู้สินทรัพย์', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['ASSET_COST', 'CAPITALIZATION_CLEARING'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.addition' => ['module' => 'Asset', 'document' => 'เพิ่มมูลค่าสินทรัพย์', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['ASSET_COST', 'CAPITALIZATION_CLEARING'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.impairment' => ['module' => 'Asset', 'document' => 'ด้อยค่าสินทรัพย์', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['IMPAIRMENT_LOSS', 'ACCUMULATED_IMPAIRMENT'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.disposal' => ['module' => 'Asset', 'document' => 'จำหน่ายสินทรัพย์', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['ASSET_COST', 'ACCUMULATED_DEPRECIATION', 'ACCUMULATED_IMPAIRMENT', 'DISPOSAL_CLEARING', 'DISPOSAL_GAIN', 'DISPOSAL_LOSS'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.write_off' => ['module' => 'Asset', 'document' => 'ตัดออกสินทรัพย์', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['ASSET_COST', 'ACCUMULATED_DEPRECIATION', 'ACCUMULATED_IMPAIRMENT', 'DISPOSAL_LOSS'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.branch_transfer' => ['module' => 'Asset', 'document' => 'โอนสาขาสินทรัพย์', 'book' => 'GENERAL', 'status' => 'NO_GL', 'roles' => [], 'reversal' => 'DOMAIN_CORRECTION'],
        'accounting.period_adjustment' => ['module' => 'Accounting', 'document' => 'ปรับปรุงงวดบัญชี', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => [], 'reversal' => 'ORIGINAL_JOURNAL'],
    ];

    public static function codes(): array
    {
        return array_keys(self::CONTRACTS);
    }

    public static function contract(string $eventCode): array
    {
        $eventCode = strtolower(trim($eventCode));
        $contract = self::CONTRACTS[$eventCode] ?? throw new DomainException("ไม่รองรับ Accounting event {$eventCode}");

        return ['event_code' => $eventCode, ...$contract];
    }

    public static function bookType(string $eventCode): string
    {
        return self::contract($eventCode)['book'];
    }

    public static function roles(string $eventCode): array
    {
        return self::contract($eventCode)['roles'];
    }

    public static function role(string $role): array
    {
        $role = strtoupper(trim($role));

        return ['account_role' => $role, ...(self::ROLES[$role] ?? throw new DomainException("ไม่รองรับ Account role {$role}"))];
    }

    public static function allowsRole(string $eventCode, string $role): bool
    {
        return in_array(strtoupper(trim($role)), self::roles($eventCode), true);
    }
}
