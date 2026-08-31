<?php

namespace App\Modules\Platform\Spreadsheet;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

final class RawWorkbookImport implements WithMultipleSheets
{
    public function __construct(private readonly array $sheetNames) {}

    public function sheets(): array
    {
        return array_fill_keys($this->sheetNames, new RawSheetImport);
    }
}
