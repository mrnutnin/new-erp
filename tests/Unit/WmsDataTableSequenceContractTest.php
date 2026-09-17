<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class WmsDataTableSequenceContractTest extends TestCase
{
    public function test_every_wms_data_table_has_a_first_sequence_column(): void
    {
        $root = dirname(__DIR__, 2);
        $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Modules/Wms/Views'));
        $tableCount = 0;

        foreach ($views as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $view = file_get_contents($file->getPathname());
            $dataTables = substr_count($view, 'DataTable(');
            if ($dataTables === 0) {
                continue;
            }

            $tableCount += $dataTables;
            self::assertSame($dataTables, preg_match_all('/<th[^>]*>ลำดับ<\/th>/', $view), $file->getPathname());
            self::assertSame($dataTables, substr_count($view, 'erpRowNumberColumn') + substr_count($view, '_iDisplayStart'), $file->getPathname());
        }

        self::assertGreaterThan(0, $tableCount);
        self::assertStringContainsString('window.erpRowNumberColumn', file_get_contents($root.'/public/js/datatables.js'));
    }

    public function test_server_side_tables_do_not_preorder_before_datatables_sorting(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Wms/Controllers/';
        $checks = [
            'InventoryAdjustmentController.php' => "\$query->latest('id')",
            'StockCountController.php' => "\$query->latest('id')",
            'OpeningBalanceController.php' => 'DataTables::eloquent($query->latest',
            'ProductionFinishedReceiptController.php' => 'DataTables::eloquent($query->latest',
            'TransferController.php' => "->withSum('lines as planned_base_quantity_total', 'planned_base_quantity')\n            ->orderByDesc",
            'EntryController.php' => "select('wms_stock_movements.id', 'wms_stock_movements.business_date', 'wms_stock_movements.movement_type', 'wms_stock_movements.direction', 'wms_stock_movements.base_quantity', 'wms_stock_movements.source_reference', 'i.code as item_code', 'i.name as item_name')->latest",
        ];

        foreach ($checks as $file => $fixedOrder) {
            self::assertStringNotContainsString($fixedOrder, file_get_contents($root.$file), $file);
        }
    }
}
