<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Pos\Models\SalesQuotation;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SalesQuotationPdfController extends Controller
{
    public function show(Request $request, SalesQuotation $salesQuotation, DocumentPdfRenderer $renderer, GlobalSettings $settings)
    {
        abort_unless((int) $salesQuotation->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
        $salesQuotation->load(['lines', 'rfq.sourceIntake.preparedBy', 'sourceIntake.preparedBy', 'party']);
        $logoPath = $settings->value('logo_path');
        $logo = $logoPath && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->path($logoPath) : null;
        $bytes = $renderer->renderView('Pos::pdf.sales-quotation', [
            'quotation' => $salesQuotation,
            'sourceIntake' => $salesQuotation->sourceIntake ?? $salesQuotation->rfq?->sourceIntake,
            'sourceLabel' => $salesQuotation->rfq?->document_number,
            'logo' => $logo,
            'companyName' => $settings->value('company_name'),
            'companyAddress' => $settings->value('company_address'),
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
            'decimalPlaces' => (int) ($settings->value('tax_decimal_places') ?? 2),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($salesQuotation->document_number).'.pdf"',
        ]);
    }
}
