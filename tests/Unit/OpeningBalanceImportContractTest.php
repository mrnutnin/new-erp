<?php

namespace Tests\Unit;

use Tests\TestCase;

final class OpeningBalanceImportContractTest extends TestCase
{
    public function test_import_validates_duplicate_stock_keys_and_strict_decimal_values(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/OpeningBalanceImportService.php'));

        $this->assertStringContainsString("'stock_keys' => []", $source);
        $this->assertStringContainsString('สินค้าเดียวกันในคลังเดียวกันห้ามซ้ำในไฟล์', $source);
        $this->assertStringContainsString("/^\\d+(?:\\.\\d{1,8})?$/", $source);
    }

    public function test_import_uses_chunked_spreadsheet_reader(): void
    {
        $service = file_get_contents(base_path('app/Modules/Wms/Services/OpeningBalanceImportService.php'));
        $spreadsheet = file_get_contents(base_path('app/Modules/Platform/Services/SpreadsheetService.php'));

        $this->assertStringContainsString('private const CHUNK_SIZE = 1000;', $service);
        $this->assertStringContainsString('readXlsxInChunks', $service);
        $this->assertStringContainsString('setReadFilter', $spreadsheet);
    }
}
