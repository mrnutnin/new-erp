<?php

namespace App\Modules\Platform\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

final class ChunkReadFilter implements IReadFilter
{
    public function __construct(private readonly int $startRow, private readonly int $endRow) {}

    public function readCell($column, $row, $worksheetName = ''): bool
    {
        return $row >= $this->startRow && $row <= $this->endRow;
    }
}
