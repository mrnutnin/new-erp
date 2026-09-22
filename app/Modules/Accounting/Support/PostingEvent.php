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
        'EMPLOYEE_ADVANCE' => ['label' => 'บัญชีเงินทดรองจ่ายพนักงาน', 'types' => ['ASSET']],
        'PURCHASE_EXPENSE' => ['label' => 'บัญชีค่าใช้จ่ายซื้อ', 'types' => ['EXPENSE', 'ASSET']],
        'DEFERRED_INPUT_VAT' => ['label' => 'บัญชีภาษีซื้อพักรอรับรู้', 'control' => 'INPUT_VAT'],
        'DEFERRED_OUTPUT_VAT' => ['label' => 'บัญชีภาษีขายพักรอรับรู้', 'control' => 'OUTPUT_VAT'],
        'INPUT_VAT' => ['label' => 'บัญชีภาษีซื้อ', 'control' => 'INPUT_VAT'],
        'OUTPUT_VAT' => ['label' => 'บัญชีภาษีขาย', 'control' => 'OUTPUT_VAT'],
        'WHT_RECEIVABLE' => ['label' => 'บัญชีภาษีหัก ณ ที่จ่ายรอรับ', 'control' => 'WITHHOLDING_TAX'],
        'WHT_PAYABLE' => ['label' => 'บัญชีภาษีหัก ณ ที่จ่ายรอจ่าย', 'control' => 'WITHHOLDING_TAX'],
        'INVENTORY' => ['label' => 'บัญชีสินค้าคงเหลือ', 'control' => 'INVENTORY'],
        'COGS' => ['label' => 'บัญชีต้นทุนขาย', 'types' => ['EXPENSE']],
        'ISSUE_EXPENSE' => ['label' => 'บัญชีค่าใช้จ่ายจากการเบิกสินค้า', 'types' => ['EXPENSE']],
        'PURCHASE_RETURN_VARIANCE' => ['label' => 'บัญชีผลต่างต้นทุนคืนซื้อ', 'types' => ['EXPENSE']],
        'COMMISSION_EXPENSE' => ['label' => 'บัญชีค่าใช้จ่ายคอมมิชชั่น', 'types' => ['EXPENSE']],
        'ADJUSTMENT_GAIN' => ['label' => 'บัญชีกำไรจากปรับปรุงสินค้าคงเหลือ', 'types' => ['REVENUE']],
        'ADJUSTMENT_LOSS' => ['label' => 'บัญชีขาดทุนจากปรับปรุงสินค้าคงเหลือ', 'types' => ['EXPENSE']],
        'RECOST_GAIN' => ['label' => 'บัญชีกำไรจากปรับต้นทุนสินค้า', 'types' => ['REVENUE']],
        'RECOST_LOSS' => ['label' => 'บัญชีขาดทุนจากปรับต้นทุนสินค้า', 'types' => ['EXPENSE']],
        'ROUNDING_GAIN' => ['label' => 'บัญชีกำไรจากการปัดเศษต้นทุน', 'types' => ['REVENUE']],
        'ROUNDING_LOSS' => ['label' => 'บัญชีขาดทุนจากการปัดเศษต้นทุน', 'types' => ['EXPENSE']],
        'PETTY_CASH_VARIANCE_GAIN' => ['label' => 'บัญชีเงินเกินจากเงินสดย่อย', 'types' => ['REVENUE']],
        'PETTY_CASH_VARIANCE_LOSS' => ['label' => 'บัญชีเงินขาดจากเงินสดย่อย', 'types' => ['EXPENSE']],
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
        'WIP' => ['label' => 'บัญชีงานระหว่างทำ', 'control' => 'WIP'],
        'FINISHED_GOODS' => ['label' => 'บัญชีสินค้าสำเร็จรูป', 'control' => 'INVENTORY'],
        'SCRAP_INVENTORY' => ['label' => 'บัญชีสินค้าคงเหลือเศษผลิต', 'control' => 'INVENTORY'],
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
        'employee_advance' => ['module' => 'Finance', 'document' => 'จ่ายเงินทดรองพนักงาน', 'book' => 'PAYMENT', 'status' => 'LIVE', 'roles' => ['EMPLOYEE_ADVANCE'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'employee_advance_clearing' => ['module' => 'Finance', 'document' => 'เคลียร์เงินทดรองพนักงาน', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['EMPLOYEE_ADVANCE'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'expense_payment' => ['module' => 'Finance', 'document' => 'จ่ายค่าใช้จ่าย', 'book' => 'PAYMENT', 'status' => 'DEFERRED', 'roles' => [], 'reversal' => 'ORIGINAL_JOURNAL'],
        'petty_cash_top_up' => ['module' => 'Finance', 'document' => 'เติมเงินสดย่อย', 'book' => 'PAYMENT', 'status' => 'LIVE', 'roles' => [], 'reversal' => 'ORIGINAL_JOURNAL'],
        'internal_transfer' => ['module' => 'Finance', 'document' => 'โอนเงินระหว่างบัญชี', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => [], 'reversal' => 'ORIGINAL_JOURNAL'],
        'petty_cash_clearing' => ['module' => 'Finance', 'document' => 'เคลียร์เงินสดย่อย', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['PETTY_CASH_VARIANCE_GAIN', 'PETTY_CASH_VARIANCE_LOSS'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'sales_commission_payout' => ['module' => 'Finance', 'document' => 'จ่ายคอมมิชชั่น', 'book' => 'PAYMENT', 'status' => 'LIVE', 'roles' => ['COMMISSION_EXPENSE'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'inventory_adjustment' => ['module' => 'WMS', 'document' => 'ปรับปรุงสินค้าคงเหลือ', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['INVENTORY', 'ADJUSTMENT_GAIN', 'ADJUSTMENT_LOSS'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'inventory.recost' => ['module' => 'WMS', 'document' => 'ปรับต้นทุนสินค้า', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['INVENTORY', 'RECOST_GAIN', 'RECOST_LOSS'], 'reversal' => 'DELTA_OR_REVERSAL'],
        'inventory.revaluation.cogs' => ['module' => 'WMS', 'document' => 'ปรับต้นทุนขายย้อนหลัง', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['COGS', 'INVENTORY'], 'reversal' => 'DELTA_OR_REVERSAL'],
        'inventory.revaluation.issue_expense' => ['module' => 'WMS', 'document' => 'ปรับต้นทุนเบิกใช้ย้อนหลัง', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['ISSUE_EXPENSE', 'INVENTORY'], 'reversal' => 'DELTA_OR_REVERSAL'],
        'inventory.revaluation.sales_return' => ['module' => 'WMS', 'document' => 'ปรับต้นทุนรับคืนจากการขาย', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['INVENTORY', 'COGS'], 'reversal' => 'DELTA_OR_REVERSAL'],
        'inventory.revaluation.issue_return' => ['module' => 'WMS', 'document' => 'ปรับต้นทุนรับคืนจากการเบิก', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['INVENTORY', 'ISSUE_EXPENSE'], 'reversal' => 'DELTA_OR_REVERSAL'],
        'inventory.revaluation.rounding' => ['module' => 'WMS', 'document' => 'ปรับผลต่างการปัดเศษต้นทุน', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['INVENTORY', 'ROUNDING_GAIN', 'ROUNDING_LOSS'], 'reversal' => 'DELTA_OR_REVERSAL'],
        'production.revaluation.wip' => ['module' => 'WMS', 'document' => 'ปรับต้นทุนงานระหว่างทำย้อนหลัง', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['WIP', 'INVENTORY'], 'reversal' => 'DELTA_OR_REVERSAL'],
        'production.revaluation.finished_goods' => ['module' => 'WMS', 'document' => 'ปรับต้นทุนสินค้าผลิตเสร็จย้อนหลัง', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['FINISHED_GOODS', 'WIP'], 'reversal' => 'DELTA_OR_REVERSAL'],
        'production.revaluation.material_return' => ['module' => 'WMS', 'document' => 'ปรับต้นทุนรับคืนวัตถุดิบผลิต', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['INVENTORY', 'WIP'], 'reversal' => 'DELTA_OR_REVERSAL'],
        'purchasing.revaluation.return_cost' => ['module' => 'Purchasing', 'document' => 'ปรับผลต่างต้นทุนคืนซื้อย้อนหลัง', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['PURCHASE_RETURN_VARIANCE', 'INVENTORY'], 'reversal' => 'DELTA_OR_REVERSAL'],
        // Goods Receipt is operational/source-only in this ERP flow. The
        // Inventory and AP Journal is created by supplier_invoice.inventory;
        // posting another Journal at receipt would duplicate inventory value.
        'inventory.receipt' => ['module' => 'WMS', 'document' => 'รับสินค้า (ไม่ลงบัญชีซ้ำ)', 'book' => 'PURCHASE', 'status' => 'NO_GL', 'roles' => [], 'reversal' => 'ORIGINAL_JOURNAL'],
        'inventory.issue' => ['module' => 'WMS', 'document' => 'เบิกสินค้า', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['ISSUE_EXPENSE', 'INVENTORY'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'inventory.issue_return' => ['module' => 'WMS', 'document' => 'รับคืนจากการเบิกสินค้า', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['INVENTORY', 'ISSUE_EXPENSE'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'production.material_issue' => ['module' => 'WMS', 'document' => 'เบิกวัตถุดิบผลิต', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['WIP', 'INVENTORY'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'production.material_return' => ['module' => 'WMS', 'document' => 'รับคืนวัตถุดิบผลิต', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['INVENTORY', 'WIP'], 'reversal' => 'ORIGINAL_JOURNAL'],
        // Manual WMS production receipt is supported without enabling the
        // Production module.  The receipt still uses its own accounting
        // contract so it cannot fall through to inventory_adjustment.
        'production.finished_receipt' => ['module' => 'WMS', 'document' => 'รับสินค้าผลิตเสร็จ', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['FINISHED_GOODS', 'WIP', 'PRODUCTION_VARIANCE'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'production.scrap_receipt' => ['module' => 'WMS', 'document' => 'รับเศษจากการผลิต', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['SCRAP_INVENTORY', 'WIP'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.depreciation' => ['module' => 'Asset', 'document' => 'ค่าเสื่อมราคา', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['DEPRECIATION_EXPENSE', 'ACCUMULATED_DEPRECIATION'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.capitalization' => ['module' => 'Asset', 'document' => 'รับรู้สินทรัพย์', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['ASSET_COST', 'CAPITALIZATION_CLEARING'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.addition' => ['module' => 'Asset', 'document' => 'เพิ่มมูลค่าสินทรัพย์', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['ASSET_COST', 'CAPITALIZATION_CLEARING'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.impairment' => ['module' => 'Asset', 'document' => 'ด้อยค่าสินทรัพย์', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['IMPAIRMENT_LOSS', 'ACCUMULATED_IMPAIRMENT'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.disposal' => ['module' => 'Asset', 'document' => 'จำหน่ายสินทรัพย์', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['ASSET_COST', 'ACCUMULATED_DEPRECIATION', 'ACCUMULATED_IMPAIRMENT', 'DISPOSAL_CLEARING', 'DISPOSAL_GAIN', 'DISPOSAL_LOSS'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.write_off' => ['module' => 'Asset', 'document' => 'ตัดออกสินทรัพย์', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => ['ASSET_COST', 'ACCUMULATED_DEPRECIATION', 'ACCUMULATED_IMPAIRMENT', 'DISPOSAL_LOSS'], 'reversal' => 'ORIGINAL_JOURNAL'],
        'asset.branch_transfer' => ['module' => 'Asset', 'document' => 'โอนสาขาสินทรัพย์', 'book' => 'GENERAL', 'status' => 'NO_GL', 'roles' => [], 'reversal' => 'DOMAIN_CORRECTION'],
        'accounting.period_adjustment' => ['module' => 'Accounting', 'document' => 'ปรับปรุงงวดบัญชี', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => [], 'reversal' => 'ORIGINAL_JOURNAL'],
        'opening_ar' => ['module' => 'Installer', 'document' => 'ยอดยกมาลูกหนี้', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => [], 'reversal' => 'ORIGINAL_JOURNAL'],
        'opening_ap' => ['module' => 'Installer', 'document' => 'ยอดยกมาเจ้าหนี้', 'book' => 'GENERAL', 'status' => 'LIVE', 'roles' => [], 'reversal' => 'ORIGINAL_JOURNAL'],
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
