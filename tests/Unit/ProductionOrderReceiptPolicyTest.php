<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\ManualProductionReceiptPostingService;
use App\Modules\Wms\Support\ManualProductionReceiptContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

final class ProductionOrderReceiptPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('production_orders', function (Blueprint $table): void {
            $table->id();
            $table->decimal('planned_quantity', 20, 8);
            $table->unsignedBigInteger('finished_item_id');
        });
        Schema::create('production_order_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('production_order_id');
            $table->string('event_type');
            $table->string('source_id')->nullable();
            $table->timestamps();
        });
        Schema::create('wms_inventory_adjustment_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('document_context');
            $table->unsignedBigInteger('source_issue_id')->nullable();
            $table->string('status')->default('DRAFT');
            $table->string('reversal_status')->nullable();
            $table->softDeletes();
        });
        Schema::create('wms_issue_returns', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('issue_document_id');
            $table->string('status')->default('DRAFT');
            $table->softDeletes();
        });

        DB::table('production_orders')->insert(['id' => 10, 'planned_quantity' => '5.00000000', 'finished_item_id' => 100]);
        DB::table('production_order_events')->insert(['production_order_id' => 10, 'event_type' => 'material_issue_created', 'source_id' => '20', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wms_issue_returns');
        Schema::dropIfExists('wms_inventory_adjustment_documents');
        Schema::dropIfExists('production_order_events');
        Schema::dropIfExists('production_orders');

        parent::tearDown();
    }

    public function test_wo_finished_receipt_policy_requires_one_receipt_for_planned_quantity_and_clear_adjustments(): void
    {
        self::assertSame([], $this->blockers('5.00000000'));

        self::assertSame('PRODUCTION_ORDER_RECEIPT_NOT_PLANNED_QUANTITY', $this->blockers('4.99999999')[0]['code']);

        DB::table('wms_inventory_adjustment_documents')->insert(['id' => 99, 'document_context' => ManualProductionReceiptContract::CONTEXT, 'source_issue_id' => 20, 'status' => 'APPROVED']);
        self::assertSame('PRODUCTION_ORDER_FINISHED_RECEIPT_EXISTS', $this->blockers('5.00000000')[0]['code']);
        DB::table('wms_inventory_adjustment_documents')->where('id', 99)->update(['reversal_status' => 'REVERSED']);

        DB::table('wms_issue_returns')->insert(['issue_document_id' => 20, 'status' => 'DRAFT']);
        DB::table('wms_inventory_adjustment_documents')->insert(['document_context' => 'PRODUCTION_SCRAP_RECEIPT', 'source_issue_id' => 20, 'status' => 'APPROVED']);
        self::assertSame(['PRODUCTION_ORDER_PENDING_MATERIAL_RETURN', 'PRODUCTION_ORDER_PENDING_SCRAP_RECEIPT'], collect($this->blockers('5.00000000'))->pluck('code')->all());
    }

    private function blockers(string $quantity): array
    {
        $service = (new ReflectionClass(ManualProductionReceiptPostingService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'productionOrderReceiptLimitBlockers');
        $method->setAccessible(true);

        return $method->invoke($service, [
            'id' => 88,
            'document_context' => ManualProductionReceiptContract::CONTEXT,
            'source_issue_id' => 20,
            'lines' => [['item_id' => 100, 'quantity' => $quantity]],
        ]);
    }
}
