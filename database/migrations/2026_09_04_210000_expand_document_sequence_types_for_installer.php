<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<int, string> */
    private const TYPES = [
        'RECEIPT', 'PAYMENT', 'SALES_INVOICE', 'SALES_CREDIT_NOTE', 'PURCHASE_INVOICE', 'PURCHASE_CREDIT_NOTE',
        'CUSTOMER', 'SUPPLIER', 'PURCHASE_ORDER', 'PURCHASE_REQUISITION', 'GOODS_RECEIPT', 'PURCHASE_RETURN',
        'INVENTORY_ADJUSTMENT', 'INVENTORY_ISSUE', 'INVENTORY_RETURN', 'WMS_TRANSFER', 'STOCK_COUNT',
        'SALES_RFQ', 'SALES_INTAKE', 'SALES_QUOTATION', 'SALES_ORDER', 'PHYSICAL_SALE_HS', 'PHYSICAL_SALE_IV',
        'SALES_RETURN', 'ADVANCE_DEPOSIT_AI', 'BILLING_NOTE', 'LANDED_COST', 'PETTY_CASH', 'PETTY_CASH_TOP_UP',
        'PETTY_CASH_CLEARING', 'EMPLOYEE_ADVANCE', 'EMPLOYEE_ADVANCE_CLEARING', 'INTERNAL_TRANSFER',
        'ASSET_REGISTER', 'ASSET_CAPITALIZATION', 'ASSET_ADDITION', 'ASSET_TRANSFER', 'ASSET_COUNT',
        'ASSET_MAINTENANCE', 'ASSET_DEPRECIATION', 'ASSET_IMPAIRMENT', 'ASSET_DISPOSAL',
    ];

    public function up(): void
    {
        $column = DB::selectOne("SHOW COLUMNS FROM finance_document_sequences LIKE 'document_type'");
        if (! $column || ! str_starts_with(strtolower((string) $column->Type), 'enum(')) {
            return;
        }

        preg_match_all("/'([^']+)'/", (string) $column->Type, $matches);
        $types = array_values(array_unique(array_merge($matches[1] ?? [], self::TYPES)));
        $enum = implode(',', array_map(fn (string $type): string => DB::getPdo()->quote($type), $types));

        DB::statement("ALTER TABLE finance_document_sequences MODIFY document_type ENUM({$enum}) NOT NULL");
    }

    public function down(): void
    {
        // Keep enum values on rollback: later migrations and existing rows may use them.
    }
};
