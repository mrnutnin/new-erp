<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AssetPerformanceReadinessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_asset_tables_use_server_side_datatables_and_deferred_rendering(): void
    {
        foreach (glob($this->root.'/app/Modules/Asset/Views/**/*.blade.php') ?: [] as $view) {
            $source = file_get_contents($view);
            if (str_contains($source, 'DataTable(')) {
                self::assertStringContainsString('window.erpDataTableDefaults', $source, $view);
            }
        }

        $defaults = file_get_contents($this->root.'/public/js/datatables.js');
        self::assertStringContainsString('serverSide: true', $defaults);
        self::assertStringContainsString('deferRender: true', $defaults);
    }

    public function test_asset_exports_stream_in_chunks_and_import_has_a_bounded_batch_size(): void
    {
        $accounting = file_get_contents($this->root.'/app/Modules/Accounting/Controllers/AccountingReportController.php');
        $import = file_get_contents($this->root.'/app/Modules/Asset/Controllers/AssetImportController.php');

        self::assertStringContainsString('->lazy(500)', $accounting);
        self::assertStringContainsString("count(\$rows) > 2000", $import);
        self::assertStringContainsString('array_slice($rows, 0, 2000)', $import);
    }

    public function test_asset_filter_indexes_cover_branch_dates_and_workflow_statuses(): void
    {
        $migrations = implode("\n", array_map('file_get_contents', glob($this->root.'/database/migrations/*asset*.php') ?: []));

        foreach (["['branch_id', 'status']", "['branch_id', 'acquisition_date']", "['branch_id', 'event_type', 'event_date']", "['branch_id', 'status', 'document_date']", "['branch_id', 'status', 'run_through_date']"] as $index) {
            self::assertStringContainsString($index, $migrations, $index);
        }
    }
}
