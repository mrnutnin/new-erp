<?php

namespace Tests\Unit;

use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesReturn;
use App\Modules\Pos\Models\SalesReturnLine;
use PHPUnit\Framework\TestCase;

final class PhysicalSaleCancellationPersistenceContractTest extends TestCase
{
    public function test_models_and_migration_keep_immutable_cancellation_linkages(): void
    {
        $migration = file_get_contents(__DIR__.'/../../database/migrations/2026_08_29_100000_add_full_cancellation_links_to_pos_sales.php');

        self::assertContains('cancellation_return_id', (new PhysicalSale)->getFillable());
        self::assertContains('reversal_key', (new SalesReturn)->getFillable());
        self::assertContains('conversion_snapshot', (new SalesReturnLine)->getFillable());
        self::assertStringContainsString("Schema::create('pos_sales_return_inventory_links'", $migration);
        self::assertStringContainsString("'psril_source_movement_fk'", $migration);
        self::assertStringContainsString("'psril_source_allocation_fk'", $migration);
    }
}
