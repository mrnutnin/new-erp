<?php

namespace App\Modules\Pos\Support;

use App\Modules\Pos\Models\SalesIntake;
use App\Modules\Pos\Models\SalesOrder;
use App\Modules\Pos\Models\SalesQuotation;
use App\Modules\Pos\Models\SalesRfq;

final class SalesDocumentTrail
{
    public static function for(SalesIntake|SalesRfq|SalesQuotation|SalesOrder $document): array
    {
        $intake = $document instanceof SalesIntake ? $document : null;
        $rfq = $document instanceof SalesRfq ? $document : null;
        $quotation = $document instanceof SalesQuotation ? $document : null;
        $order = $document instanceof SalesOrder ? $document : null;

        $quotation ??= $intake?->quotation ?? $rfq?->quotation ?? $order?->quotation;
        $rfq ??= $intake?->rfq ?? $quotation?->rfq ?? $order?->rfq;
        $order ??= $intake?->order ?? $rfq?->order ?? $quotation?->order;
        $intake ??= $rfq?->sourceIntake ?? $quotation?->sourceIntake ?? $order?->sourceIntake ?? $quotation?->rfq?->sourceIntake ?? $order?->rfq?->sourceIntake ?? $order?->quotation?->sourceIntake;
        $physicalSales = $order?->physicalSales?->keyBy('document_type');
        $hs = $physicalSales?->get('HS');
        $iv = $physicalSales?->get('IV');

        return compact('intake', 'rfq', 'quotation', 'order', 'hs', 'iv');
    }
}
