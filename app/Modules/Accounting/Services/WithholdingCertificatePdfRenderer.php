<?php

namespace App\Modules\Accounting\Services;

use Carbon\Carbon;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class WithholdingCertificatePdfRenderer
{
    private const PAGE_WIDTH = 595.0;

    private const PAGE_HEIGHT = 842.0;

    /** Centers of the official 1-4-5-2-1 tax-ID boxes, relative to the field's x coordinate (PDF points). */
    private const TAX_ID_BOX_CENTERS = [5.2, 23.2, 35.2, 47.3, 59.4, 78.0, 89.9, 101.7, 113.8, 126.2, 143.9, 156.2, 175.4];

    /** @var array<string, array{0: float, 1: float, 2: float, 3: float}> */
    private const FIELDS = [
        'book_no' => [519.402, 781.206, 40.0, 16.0],
        'run_no' => [519.337, 764.666, 40.667, 16.0],
        'payer_id' => [374.67, 743.999, 183.776, 14.667],
        'payer_name' => [53.9002, 729.073, 262.4008, 16.127],
        'payer_address' => [60.8002, 705.735, 489.6018, 15.824],
        'payee_id' => [375.336, 675.592, 182.303, 14.667],
        'payee_name' => [53.2492, 657.605, 261.6008, 13.5],
        'payee_address' => [59.2002, 627.026, 490.4018, 16.273],
        'filing_order' => [77.0158, 600.592, 61.7822, 15.333],
        'pnd1g' => [209.335, 602.543, 12.667, 12.667],
        'pnd1g_special' => [288.002, 602.543, 12.667, 12.667],
        'pnd2' => [394.67, 601.876, 12.666, 12.667],
        'pnd3' => [471.337, 601.876, 12.667, 12.667],
        'pnd2g' => [209.335, 583.876, 12.667, 12.667],
        'pnd3g' => [287.336, 583.876, 12.666, 12.667],
        'pnd53' => [394.67, 583.876, 12.666, 12.667],
        'income_date' => [326.503, 216.34, 76.24, 14.088],
        'income_amount' => [409.372, 216.34, 79.554, 14.088],
        'income_tax' => [496.384, 214.683, 64.638, 14.916],
        'total_amount' => [409.377, 180.055, 79.033, 15.455],
        'total_tax' => [495.516, 180.425, 64.517, 15.522],
        'tax_words' => [184.57, 157.649, 372.969, 19.194],
        'withheld' => [82.5, 119.143, 11.5, 11.693],
        'issue_day' => [341.819, 72.1061, 24.218, 15.2001],
        'issue_month' => [363.637, 70.6061, 64.801, 16.7001],
        'issue_year' => [429.311, 72.2581, 40.8, 14.3936],
    ];

    public function render(object $row, object $company, string $formType, string $certificateNumber, string $payerAddress, string $taxAmountText): string
    {
        $options = (array) config('erp.pdf.profiles.a4', []);
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $pdf = new Mpdf(array_merge($options, [
            'tempDir' => $tempDir,
            'margin_top' => 0, 'margin_right' => 0, 'margin_bottom' => 0, 'margin_left' => 0,
            'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]));
        $pdf->setSourceFile(resource_path('pdf/forms/rd-withholding-certificate-50-tawi.pdf'));
        $template = $pdf->importPage(1);
        $pdf->AddPageByArray(['margin-left' => 0, 'margin-right' => 0, 'margin-top' => 0, 'margin-bottom' => 0]);
        $pdf->useTemplate($template, 0, 0, 210, 297);

        $date = Carbon::parse($row->settlement_date);
        $thaiDate = $date->format('d/m/').($date->year + 543);
        $payerName = trim((string) $company->company_name.' ('.(string) $row->branch_name.')');

        $this->write($pdf, 'run_no', $certificateNumber, 6.5);
        $this->writeDigits($pdf, 'payer_id', (string) $company->tax_id);
        $this->write($pdf, 'payer_name', $payerName);
        $this->write($pdf, 'payer_address', $payerAddress);
        $this->writeDigits($pdf, 'payee_id', (string) $row->tax_id);
        $this->write($pdf, 'payee_name', (string) $row->party_name);
        $this->write($pdf, 'payee_address', (string) $row->party_address);
        $this->check($pdf, $formType === 'PND3' ? 'pnd3' : 'pnd53');
        $this->write($pdf, 'income_date', $thaiDate, 7.2, 'C');
        $this->write($pdf, 'income_amount', number_format((float) $row->tax_base, 2), 7.5, 'R');
        $this->write($pdf, 'income_tax', number_format((float) $row->tax_amount, 2), 7.5, 'R');
        $this->write($pdf, 'total_amount', number_format((float) $row->tax_base, 2), 7.5, 'R');
        $this->write($pdf, 'total_tax', number_format((float) $row->tax_amount, 2), 7.5, 'R');
        $this->write($pdf, 'tax_words', '('.$taxAmountText.')', 7.5);
        $this->check($pdf, 'withheld');
        $this->write($pdf, 'issue_day', $date->format('d'), 7.5, 'C');
        $this->write($pdf, 'issue_month', $this->thaiMonth((int) $date->format('n')), 7.5, 'C');
        $this->write($pdf, 'issue_year', (string) ($date->year + 543), 7.5, 'C');

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    private function write(Mpdf $pdf, string $field, string $text, float $fontSize = 7.5, string $align = 'L'): void
    {
        [$x, $y, $width, $height] = $this->rect($field);
        $pdf->SetFont('notosansthai', '', $fontSize);
        while ($fontSize > 5.2 && $pdf->GetStringWidth($text) > $width - 1) {
            $pdf->SetFontSize($fontSize -= 0.2);
        }
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, $height, $text, 0, 0, $align);
    }

    private function writeDigits(Mpdf $pdf, string $field, string $value): void
    {
        $digits = str_split(preg_replace('/\D/', '', $value));
        [$x, $y, , $height] = $this->rect($field);
        $cellWidth = 12.0 * 210 / self::PAGE_WIDTH;
        $pdf->SetFont('notosansthai', '', 7.2);
        foreach (array_slice($digits, 0, 13) as $index => $digit) {
            $center = $x + (self::TAX_ID_BOX_CENTERS[$index] * 210 / self::PAGE_WIDTH);
            $pdf->SetXY($center - ($cellWidth / 2), $y);
            $pdf->Cell($cellWidth, $height, $digit, 0, 0, 'C');
        }
    }

    private function check(Mpdf $pdf, string $field): void
    {
        [$x, $y, $width, $height] = $this->rect($field);
        $pdf->SetFont('notosansthai', 'B', 9);
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, $height, 'X', 0, 0, 'C');
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private function rect(string $field): array
    {
        [$x, $y, $width, $height] = self::FIELDS[$field];

        return [
            $x * 210 / self::PAGE_WIDTH,
            (self::PAGE_HEIGHT - $y - $height) * 297 / self::PAGE_HEIGHT,
            $width * 210 / self::PAGE_WIDTH,
            $height * 297 / self::PAGE_HEIGHT,
        ];
    }

    private function thaiMonth(int $month): string
    {
        return ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'][$month];
    }
}
