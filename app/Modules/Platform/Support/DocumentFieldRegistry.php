<?php

namespace App\Modules\Platform\Support;

final class DocumentFieldRegistry
{
    public static function fields(string $documentType): array
    {
        return [
            'company.logo' => ['label' => 'โลโก้บริษัท', 'group' => 'company'], 'company.name' => ['label' => 'ชื่อบริษัท', 'group' => 'company'], 'company.address' => ['label' => 'ที่อยู่บริษัท', 'group' => 'company'], 'company.tax_id' => ['label' => 'เลขผู้เสียภาษี', 'group' => 'company'],
            'party.name' => ['label' => 'ชื่อลูกค้า/Supplier', 'group' => 'party'], 'party.address' => ['label' => 'ที่อยู่คู่ค้า', 'group' => 'party'],
            'document.title' => ['label' => 'ชื่อเอกสาร', 'group' => 'document'], 'document.number' => ['label' => 'เลขที่เอกสาร', 'group' => 'document'], 'document.date' => ['label' => 'วันที่เอกสาร', 'group' => 'document'], 'document.status' => ['label' => 'สถานะ', 'group' => 'document'],
            'lines' => ['label' => 'รายการเอกสาร', 'group' => 'lines'], 'totals.subtotal' => ['label' => 'ยอดก่อนภาษี', 'group' => 'totals'], 'totals.vat' => ['label' => 'VAT', 'group' => 'totals'], 'totals.grand_total' => ['label' => 'ยอดรวม', 'group' => 'totals'], 'signatures.prepared_by' => ['label' => 'ผู้จัดทำ', 'group' => 'signatures'], 'signatures.approved_by' => ['label' => 'ผู้อนุมัติ', 'group' => 'signatures'],
        ];
    }

    public static function allows(string $documentType, string $field): bool
    {
        return array_key_exists($field, self::fields($documentType));
    }
}
