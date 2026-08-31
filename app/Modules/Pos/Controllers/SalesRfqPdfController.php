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
        $salesRfq->load('lines.item', 'lines.uom', 'sourceIntake.preparedBy');
        $path = $settings->value('logo_path');
        $logo = $path && Storage::disk('public')->exists($path) ? Storage::disk('public')->path($path) : null;
        $bytes = $renderer->renderView('Pos::pdf.sales-rfq', ['salesRfq' => $salesRfq, 'sourceIntake' => $salesRfq->sourceIntake, 'sourceLabel' => $salesRfq->sourceIntake?->document_number, 'logo' => $logo, 'companyName' => $settings->value('company_name'), 'companyAddress' => $settings->value('company_address'), 'dateFormat' => (string) $settings->value('date_format')]);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.rawurlencode($salesRfq->document_number).'.pdf"']);
    }
}
