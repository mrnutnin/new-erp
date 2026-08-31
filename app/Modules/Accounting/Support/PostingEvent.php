<?php

namespace App\Modules\Accounting\Support;

use DomainException;

final class PostingEvent
{
    private const BOOK_TYPES = [
        'supplier_invoice.inventory' => 'PURCHASE',
        'supplier_invoice.expense' => 'PURCHASE',
        'purchase_credit_note' => 'PURCHASE',
        'sales_invoice' => 'SALES',
        'sales_cogs' => 'SALES',
        'sales_credit_note' => 'SALES',
        'customer_payment' => 'RECEIPT',
        'customer_advance' => 'RECEIPT',
        'supplier_payment' => 'PAYMENT',
        'expense_payment' => 'PAYMENT',
        'sales_commission_payout' => 'PAYMENT',
        'inventory_adjustment' => 'GENERAL',
        // RECOST uses the General journal book but remains feature-gated at
        // the WMS service boundary until the release gate is enabled.
        'inventory.recost' => 'GENERAL',
        'inventory.receipt' => 'PURCHASE',
        'production.material_issue' => 'GENERAL',
        'production.finished_receipt' => 'GENERAL',
        'asset.depreciation' => 'GENERAL',
        'accounting.period_adjustment' => 'GENERAL',
    ];

    public static function codes(): array
    {
        return array_keys(self::BOOK_TYPES);
    }

    public static function bookType(string $eventCode): string
    {
        return self::BOOK_TYPES[$eventCode]
            ?? throw new DomainException("ไม่รองรับ Accounting event {$eventCode}");
    }
}
