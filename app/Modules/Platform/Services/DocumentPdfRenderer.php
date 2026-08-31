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
        $pdf->WriteHTML($html);

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    public function renderView(string $view, array $data = [], string $profile = 'a4'): string
    {
        return $this->render(view($view, $data)->render(), $profile);
    }
}
