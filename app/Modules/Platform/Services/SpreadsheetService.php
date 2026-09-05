<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Spreadsheet\RawWorkbookImport;
use App\Modules\Platform\Spreadsheet\ChunkReadFilter;
use App\Modules\Platform\Spreadsheet\WorkbookExport;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    /**
     * Read one worksheet in bounded row chunks. The callback receives the rows
     * and the first Excel row number in that chunk, preserving source row refs.
     */
    public function readXlsxInChunks(UploadedFile $file, string $sheetName, int $chunkSize, callable $onChunk): array
    {
        $path = $file->getRealPath();
        $headers = [];

        for ($startRow = 1; ; $startRow += $chunkSize) {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly([$sheetName]);
            $reader->setReadFilter(new ChunkReadFilter($startRow, $startRow + $chunkSize - 1));
            $spreadsheet = $reader->load($path);
            $worksheet = $spreadsheet->getSheetByName($sheetName);

            if (! $worksheet) break;
            $rows = array_values($worksheet->toArray(null, true, true, false));
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $worksheet, $reader);

            if ($startRow === 1) {
                $headers = array_values(array_shift($rows) ?? []);
                $dataStartRow = 2;
            } else {
                $dataStartRow = $startRow;
            }

            if ($rows !== []) $onChunk($rows, $dataStartRow);
            if (count($rows) < $chunkSize - ($startRow === 1 ? 1 : 0)) break;
        }

        return $headers;
    }
}
