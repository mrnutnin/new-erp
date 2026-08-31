<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Spreadsheet\RawWorkbookImport;
use App\Modules\Platform\Spreadsheet\WorkbookExport;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SpreadsheetService
{
    public function download(string $filename, array $sheets): BinaryFileResponse
    {
        return Excel::download(new WorkbookExport($sheets), $filename, ExcelFormat::XLSX);
    }

    public function readXlsx(UploadedFile $file, array $sheetNames): array
    {
        return Excel::toArray(new RawWorkbookImport($sheetNames), $file, null, ExcelFormat::XLSX);
    }
}
