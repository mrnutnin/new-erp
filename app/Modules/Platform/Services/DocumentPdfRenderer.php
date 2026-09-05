<?php

namespace App\Modules\Platform\Services;

use InvalidArgumentException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class DocumentPdfRenderer
{
    public function render(string $html, string $profile = 'a4'): string
    {
        $profiles = (array) config('erp.pdf.profiles', []);
        $options = $profiles[$profile] ?? null;
        if (! is_array($options)) {
            throw new InvalidArgumentException("Unknown PDF profile [{$profile}].");
        }

        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $pdf = new Mpdf(array_merge($options, [
            'tempDir' => $tempDir,
            'mode' => 'utf-8',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]));
        // Keep the shared markup, but replace browser-only CSS with mPDF-safe primitives.
        $pdfHtml = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $html) ?? $html;
        $pdfHtml = <<<'HTML'
<style>
.document-render { box-sizing:border-box; width:100%; padding:24px; font-family:Arial,sans-serif; font-size:10pt; line-height:1.35; color:#172033; background:#fff; border:1px solid #cbd5e1; }
.document-render .text-end { text-align:right; }
.document-render .small { font-size:9pt; }
.document-render .fw-bold,.document-render .fw-semibold { font-weight:bold; }
.document-render .text-secondary { color:#687386; }
.document-render .mb-2 { margin-bottom:8px; }
.document-render .mb-4 { margin-bottom:16px; }
.document-render .mt-3 { margin-top:12px; }
.document-render .pt-3 { padding-top:12px; }
.document-render table { width:100%; table-layout:fixed; border-collapse:collapse; }
.document-render thead { display:table-header-group; }
.document-render tr { page-break-inside:avoid; }
</style>
HTML
        .$pdfHtml;
        $pdf->WriteHTML($pdfHtml);

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    public function renderView(string $view, array $data = [], string $profile = 'a4'): string
    {
        return $this->render(view($view, $data)->render(), $profile);
    }
}
