<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Pos\Models\SalesIntake;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class SalesIntakePdfController extends Controller
{
    public function show(Request $request, SalesIntake $salesIntake, DocumentPdfRenderer $renderer, GlobalSettings $settings)
    {
        abort_unless((int) $salesIntake->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
        $salesIntake->load(['lines.item', 'lines.uom', 'preparedBy']);
        $logoPath = $settings->value('logo_path');
        $logo = $logoPath && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->path($logoPath) : null;
        $bytes = $renderer->renderView('Pos::pdf.sales-intake', [
            'intake' => $salesIntake, 'sourceIntake' => $salesIntake, 'logo' => $logo, 'companyName' => $settings->value('company_name') ?: 'บริษัท',
            'companyAddress' => $settings->value('company_address'), 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
            'decimalPlaces' => (int) ($salesIntake->tax_decimal_places ?? $settings->value('tax_decimal_places') ?? 2),
        ]);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.rawurlencode($salesIntake->document_number).'.pdf"']);
    }
}
