<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Release-boundary tests for the current Receipt Draft foundation.
 * Receipt must remain an intent until the Inventory Post contract can persist
 * exact UOM/cost snapshots and reuse the Purchase Document posting proof.
 */
final class GoodsReceiptInventoryPostAuditTest extends TestCase
{
    public function test_receipt_has_no_independent_posting_route(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->getName() ?? '', 'wms.purchase-receipts.'))
            ->map(fn ($route): string => $route->getName())
            ->values();

        $this->assertContains('wms.purchase-receipts.index', $routes);
        $this->assertContains('wms.purchase-receipts.store', $routes);
        $this->assertContains('wms.purchase-receipts.update', $routes);
        $this->assertFalse($routes->contains(fn (string $name): bool => str_contains($name, 'post')));
    }

    public function test_receipt_foundation_is_still_draft_only_and_does_not_create_posting_records(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/PurchaseReceiptFoundationService.php'));

        $this->assertStringContainsString("'status' => 'DRAFT'", $source);
        $this->assertStringContainsString("'movement_type' => 'RECEIPT'", $source);
        $this->assertStringNotContainsString('postWithinTransaction', $source);
        $this->assertStringNotContainsString('JournalPostingService', $source);
        $this->assertStringNotContainsString('CostAllocationService', $source);
    }

    public function test_inventory_post_boundary_requires_exact_source_and_single_allocation_retry_proof(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/InventoryPurchaseProductionAdapter.php'));

        foreach ([
            "where('source_type', 'PURCHASING')",
            "where('source_event', 'supplier_invoice.inventory')",
            "where('source_id', (string) \$document->id)",
            "where('source_reference', \$document->document_number)",
            'resolveReceiptAllocationWithinTransaction',
            '$allocations->count() !== 1',
            'defaultReconciliationGate',
        ] as $proof) {
            $this->assertStringContainsString($proof, $source);
        }
    }

    public function test_inventory_post_adapter_does_not_claim_snapshot_ready_when_receipt_snapshot_is_missing(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/InventoryPurchaseProductionAdapter.php'));

        $this->assertStringNotContainsString('GoodsReceiptConversionContract::resolve', $source);
        $this->assertStringNotContainsString("'conversion_snapshot'", $source);
        $this->assertStringContainsString('production_ready', $source);
    }

    public function test_goods_receipt_inventory_writer_is_closed_without_journal_or_route(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/GoodsReceiptInventoryService.php'));

        $this->assertStringContainsString('DB::transaction', $source);
        $this->assertStringContainsString('GoodsReceiptMovementAdapter::map', $source);
        $this->assertStringContainsString('postWithinTransaction', $source);
        $this->assertStringNotContainsString('JournalPostingService', $source);
        $this->assertStringNotContainsString('journal_entries', $source);

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->getName() ?? '', 'wms.purchase-receipts.'))
            ->map(fn ($route): string => $route->getName())
            ->values();

        $this->assertFalse($routes->contains(fn (string $name): bool => str_contains($name, 'inventory')));
    }

    public function test_goods_receipt_retry_identity_includes_cost_and_conversion_snapshot(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/GoodsReceiptInventoryService.php'));

        foreach (['idempotency_key', 'rounding_delta', 'event_code', 'conversion_snapshot', 'assertSameIntent'] as $proof) {
            $this->assertStringContainsString($proof, $source);
        }
        $this->assertStringContainsString("source_type' => 'GOODS_RECEIPT'", file_get_contents(base_path('app/Modules/Wms/Support/GoodsReceiptInventoryPostingContract.php')));
        $this->assertStringContainsString('goods-receipt:', file_get_contents(base_path('app/Modules/Wms/Support/GoodsReceiptInventoryPostingContract.php')));
    }
}
