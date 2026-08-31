<?php

namespace App\Modules\Platform\Spreadsheet;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class WorkbookExport implements WithMultipleSheets
{
    public function __construct(private readonly array $sheets) {}

    public function sheets(): array
    {
        return array_map(
            fn (array $sheet) => new ArraySheetExport(
                $sheet['title'],
                $sheet['headings'],
                $sheet['rows'],
                $sheet['formats'] ?? [],
            ),
            $this->sheets,
        );
    }
}
