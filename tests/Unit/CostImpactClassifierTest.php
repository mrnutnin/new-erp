<?php

namespace Tests\Unit;

use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Services\CostImpactClassifier;
use App\Modules\Wms\Support\CostImpactBucket;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CostImpactClassifierTest extends TestCase
{
    #[DataProvider('knownEvents')]
    public function test_known_events_are_classified_explicitly(
        string $sourceType,
        string $movementType,
        string $direction,
        array $metadata,
        CostImpactBucket $expected,
    ): void {
        $impact = (new CostImpactClassifier)->classify(
            $this->allocation($sourceType, $movementType, $direction, $metadata),
            '-12.345',
            branchId: 1,
        );

        self::assertTrue($impact->ready());
        self::assertSame($expected, $impact->bucket);
        self::assertSame('-12.34500000', $impact->deltaValue);
        $targetEvent = match ([$sourceType, $direction, $metadata['issue_type'] ?? null]) {
            ['POS', 'IN', null] => 'inventory.revaluation.sales_return',
            ['ISSUE_RETURN', 'IN', 'PRODUCTION'] => 'production.revaluation.material_return',
            ['ISSUE_RETURN', 'IN', null] => 'inventory.revaluation.issue_return',
            default => $expected->targetEvent(),
        };
        self::assertSame($targetEvent, $impact->targetEvent);
    }

    public function test_unknown_event_is_blocked_instead_of_falling_back_to_inventory(): void
    {
        $impact = (new CostImpactClassifier)->classify(
            $this->allocation('UNKNOWN_SOURCE', 'UNKNOWN', 'IN'),
            '1',
            branchId: 1,
        );

        self::assertFalse($impact->ready());
        self::assertNull($impact->bucket);
        self::assertContains('UNSUPPORTED_IMPACT_EVENT', $impact->blockers);
    }

    public function test_pending_cost_allocation_lifecycle_and_missing_branch_are_blocked(): void
    {
        $allocation = $this->allocation('PURCHASING', 'RECEIPT', 'IN');
        $allocation->cost_status = 'PENDING';
        $allocation->status = 'PENDING';
        $movement = $allocation->movement;
        $movement->setRelation('warehouse', null);

        $impact = (new CostImpactClassifier)->classify($allocation, '1');

        self::assertFalse($impact->ready());
        self::assertContains('PENDING_COST', $impact->blockers);
        self::assertContains('ACCOUNTING_PROOF_PENDING', $impact->warnings);
        self::assertContains('SCOPE_BRANCH_MISSING', $impact->blockers);
    }

    public function test_final_pending_no_gl_transfer_does_not_require_journal_proof(): void
    {
        $allocation = $this->allocation('WMS_TRANSFER', 'TRANSFER', 'OUT', ['transfer_event' => 'DISPATCH']);
        $allocation->status = 'PENDING';

        $impact = (new CostImpactClassifier)->classify($allocation, '1', branchId: 1);

        self::assertTrue($impact->ready());
        self::assertSame([], $impact->blockers);
        self::assertSame([], $impact->warnings);
    }

    public function test_non_return_credit_note_stock_node_is_blocked_explicitly(): void
    {
        $impact = (new CostImpactClassifier)->classify(
            $this->allocation('PURCHASING', 'ISSUE', 'OUT', ['credit_note_mode' => 'NON_RETURN']),
            '-12.345',
            branchId: 1,
        );

        self::assertFalse($impact->ready());
        self::assertNull($impact->bucket);
        self::assertSame(['NON_RETURN_STOCK_EVENT_FORBIDDEN'], $impact->blockers);
    }

    public static function knownEvents(): array
    {
        return [
            'purchase receipt' => ['PURCHASING', 'RECEIPT', 'IN', [], CostImpactBucket::InventoryOnHand],
            'partial purchase return' => ['PURCHASING', 'ISSUE', 'OUT', ['credit_note_mode' => 'RETURN', 'purchase_return_mode' => 'PARTIAL'], CostImpactBucket::PurchaseReturnConsumed],
            'sale cogs' => ['POS', 'ISSUE', 'OUT', [], CostImpactBucket::CogsConsumed],
            'sale return' => ['POS', 'ISSUE', 'IN', ['sales_return_id' => 1], CostImpactBucket::ReturnBridge],
            'generic issue' => ['ISSUE_DOCUMENT', 'ISSUE', 'OUT', ['issue_type' => 'GENERAL'], CostImpactBucket::IssueExpenseConsumed],
            'material issue' => ['ISSUE_DOCUMENT', 'ISSUE', 'OUT', ['issue_type' => 'PRODUCTION'], CostImpactBucket::WipConsumed],
            'issue return' => ['ISSUE_RETURN', 'ISSUE', 'IN', [], CostImpactBucket::ReturnBridge],
            'production material return' => ['ISSUE_RETURN', 'ISSUE', 'IN', ['issue_type' => 'PRODUCTION'], CostImpactBucket::ReturnBridge],
            'finished receipt' => ['WMS_PRODUCTION_RECEIPT', 'RECEIPT', 'IN', [], CostImpactBucket::FinishedGoodsBridge],
            'transfer' => ['WMS_TRANSFER', 'TRANSFER', 'OUT', ['transfer_event' => 'DISPATCH'], CostImpactBucket::TransferBridge],
            'adjustment gain' => ['INVENTORY', 'ADJUSTMENT', 'IN', [], CostImpactBucket::InventoryOnHand],
            'adjustment loss' => ['INVENTORY', 'ADJUSTMENT', 'OUT', [], CostImpactBucket::IssueExpenseConsumed],
        ];
    }

    private function allocation(string $sourceType, string $movementType, string $direction, array $metadata = []): CostAllocation
    {
        $movement = new StockMovement([
            'warehouse_id' => 1,
            'item_id' => 2,
            'uom_id' => 3,
            'source_type' => $sourceType,
            'source_id' => '10',
            'source_reference' => 'DOC-10',
            'movement_type' => $movementType,
            'direction' => $direction,
            'status' => 'POSTED',
            'business_date' => '2026-09-01',
            'metadata' => $metadata,
        ]);
        $movement->id = 20;
        $movement->business_date = Carbon::parse('2026-09-01');

        $allocation = new CostAllocation([
            'warehouse_id' => 1,
            'item_id' => 2,
            'uom_id' => 3,
            'direction' => $direction,
            'status' => 'POSTED',
            'cost_status' => 'FINAL',
            'quantity' => '4',
            'business_date' => '2026-09-01',
        ]);
        $allocation->id = 30;
        $allocation->business_date = Carbon::parse('2026-09-01');
        $allocation->setRelation('movement', $movement);

        return $allocation;
    }
}
