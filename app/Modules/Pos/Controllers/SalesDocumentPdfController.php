<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Pos\Models\SalesDocument;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\Request;

final class SalesDocumentPdfController extends Controller
{
    public function show(Request $request, SalesDocument $salesDocument, DocumentPdfRenderer $renderer, GlobalSettings $settings)
    {
        abort_unless((int) $salesDocument->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
        $salesDocument->load(['branch', 'lines.item', 'lines.uom', 'party', 'sourceInvoice']);
        $logo = $settings->logoDataUri();
        $bytes = $renderer->renderView('Pos::pdf.sales-document', ['document' => $salesDocument, 'logo' => $logo, 'companyName' => $settings->value('company_name'), 'companyAddress' => $salesDocument->branch?->tax_address ?: $settings->value('company_address'), 'companyTaxId' => $settings->value('tax_id'), 'companyTaxBranchCode' => $salesDocument->branch?->tax_branch_code, 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'), 'decimalPlaces' => (int) ($settings->value('tax_decimal_places') ?? 2)]);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.rawurlencode($salesDocument->document_number).'.pdf"']);
    }
}
