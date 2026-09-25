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
        if (str_starts_with($profile, 'label_')) {
            $fontOptions = array_intersect_key((array) ($profiles['a4'] ?? []), array_flip(['fontDir', 'fontdata', 'default_font']));
            $options = array_merge($fontOptions, $options);
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
        if (! str_starts_with($profile, 'label_')) {
            $pdf->SetHTMLFooter('<div style="border-top:1px solid #cbd5e1;color:#64748b;font-family:notosansthai,sans-serif;font-size:8pt;padding-top:4px;text-align:right">หน้า {PAGENO} / {nbpg}</div>');
        }
        // Keep the shared markup, but replace browser-only CSS with mPDF-safe primitives.
        $pdfHtml = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $html) ?? $html;
        $pdfHtml = <<<'HTML'
<style>
.document-render { box-sizing:border-box; width:100%; font-family:notosansthai,sans-serif; font-size:10pt; line-height:1.4; color:#172033; background:#fff; }
.document-render h1 { font-size:14pt; line-height:1.2; margin:0 0 3px; }
.document-render h2 { font-size:16pt; line-height:1.2; margin:0; text-align:right; }
.document-render h3 { font-size:11pt; margin:16px 0 6px; }
.document-render .text-end { text-align:right; }
.document-render .right { text-align:right; }
.document-render .center { text-align:center; }
.document-render .small { font-size:9pt; }
.document-render .fw-bold,.document-render .fw-semibold { font-weight:bold; }
.document-render .text-secondary { color:#687386; }
.document-render .muted { color:#687386; }
.document-render .mb-2 { margin-bottom:8px; }
.document-render .mb-4 { margin-bottom:16px; }
.document-render .mt-3 { margin-top:12px; }
.document-render .pt-3 { padding-top:12px; }
.document-render table { width:100%; table-layout:fixed; border-collapse:collapse; }
.document-render thead { display:table-header-group; }
.document-render tr { page-break-inside:avoid; }
.document-render th,.document-render td { border:1px solid #cbd5e1; padding:6px 7px; vertical-align:top; }
.document-render th { background:#e9edf2; font-size:9pt; font-weight:bold; }
.document-render .pdf-meta td { background:#f8fafc; }
.document-render .doc-meta td { background:#f8fafc; }
.document-render .pdf-total td { background:#f1f5f9; font-weight:bold; }
.document-render .total td { background:#f1f5f9; font-weight:bold; }
.document-render .pdf-watermark { color:#991b1b; font-size:18pt; font-weight:bold; text-align:center; }
.document-render .pdf-note { color:#475569; font-size:9pt; }
.document-render .pdf-control { color:#64748b; font-size:8pt; margin-top:12px; }
.document-render .logo { height:52px; max-width:170px; }
.document-render .pdf-header { border:0; margin-bottom:10px; }
.document-render .pdf-header td { border:0; padding:0; vertical-align:top; }
.document-render .pdf-header-company { width:58%; padding-right:12px !important; }
.document-render .pdf-header-title { width:42%; text-align:right; }
.document-render .pdf-header-title h2 { font-size:17pt; }
.document-render .pdf-label { color:#64748b; font-size:8pt; }
.document-render .pdf-value { font-weight:bold; }
.document-render .pdf-issuer,.document-render .pdf-party,.document-render .pdf-context { margin-bottom:10px; }
.document-render .pdf-issuer td,.document-render .pdf-party td,.document-render .pdf-context td { background:#f8fafc; }
.document-render .pdf-party td { width:50%; }
.document-render .pdf-context td { width:33.333%; }
.document-render .pdf-product th { text-align:center; }
.document-render .pdf-product .description { width:46%; }
.document-render .pdf-product .narrow { width:8%; }
.document-render .pdf-product .money { width:11%; }
.document-render .pdf-product td { word-wrap:break-word; overflow-wrap:break-word; }
.document-render .pdf-total-summary { width:43%; margin:10px 0 0 auto; }
.document-render .pdf-total-summary td { background:#f8fafc; }
.document-render .pdf-total-summary td:first-child { width:65%; }
.document-render .pdf-total-summary .total td { background:#e9edf2; }
.document-render .pdf-payment { margin-top:12px; }
.document-render .pdf-payment th { text-align:center; }
.document-render .pdf-signatures { border:0; margin-top:24mm; page-break-inside:avoid; }
.document-render .pdf-signatures td { border:0; width:50%; text-align:center; padding:0 10mm; }
.document-render .pdf-sign-line { height:14mm; margin-bottom:3mm; }
.document-render .pdf-internal-document { font-size:9.5pt; line-height:1.5; }
.document-render .pdf-internal-document table { font-size:9.5pt; width:100%; }
.document-render .pdf-internal-document td { font-size:9.5pt; color:#24292e; }
.document-render .pdf-internal-document .pdf-header { margin-bottom:8mm; }
.document-render .pdf-internal-document .invoice-logo { width:16%; padding-right:5mm; }
.document-render .pdf-internal-document .pdf-header-company { width:60%; padding-right:6mm; }
.document-render .pdf-internal-document.with-logo .pdf-header-company { width:44%; }
.document-render .pdf-internal-document .pdf-header-title { width:40%; text-align:right; }
.document-render .pdf-internal-document h1 { font-size:12pt; margin-bottom:3mm; }
.document-render .pdf-internal-document .pdf-copy { font-size:8.5pt; color:#737b83; margin-bottom:2mm; }
.document-render .pdf-internal-document .pdf-header-title h2 { font-size:16pt; line-height:1.4; color:#20252b; }
.document-render .pdf-internal-document .invoice-number { font-size:12pt; font-weight:bold; margin-top:3mm; }
.document-render .pdf-internal-document .pdf-party { margin:6mm 0; }
.document-render .pdf-internal-document .pdf-party td { background:#fff; border-top:.2mm solid #d8dcdf; border-bottom:.2mm solid #d8dcdf; padding:4mm 3mm; }
.document-render .pdf-internal-document .pdf-label { font-size:8.5pt; color:#737b83; margin-bottom:2mm; }
.document-render .pdf-internal-document .pdf-value { font-size:10pt; font-weight:bold; }
.document-render .pdf-internal-document .pdf-meta { margin-bottom:5mm; }
.document-render .pdf-internal-document .pdf-meta td { background:#fff; border:.2mm solid #e8eaec; padding:3mm; }
.document-render .pdf-internal-document h3 { font-size:11pt; margin:5mm 0 2mm; color:#363d44; }
.document-render .pdf-internal-document .pdf-product th { font-size:8.5pt; background:#edf0f2; color:#363d44; border:0; padding:3mm 1.5mm; text-align:center; }
.document-render .pdf-internal-document .pdf-product td { padding:3mm 1.5mm; border:0; border-bottom:.2mm solid #e8eaec; font-size:9pt; }
.document-render .pdf-internal-document .pdf-total-summary { margin:4mm 0 0 auto; width:48%; }
.document-render .pdf-internal-document .pdf-total-summary td { background:#fff; border:0; border-top:.2mm solid #d8dcdf; padding:2mm; font-size:9pt; }
.document-render .pdf-internal-document .pdf-total-summary .total td { background:#edf0f2; font-size:11pt; font-weight:bold; padding:3mm 2mm; }
.document-render .pdf-internal-document .pdf-note { font-size:9pt; border-top:.2mm solid #e2e5e8; border-bottom:.2mm solid #e2e5e8; padding:3mm 0; margin:4mm 0 0; }
.document-render .pdf-internal-document .pdf-control { font-size:8.5pt; color:#737b83; margin:4mm 0 0; }
.document-render .pdf-tax-invoice table { font-size:9.5pt; width:100%; }
.document-render .pdf-tax-invoice td { font-size:9.5pt; color:#24292e; border:0; background:#fff; padding:0; vertical-align:top; }
.document-render .pdf-tax-invoice { font-size:9.5pt; line-height:1.5; }
.document-render .pdf-tax-invoice .muted { color:#606870; }
.document-render .pdf-tax-invoice .pdf-header { margin-bottom:8mm; }
.document-render .pdf-tax-invoice .pdf-header-company { padding-right:6mm; }
.document-render .pdf-tax-invoice h1 { font-size:12pt; margin-bottom:3mm; }
.document-render .pdf-tax-invoice .pdf-header-title { text-align:right; }
.document-render .pdf-tax-invoice .pdf-header-title h2 { font-size:16pt; line-height:1.4; color:#20252b; }
.document-render .pdf-tax-invoice .pdf-copy { font-size:8.5pt; color:#737b83; margin-bottom:2mm; }
.document-render .pdf-tax-invoice .invoice-number { font-size:12pt; font-weight:bold; margin-top:3mm; }
.document-render .pdf-tax-invoice .pdf-party { margin-bottom:6mm; }
.document-render .pdf-tax-invoice .pdf-party td { background:#fff; }
.document-render .pdf-tax-invoice .invoice-buyer { border-top:0.2mm solid #d8dcdf; border-bottom:0.2mm solid #d8dcdf; padding:5mm 8mm 5mm 0; }
.document-render .pdf-tax-invoice .invoice-details { border-top:0.2mm solid #d8dcdf; border-bottom:0.2mm solid #d8dcdf; padding:5mm 0; }
.document-render .pdf-tax-invoice .pdf-label { font-size:8.5pt; color:#737b83; margin-bottom:2mm; }
.document-render .pdf-tax-invoice .pdf-value { font-size:10pt; font-weight:bold; }
.document-render .pdf-tax-invoice .invoice-meta td { padding:1mm 0; font-size:9pt; }
.document-render .pdf-tax-invoice .pdf-product th { font-size:8.5pt; background:#edf0f2; color:#363d44; border:0; padding:3mm 1.5mm; text-align:center; }
.document-render .pdf-tax-invoice .pdf-product th.right { text-align:right; }
.document-render .pdf-tax-invoice .pdf-product th.invoice-item-heading { text-align:left; }
.document-render .pdf-tax-invoice .pdf-product td { padding:3mm 1.5mm; border-bottom:0.2mm solid #e8eaec; font-size:9pt; }
.document-render .pdf-tax-invoice .center { text-align:center; }
.document-render .pdf-tax-invoice .invoice-item-code { color:#777f87; font-size:8.5pt; }
.document-render .pdf-tax-invoice .pdf-footer { margin:0; }
.document-render .pdf-tax-invoice .pdf-footer-payment { border-top:0.2mm solid #d8dcdf; padding:4mm 10mm 0 0; }
.document-render .pdf-tax-invoice .pdf-footer-total { border-top:0.2mm solid #d8dcdf; padding:4mm 0 0; }
.document-render .pdf-tax-invoice .pdf-footer-heading { font-weight:bold; font-size:9.5pt; }
.document-render .pdf-tax-invoice .invoice-tender { font-size:9pt; margin:2mm 0; }
.document-render .pdf-tax-invoice .pdf-total-summary { margin:0; width:100%; }
.document-render .pdf-tax-invoice .pdf-total-summary td { padding:1.5mm 2mm; font-size:9pt; }
.document-render .pdf-tax-invoice .pdf-total-summary .total td { background:#edf0f2; font-size:11pt; font-weight:bold; padding:3mm 2mm; }
.document-render .pdf-tax-invoice .pdf-total-summary .invoice-net td { border-top:0.2mm solid #cbd0d5; font-weight:bold; }
.document-render .pdf-tax-invoice .invoice-payments { margin:3mm 0; }
.document-render .pdf-tax-invoice .invoice-payments th { background:#fff; border:0; border-bottom:0.2mm solid #d8dcdf; text-align:left; font-size:9pt; padding:2mm 1mm; }
.document-render .pdf-tax-invoice .invoice-payments th.right { text-align:right; }
.document-render .pdf-tax-invoice .invoice-payments td { border-bottom:0.2mm solid #eceeef; padding:2mm 1mm; font-size:9pt; }
.document-render .pdf-tax-invoice .invoice-payments td.right { width:28%; white-space:nowrap; }
.document-render .pdf-tax-invoice .pdf-note { font-size:9pt; border-top:0.2mm solid #e2e5e8; border-bottom:0.2mm solid #e2e5e8; padding:3mm 0; margin:4mm 0 0; }
.document-render .pdf-tax-invoice .pdf-signatures { margin:0; }
.document-render .pdf-tax-invoice .pdf-signatures td { width:45%; text-align:center; font-size:9pt; padding:0; }
.document-render .pdf-tax-invoice .pdf-signatures td.invoice-sign-gap { width:10%; }
.document-render .pdf-tax-invoice .pdf-signatures.pdf-signatures-three td { width:31.333%; }
.document-render .pdf-tax-invoice .pdf-signatures.pdf-signatures-three td.invoice-sign-gap { width:3%; }
.document-render .pdf-tax-invoice .invoice-sign-space { height:12mm; }
.document-render .pdf-signature-image { display:block; max-height:11mm; max-width:42mm; margin:0 auto; }
.document-render .invoice-sign-position { color:#737b83; font-size:8pt; }
.document-render .pdf-tax-invoice .invoice-sign-date { color:#737b83; margin-top:2mm; }
.document-render .pdf-tax-invoice .pdf-control { font-size:8.5pt; color:#737b83; margin:4mm 0 0; }
.document-render .pdf-tax-invoice.pdf-readable { font-size:10.5pt; }
.document-render .pdf-tax-invoice.pdf-readable table,.document-render .pdf-tax-invoice.pdf-readable td { font-size:10pt; }
.document-render .pdf-tax-invoice.pdf-readable .pdf-label,.document-render .pdf-tax-invoice.pdf-readable .pdf-copy,.document-render .pdf-tax-invoice.pdf-readable .invoice-item-code { font-size:9pt; }
.document-render .pdf-tax-invoice.pdf-readable .invoice-meta td,.document-render .pdf-tax-invoice.pdf-readable .pdf-product td,.document-render .pdf-tax-invoice.pdf-readable .pdf-total-summary td { font-size:9.5pt; }
.document-render .pdf-tax-invoice.pdf-readable .pdf-product th { font-size:9pt; }
.document-render .pdf-tax-invoice.pdf-readable .pdf-footer-heading,.document-render .pdf-tax-invoice.pdf-readable .pdf-signatures td { font-size:10pt; }
.document-render .pdf-tax-invoice.pdf-readable .pdf-control { font-size:9.5pt; }
.document-render .pdf-sticker { box-sizing:border-box; width:100%; overflow:hidden; text-align:center; line-height:1.05; }
.document-render .pdf-sticker table,.document-render .pdf-sticker td { border:0; padding:0; vertical-align:middle; }
.document-render .pdf-sticker .sticker-company { overflow:hidden; font-size:6pt; font-weight:bold; white-space:nowrap; }
.document-render .pdf-sticker .sticker-name { margin:.45mm 0 .25mm; font-size:8pt; font-weight:bold; line-height:1.05; overflow-wrap:break-word; word-wrap:break-word; }
.document-render .pdf-sticker .sticker-name-long { font-size:6.5pt; }
.document-render .pdf-sticker .sticker-meta { overflow:hidden; color:#374151; font-size:5.5pt; white-space:nowrap; }
.document-render .pdf-sticker .sticker-code { margin-top:.25mm; font-family:monospace; font-size:6.5pt; font-weight:bold; line-height:1; overflow-wrap:break-word; word-wrap:break-word; }
.document-render .pdf-sticker .sticker-code-long { font-size:5pt; }
.document-render .pdf-sticker .sticker-code-very-long { font-size:4.2pt; }
.document-render .pdf-sticker .sticker-symbol { padding-top:.55mm; text-align:center; }
.document-render .pdf-sticker .sticker-barcode-cell { width:68%; padding-right:1mm; }
.document-render .pdf-sticker .sticker-qr-cell { width:32%; }
.document-render .pdf-sticker-large .sticker-company { font-size:7.5pt; }
.document-render .pdf-sticker-large .sticker-name { margin:.8mm 0 .4mm; font-size:10pt; }
.document-render .pdf-sticker-large .sticker-name-long { font-size:8pt; }
.document-render .pdf-sticker-large .sticker-meta { font-size:7pt; }
.document-render .pdf-sticker-large .sticker-code { font-size:7.5pt; }
.document-render .pdf-sticker-large .sticker-code-long { font-size:6pt; }
.document-render .pdf-sticker-large .sticker-code-very-long { font-size:5pt; }
.document-render .pdf-label-sheet,.document-render .pdf-label-sheet td { border:0; padding:0; }
.document-render .pdf-label-sheet { width:100%; table-layout:fixed; border-collapse:collapse; }
.document-render .pdf-label-sheet td { box-sizing:border-box; padding:1.1mm; vertical-align:middle; }
.document-render .pdf-label-cell { box-sizing:border-box; width:100%; padding:1.5mm; overflow:hidden; border:.2mm dashed #cbd5e1; }
</style>
HTML
        .'<main class="document-render">'.$pdfHtml.'</main>';
        if (str_contains($pdfHtml, '<!-- invoice-closing -->')) {
            [$body, $closing] = explode('<!-- invoice-closing -->', $pdfHtml, 2);
            $styles = substr($pdfHtml, 0, strpos($pdfHtml, '</style>') + 8);
            // Measure the closing block with the same page width and fonts.
            $measure = new Mpdf(array_merge($options, [
                'tempDir' => $tempDir, 'mode' => 'utf-8',
                'autoScriptToLang' => true, 'autoLangToFont' => true,
            ]));
            $measure->WriteHTML($styles.'<main class="document-render">'.$closing);
            $height = $measure->y - $measure->tMargin;
            $singlePageClosing = $measure->page === 1;
            unset($measure);

            $pdf->WriteHTML($body.'</main>');
            $bottom = $pdf->h - $pdf->bMargin - 2;
            if ($singlePageClosing) {
                if ($pdf->y + 6 + $height > $bottom) {
                    $pdf->AddPage();
                }
                $pdf->SetY(max($pdf->y + 6, $bottom - $height));
            }
            $pdf->WriteHTML('<main class="document-render">'.$closing);
        } else {
            $pdf->WriteHTML($pdfHtml);
        }

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    public function renderView(string $view, array $data = [], string $profile = 'a4'): string
    {
        return $this->render(view($view, $data)->render(), $profile);
    }
}
