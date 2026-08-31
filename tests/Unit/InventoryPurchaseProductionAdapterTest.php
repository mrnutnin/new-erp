<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Services\InventoryPurchaseProductionAdapter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryPurchaseProductionAdapterTest extends TestCase
{
    public function test_post_gate_rejects_before_preflight_or_collaborators(): void
    {
        $adapter = app(InventoryPurchaseProductionAdapter::class);
        $this->expectException(ValidationException::class);
        $adapter->post(new PurchaseDocument, new Warehouse, new User, null, false);
    }

    public function test_callback_gate_rejects_before_outer_transaction_when_closed(): void
    {
        $adapter = app(InventoryPurchaseProductionAdapter::class);
        $this->expectException(ValidationException::class);
        $adapter->postWithCallbacks(new PurchaseDocument, new Warehouse, new User, null, false);
    }

    public function test_purchase_ap_resolution_is_explicit_mapping_not_first_account(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/InventoryPurchaseProductionAdapter.php'));

        $this->assertStringContainsString("where('key', 'PURCHASE_AP')", $source);
        $this->assertStringContainsString('->sole()', $source);
        $this->assertStringNotContainsString("orderBy('id')->first", $source);
    }

    public function test_expense_route_remains_separate_from_inventory_route(): void
    {
        $this->assertNotSame(
            Route::getRoutes()->getByName('wms.purchase-documents.post')->getActionName(),
            Route::getRoutes()->getByName('wms.purchase-documents.inventory-post')->getActionName(),
        );
    }

    public function test_inventory_route_uses_the_bounded_adapter_and_posting_date(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Controllers/PurchaseDocumentController.php'));

        $this->assertStringContainsString('$adapter->post(', $source);
        $this->assertStringContainsString("\$request->validated('posting_date')", $source);
    }

    public function test_posted_retry_verifier_requires_inventory_identity_and_exact_receipt_allocation(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/InventoryPurchaseProductionAdapter.php'));

        $this->assertStringContainsString('verifyPostedRetry', $source);
        $this->assertStringContainsString('supplier_invoice.inventory', $source);
        $this->assertStringContainsString('movement:{', $source);
        $this->assertStringContainsString('->sole()', $source);
        $this->assertStringContainsString('defaultReconciliationGate', $source);
    }

    public function test_posting_date_cannot_precede_the_purchase_document(): void
    {
        $adapter = app(InventoryPurchaseProductionAdapter::class);
        $this->expectException(ValidationException::class);

        $adapter->postWithCallbacks(
            new PurchaseDocument(['document_date' => '2026-08-22']),
            new Warehouse,
            new User,
            null,
            true,
            null,
            '2026-08-21',
        );
    }
}
