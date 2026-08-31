<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Services\IssueReturnService;
use App\Modules\Wms\Services\StockMovementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Opt-in, rollback-only local MySQL evidence for real FIFO issue-return lineage. */
final class IssueReturnFifoMySqlIntegrationReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private User $actor;

    private Warehouse $warehouse;

    private Item $item;

    private int $uomId;

    private string $date;

    private array $settingsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $this->actor = User::query()->firstOrFail();
        $base = Warehouse::query()->whereNull('deleted_at')->orderBy('id')->firstOrFail();
        $stamp = substr((string) hrtime(true), -10);
        $warehouseId = DB::table('warehouses')->insertGetId([
            'branch_id' => $base->branch_id, 'code' => "IR-FIFO-{$stamp}", 'name' => 'Issue Return FIFO Test',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->warehouse = Warehouse::query()->findOrFail($warehouseId);
        $categoryId = DB::table('wms_item_categories')->orderBy('id')->value('id');
        $this->uomId = (int) DB::table('wms_uoms')->where('is_active', true)->orderBy('id')->value('id');
        $itemId = DB::table('wms_items')->insertGetId([
            'category_id' => $categoryId, 'code' => "IR-FIFO-{$stamp}", 'name' => 'Issue Return FIFO Test Item',
            'item_type' => 'GOODS', 'base_uom' => 'TEST', 'base_uom_id' => $this->uomId,
            'is_stock_item' => true, 'is_active' => true, 'created_by' => $this->actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->item = Item::query()->findOrFail($itemId);
        $this->date = (string) DB::table('fiscal_periods')->where('status', 'OPEN')->orderBy('start_date')->value('start_date');
        $this->settingsBefore = (array) DB::table('company_settings')->where('id', 1)->firstOrFail();
        DB::table('company_settings')->where('id', 1)->update([
            'inventory_costing_method' => 'FIFO', 'allow_negative_stock' => false,
            'settings_version' => ((int) $this->settingsBefore['settings_version']) + 1,
        ]);
        app(GlobalSettings::class)->forget((int) $this->settingsBefore['settings_version']);
        foreach ([['INVENTORY_ISSUE', 'ใบเบิกสินค้า', 'ISSUE'], ['INVENTORY_RETURN', 'ใบรับคืนจากการเบิก', 'IRTN']] as [$type, $name, $prefix]) {
            DocumentSequence::query()->updateOrCreate(
                ['warehouse_id' => $this->warehouse->id, 'document_type' => $type],
                ['name' => $name, 'prefix' => $prefix, 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'created_by' => $this->actor->id],
            );
        }
    }

    public function test_real_fifo_issue_return_splits_layers_and_retries_idempotently(): void
    {
        $movements = app(StockMovementService::class);
        foreach ([['6', '10', 'receipt-a'], ['4', '20', 'receipt-b']] as [$qty, $cost, $key]) {
            $movement = $movements->recordIntent([
                'warehouse_id' => $this->warehouse->id, 'item_id' => $this->item->id, 'uom_id' => $this->uomId,
                'movement_type' => 'RECEIPT', 'direction' => 'IN', 'quantity' => $qty, 'base_quantity' => $qty,
                'business_date' => $this->date, 'source_type' => 'TEST', 'source_id' => $key,
                'source_reference' => $key, 'idempotency_key' => "ir-fifo:{$key}",
                'metadata' => ['unit_cost' => $cost, 'unit_cost_trusted' => true], 'created_by' => $this->actor->id,
            ]);
            $movements->post($movement);
        }

        $issueMovement = $movements->recordIntent([
            'warehouse_id' => $this->warehouse->id, 'item_id' => $this->item->id, 'uom_id' => $this->uomId,
            'movement_type' => 'ISSUE', 'direction' => 'OUT', 'quantity' => '10', 'base_quantity' => '10',
            'business_date' => $this->date, 'source_type' => 'TEST', 'source_id' => 'issue-a',
            'source_reference' => 'issue-a', 'idempotency_key' => 'ir-fifo:issue-a', 'created_by' => $this->actor->id,
        ]);
        $movements->post($issueMovement);
        $outAllocations = DB::table('wms_cost_allocations')->where('stock_movement_id', $issueMovement->id)->where('direction', 'OUT')->orderBy('id')->get();
        $this->assertCount(2, $outAllocations);
        $this->assertSame(['6.00000000', '4.00000000'], $outAllocations->pluck('quantity')->map(fn ($v) => (string) $v)->all());

        $issue = IssueDocument::create([
            'warehouse_id' => $this->warehouse->id, 'document_number' => 'IR-FIFO-ISSUE', 'document_date' => $this->date,
            'status' => 'POSTED', 'issue_type' => 'GENERAL', 'reason' => 'FIFO evidence', 'idempotency_key' => 'ir-fifo:issue-doc', 'created_by' => $this->actor->id,
        ]);
        $issueLine = $issue->lines()->create(['item_id' => $this->item->id, 'uom_id' => $this->uomId, 'quantity' => '10', 'stock_movement_id' => $issueMovement->id, 'line_number' => 1]);
        $request = Request::create('/wms/issue-returns', 'POST');
        $service = app(IssueReturnService::class);
        $sequence = app(DocumentSequenceService::class);
        $audit = app(AuditLogger::class);
        $return = $service->createReturn(['issue_document_id' => $issue->id, 'document_date' => $this->date, 'reason' => 'คืนเพื่อทดสอบ FIFO', 'lines' => [['issue_line_id' => $issueLine->id, 'quantity' => '10']]], $this->warehouse, $this->actor, $sequence, $audit, $request);
        $returnLine = $return->lines()->firstOrFail();
        $splits = $returnLine->sourceAllocations()->orderBy('source_allocation_id')->get();
        $this->assertCount(2, $splits);
        $this->assertSame(['6.00000000', '4.00000000'], $splits->pluck('quantity')->map(fn ($v) => (string) $v)->all());

        $service->approve($return, $this->actor, $audit, $request);
        $posted = $service->postReturn($return, $this->warehouse, $this->actor, $audit, $request);
        $this->assertSame('POSTED', $posted->status);
        $returnMovements = DB::table('wms_stock_movements')->where('source_type', 'ISSUE_RETURN')->where('source_id', (string) $return->id)->orderBy('id')->get();
        $this->assertCount(2, $returnMovements);
        $this->assertSame(['6.00000000', '4.00000000'], $returnMovements->pluck('base_quantity')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(2, DB::table('wms_issue_return_line_allocations')->where('return_line_id', $returnLine->id)->whereNotNull('stock_movement_id')->count());
        $this->assertSame(10.0, (float) DB::table('wms_stock_balances')->where('warehouse_id', $this->warehouse->id)->where('item_id', $this->item->id)->value('on_hand'));

        $movementCount = DB::table('wms_stock_movements')->count();
        $retry = $service->postReturn($return->fresh(), $this->warehouse, $this->actor, $audit, $request);
        $this->assertSame('POSTED', $retry->status);
        $this->assertSame($movementCount, DB::table('wms_stock_movements')->count());

        $before = [DB::table('wms_issue_returns')->count(), DB::table('wms_issue_return_lines')->count(), DB::table('wms_stock_movements')->count()];
        $this->expectException(ValidationException::class);
        try {
            $service->createReturn(['issue_document_id' => $issue->id, 'document_date' => $this->date, 'reason' => 'เกินจำนวน', 'lines' => [['issue_line_id' => $issueLine->id, 'quantity' => '1']]], $this->warehouse, $this->actor, $sequence, $audit, $request);
        } finally {
            $this->assertSame($before, [DB::table('wms_issue_returns')->count(), DB::table('wms_issue_return_lines')->count(), DB::table('wms_stock_movements')->count()]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->settingsBefore !== []) {
            DB::table('company_settings')->where('id', 1)->update($this->settingsBefore);
            app(GlobalSettings::class)->forget((int) $this->settingsBefore['settings_version']);
        }
        parent::tearDown();
    }
}
