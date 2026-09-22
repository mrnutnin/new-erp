<?php

namespace Tests\Unit;

use App\Modules\Production\Controllers\OrderController;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Wms\Support\ManualProductionReceiptContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

final class ProductionJournalScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['journal_entries', 'wms_inventory_adjustment_documents', 'wms_issue_returns', 'production_order_events', 'production_orders'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('production_orders', function (Blueprint $table): void {
            $table->id();
            $table->softDeletes();
        });
        Schema::create('production_order_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('production_order_id');
            $table->string('event_type');
            $table->string('source_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wms_issue_returns', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('issue_document_id');
            $table->softDeletes();
        });
        Schema::create('wms_inventory_adjustment_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_issue_id')->nullable();
            $table->string('document_context');
            $table->softDeletes();
        });
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type');
            $table->string('source_id');
            $table->date('entry_date');
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        foreach (['journal_entries', 'wms_inventory_adjustment_documents', 'wms_issue_returns', 'production_order_events', 'production_orders'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_wo_journal_scope_includes_only_related_production_documents_and_reversals(): void
    {
        DB::table('production_orders')->insert(['id' => 1]);
        DB::table('production_order_events')->insert(['production_order_id' => 1, 'event_type' => 'material_issue_created', 'source_id' => '20', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wms_issue_returns')->insert(['id' => 30, 'issue_document_id' => 20]);
        DB::table('wms_inventory_adjustment_documents')->insert([
            ['id' => 40, 'source_issue_id' => 20, 'document_context' => ManualProductionReceiptContract::CONTEXT],
            ['id' => 50, 'source_issue_id' => 20, 'document_context' => 'PRODUCTION_SCRAP_RECEIPT'],
            ['id' => 60, 'source_issue_id' => 999, 'document_context' => ManualProductionReceiptContract::CONTEXT],
        ]);
        foreach ([
            [1, 'WMS_ISSUE', '20'],
            [2, 'WMS_ISSUE_RETURN', '30'],
            [3, 'WMS_ISSUE_RETURN', 'issue-return:30:reversal:1'],
            [4, 'WMS_PRODUCTION_RECEIPT', '40'],
            [5, 'WMS_PRODUCTION_RECEIPT', 'reversal:production-finished-receipt:40:revision:1'],
            [6, 'WMS_PRODUCTION_SCRAP_RECEIPT', '50'],
            [7, 'WMS_PRODUCTION_SCRAP_RECEIPT', 'reversal:production-scrap-receipt:50:revision:1'],
            [8, 'WMS_ISSUE', '999'],
            [9, 'MANUAL_JOURNAL', '20'],
            [10, 'WMS_PRODUCTION_RECEIPT', '60'],
        ] as [$id, $type, $source]) {
            DB::table('journal_entries')->insert(['id' => $id, 'source_type' => $type, 'source_id' => $source, 'entry_date' => '2026-09-21']);
        }

        $method = new ReflectionMethod(OrderController::class, 'productionJournalIds');
        $method->setAccessible(true);

        self::assertSame([1, 2, 3, 4, 5, 6, 7], $method->invoke(new OrderController(), ProductionOrder::query()->findOrFail(1))->all());
    }
}
