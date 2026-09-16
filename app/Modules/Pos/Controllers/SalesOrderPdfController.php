<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Pos\Models\SalesOrder;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\Request;

class SalesOrderPdfController extends Controller
{
    public function show(Request $request, SalesOrder $salesOrder, DocumentPdfRenderer $renderer, GlobalSettings $settings)
    {
        abort_unless((int) $salesOrder->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
        $salesOrder->load(['branch', 'lines', 'quotation.sourceIntake.preparedBy', 'rfq.sourceIntake.preparedBy', 'sourceIntake.preparedBy', 'party']);
        $logo = $settings->logoDataUri();
        $bytes = $renderer->renderView('Pos::pdf.sales-order', [
            'order' => $salesOrder,
            'sourceIntake' => $salesOrder->sourceIntake ?? $salesOrder->quotation?->sourceIntake ?? $salesOrder->rfq?->sourceIntake,
            'sourceLabel' => $salesOrder->quotation?->document_number ?? $salesOrder->rfq?->document_number ?? $salesOrder->sourceIntake?->document_number,
            'logo' => $logo,
            'companyName' => $settings->value('company_name'),
            'companyAddress' => $salesOrder->branch?->tax_address ?: $settings->value('company_address'),
            'companyTaxId' => $settings->value('tax_id'),
            'companyTaxBranchCode' => $salesOrder->branch?->tax_branch_code,
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
            'decimalPlaces' => (int) ($settings->value('tax_decimal_places') ?? 2),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($salesOrder->document_number).'.pdf"',
        ]);
    }
}
