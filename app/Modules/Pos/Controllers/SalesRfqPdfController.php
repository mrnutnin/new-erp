<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Pos\Models\SalesRfq;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SalesRfqPdfController extends Controller
{
    public function show(Request $r, SalesRfq $salesRfq, DocumentPdfRenderer $renderer, GlobalSettings $settings)
    {
        abort_unless((int) $salesRfq->branch_id === (int) $r->attributes->get('selectedBranch')->id, 404);
        $salesRfq->load('branch', 'lines.item', 'lines.uom', 'sourceIntake.preparedBy');
        $path = $settings->value('logo_path');
        $logo = $path && Storage::disk('public')->exists($path) ? Storage::disk('public')->path($path) : null;
        $bytes = $renderer->renderView('Pos::pdf.sales-rfq', ['salesRfq' => $salesRfq, 'sourceIntake' => $salesRfq->sourceIntake, 'sourceLabel' => $salesRfq->sourceIntake?->document_number, 'logo' => $logo, 'companyName' => $settings->value('company_name'), 'companyAddress' => $salesRfq->branch?->tax_address ?: $settings->value('company_address'), 'companyTaxId' => $settings->value('tax_id'), 'companyTaxBranchCode' => $salesRfq->branch?->tax_branch_code, 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'), 'decimalPlaces' => (int) ($settings->value('tax_decimal_places') ?? 2)]);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.rawurlencode($salesRfq->document_number).'.pdf"']);
    }
}
