<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProductionReceiptSourceMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('wms_inventory_adjustment_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('document_context');
            $table->unsignedBigInteger('source_issue_id')->nullable();
        });
        Schema::create('wms_issue_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('stock_movement_id');
            $table->unsignedInteger('line_number');
            $table->softDeletes();
        });
        Schema::create('wms_cost_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_movement_id');
            $table->string('status');
            $table->unsignedInteger('revision')->default(0);
            $table->decimal('quantity', 20, 8);
            $table->decimal('value', 20, 8);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wms_production_receipt_sources');
        Schema::dropIfExists('wms_cost_allocations');
        Schema::dropIfExists('wms_issue_lines');
        Schema::dropIfExists('wms_inventory_adjustment_documents');

        parent::tearDown();
    }

    public function test_migration_backfills_each_fifo_allocation_and_is_idempotent(): void
    {
        DB::table('wms_inventory_adjustment_documents')->insert(['id' => 10, 'document_context' => 'PRODUCTION_RECEIPT', 'source_issue_id' => 20]);
        DB::table('wms_issue_lines')->insert(['id' => 30, 'document_id' => 20, 'stock_movement_id' => 40, 'line_number' => 1]);
        DB::table('wms_cost_allocations')->insert([
            ['id' => 50, 'stock_movement_id' => 40, 'status' => 'POSTED', 'revision' => 2, 'quantity' => 2, 'value' => -20],
            ['id' => 51, 'stock_movement_id' => 40, 'status' => 'POSTED', 'revision' => 3, 'quantity' => 3, 'value' => -36],
        ]);
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_09_010000_create_wms_production_receipt_sources.php';

        $migration->up();
        $migration->up();

        $rows = DB::table('wms_production_receipt_sources')->orderBy('source_allocation_id')->get();
        self::assertCount(2, $rows);
        self::assertSame([50, 51], $rows->pluck('source_allocation_id')->map(fn ($id): int => (int) $id)->all());
        self::assertSame([20.0, 36.0], $rows->pluck('consumed_value')->map(fn ($value): float => (float) $value)->all());
        self::assertSame([2, 3], $rows->pluck('source_allocation_revision')->map(fn ($revision): int => (int) $revision)->all());
    }
}
