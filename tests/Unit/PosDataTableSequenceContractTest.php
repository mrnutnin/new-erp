<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PosDataTableSequenceContractTest extends TestCase
{
    public function test_every_pos_data_table_has_a_first_sequence_column(): void
    {
        $root = dirname(__DIR__, 2);
        $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Modules/Pos/Views'));
        $tableCount = 0;

        foreach ($views as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $view = file_get_contents($file->getPathname());
            if (! str_contains($view, 'DataTable(')) {
                continue;
            }

            $tables = preg_match_all('/<thead\b[^>]*>\s*<tr\b[^>]*>/i', $view);
            $tableCount += $tables;
            self::assertSame($tables, preg_match_all('/<thead\b[^>]*>\s*<tr\b[^>]*>\s*<th[^>]*>ลำดับ<\/th>/i', $view), $file->getPathname());
            self::assertDoesNotMatchRegularExpression('/\bcolumns\s*:\s*\[(?!\s*window\.erpRowNumberColumn\(\))/', $view, $file->getPathname());
            self::assertStringContainsString('erpRowNumberColumn', $view, $file->getPathname());
        }

        self::assertSame(31, $tableCount);

        $helper = file_get_contents($root.'/public/js/datatables.js');
        self::assertStringContainsString('orderable: false', $helper);
        self::assertStringContainsString('searchable: false', $helper);
        self::assertStringContainsString('meta.settings._iDisplayStart + meta.row + 1', $helper);
    }
}
