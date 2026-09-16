<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Pos\Models\BillingNote;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class BillingNotePdfController extends Controller
{
    public function show(Request $request, BillingNote $billingNote, DocumentPdfRenderer $renderer, GlobalSettings $settings)
    {
        abort_unless((int) $billingNote->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
        $billingNote->load(['branch', 'party', 'lines.salesDocument', 'lines.physicalSale']);
        $path = $settings->value('logo_path');
        $logo = $path && Storage::disk('public')->exists($path) ? Storage::disk('public')->path($path) : null;
        $bytes = $renderer->renderView('Pos::pdf.billing-note', ['note' => $billingNote, 'logo' => $logo, 'companyName' => $settings->value('company_name'), 'companyAddress' => $billingNote->branch?->tax_address ?: $settings->value('company_address'), 'companyTaxId' => $settings->value('tax_id'), 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y')]);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.rawurlencode($billingNote->document_number).'.pdf"']);
    }
}
