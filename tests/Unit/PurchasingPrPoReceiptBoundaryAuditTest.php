<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PurchasingPrPoReceiptBoundaryAuditTest extends TestCase
{
    public function test_pr_po_linkage_keeps_source_and_receipt_guards(): void
    {
        $service = file_get_contents(base_path('app/Modules/Purchasing/Services/PurchaseRequisitionPurchaseOrderService.php'));
        foreach ([
            "status !== 'APPROVED'",
            'lockForUpdate()',
            "purchase_requisition_id' => \$source->id",
            'remaining',
            "status === 'VOID'",
            'is_active',
            'purchase_requisition_line_id',
        ] as $proof) {
            $this->assertStringContainsString($proof, $service);
        }

        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseRequisitionController.php'));
        $this->assertStringContainsString("status === 'APPROVED' && \$request->user()->hasPermission(\$this->modulePermission('purchase-requisitions.create-po'))", $controller);
        $this->assertStringContainsString('protected function modulePermission(string $permission): string', $controller);
        $this->assertStringContainsString("route(\$this->moduleRoutePrefix().'.purchase-orders.create', ['purchase_requisition_id' => \$r->id])", $controller);

        $index = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-requisitions/index.blade.php'));
        $this->assertStringContainsString('href="\'+x.display(r.create_po_url)', $index);
        $this->assertStringNotContainsString('pr-po-supplier', $index);

        $poController = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseOrderController.php'));
        $this->assertStringContainsString('GoodsReceipt::query()', $poController);
        $this->assertStringContainsString('มี Goods Receipt ที่ยังไม่ยกเลิก', $poController);
        $this->assertStringContainsString('protected function modulePermission(string $permission): string', $poController);
        $this->assertStringContainsString("hasPermission(\$this->modulePermission('purchase-orders.approve'))", $poController);

        $receipt = file_get_contents(base_path('app/Modules/Purchasing/Services/GoodsReceiptService.php'));
        foreach ([
            'idempotency_key',
            "status !== 'APPROVED'",
            'lockForUpdate()',
            'PO line ซ้ำใน Receipt เดียวกัน',
            'isGreaterThan',
            "status' => 'DRAFT'",
        ] as $proof) {
            $this->assertStringContainsString($proof, $receipt);
        }

        $receiptController = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseReceiptController.php'));
        $this->assertStringContainsString('protected function modulePermission(string $permission): string', $receiptController);
        $this->assertStringContainsString("hasPermission(\$this->modulePermission('purchase-receipts.approve'))", $receiptController);
    }

    public function test_receipt_draft_has_no_inventory_posting_path(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->getName() ?? '', 'purchase-receipts'))
            ->map(fn ($route): string => $route->getName())
            ->values();

        $this->assertFalse($routes->contains(fn (string $name): bool => str_contains($name, 'post')));
        $source = file_get_contents(base_path('app/Modules/Purchasing/Services/GoodsReceiptService.php'));
        $this->assertStringNotContainsString('JournalPostingService', $source);
        $this->assertStringNotContainsString('StockMovementService', $source);
        $this->assertStringNotContainsString('InventoryCostAllocationService', $source);
    }

    public function test_purchasing_purchase_document_surface_has_complete_ap_endpoint_seam(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->getName() ?? '', 'purchasing.purchase-documents.'))
            ->map(fn ($route): string => $route->getName())
            ->values();

        foreach ([
            'index', 'data', 'create', 'store', 'show', 'edit', 'update', 'destroy', 'approve', 'post', 'void',
            'supplier-options', 'account-options', 'item-options', 'uom-options', 'tax-code-options',
            'withholding-tax-code-options', 'original-options', 'purchase-order-line-options',
            'goods-receipt-line-options', 'goods-receipt-options', 'goods-receipt-lines', 'three-way-match',
            'variance-approve', 'variance-reject', 'variance-recover', 'inventory-post', 'inventory-reverse',
            'credit-inventory-reverse', 'pdf',
        ] as $suffix) {
            $this->assertTrue($names->contains('purchasing.purchase-documents.'.$suffix), $suffix.' route is missing');
        }

        $documentController = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseDocumentController.php'));
        $this->assertStringContainsString('protected function modulePermission(string $permission): string', $documentController);
        $this->assertStringContainsString("hasPermission(\$this->modulePermission('purchase-documents.post'))", $documentController);
        $this->assertStringContainsString("route(\$this->moduleRoutePrefix().'.purchase-documents.inventory-post'", $documentController);
        $this->assertStringNotContainsString("route('wms.purchase-documents.show'", $documentController);
    }

    public function test_purchasing_pdf_is_module_owned_and_uses_purchasing_view_alias(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseDocumentPdfController.php'));

        $this->assertStringContainsString('namespace App\\Modules\\Purchasing\\Controllers;', $controller);
        $this->assertStringContainsString("renderView('Purchasing::pdf.purchase-document'", $controller);
        $this->assertStringNotContainsString('extends \\App\\Modules\\Wms\\Controllers\\PurchaseDocumentPdfController', $controller);
    }

    public function test_purchasing_dashboard_is_module_owned(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/EntryController.php'));

        $this->assertStringContainsString('namespace App\\Modules\\Purchasing\\Controllers;', $controller);
        $this->assertStringContainsString("view('Purchasing::dashboard'", $controller);
        $this->assertStringNotContainsString('extends \\App\\Modules\\Wms\\Controllers\\EntryController', $controller);
        $this->assertFileExists(base_path('app/Modules/Purchasing/Views/dashboard.blade.php'));
        $this->assertFileExists(base_path('app/Modules/Purchasing/Views/layout.blade.php'));
    }

    public function test_purchasing_workflow_is_module_owned(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/WorkflowController.php'));

        $this->assertStringContainsString('namespace App\\Modules\\Purchasing\\Controllers;', $controller);
        $this->assertStringContainsString("view('Purchasing::workflow.index'", $controller);
        $this->assertFileExists(base_path('app/Modules/Purchasing/Views/workflow/index.blade.php'));

        $routeNames = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => (string) $route->getName())
            ->filter();

        $this->assertTrue($routeNames->contains('purchasing.workflow.index'));
    }

    public function test_supplier_controller_keeps_canonical_route_and_view_prefixes(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/SupplierController.php'));

        $this->assertStringContainsString('namespace App\\Modules\\Purchasing\\Controllers;', $controller);
        $this->assertStringContainsString("return 'purchasing';", $controller);
        $this->assertStringContainsString("return 'Purchasing';", $controller);
        $this->assertStringContainsString('public function index(): View', $controller);
        $this->assertStringContainsString('public function data(Request $request): JsonResponse', $controller);
        $this->assertStringContainsString('public function options(Request $request): JsonResponse', $controller);
        $this->assertStringContainsString('public function create(): View', $controller);
        $this->assertStringContainsString('public function edit(Party $supplier): View', $controller);
        $this->assertStringContainsString('public function store(SaveSupplierRequest $request, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse', $controller);
        $this->assertStringContainsString('public function update(SaveSupplierRequest $request, Party $supplier, AuditLogger $audit): JsonResponse', $controller);
        $this->assertStringContainsString('public function destroy(Request $request, Party $supplier, AuditLogger $audit): JsonResponse', $controller);
        $this->assertStringContainsString("name('suppliers.index')", file_get_contents(base_path('app/Modules/Purchasing/Routes/web.php')));
    }

    public function test_supplier_request_and_views_have_purchasing_owned_seams(): void
    {
        $request = file_get_contents(base_path('app/Modules/Purchasing/Requests/SaveSupplierRequest.php'));

        $this->assertStringContainsString('namespace App\\Modules\\Purchasing\\Requests;', $request);
        $this->assertStringContainsString('extends FormRequest', $request);
        $this->assertFileExists(base_path('app/Modules/Purchasing/Views/suppliers/index.blade.php'));
        $this->assertFileExists(base_path('app/Modules/Purchasing/Views/suppliers/form.blade.php'));
    }

    public function test_purchasing_documents_have_local_request_seams_before_controller_cutover(): void
    {
        $requests = [
            'ChangePurchaseDocumentStatusRequest',
            'PostPurchaseDocumentRequest',
            'PurchaseVarianceDecisionRequest',
            'SavePurchaseDocumentRequest',
            'ChangePurchaseRequisitionStatusRequest',
            'SavePurchaseRequisitionRequest',
            'SavePurchaseOrderRequest',
            'SaveSupplierRequest',
            'PurchaseVarianceDecisionRequest',
        ];

        foreach ($requests as $request) {
            $path = base_path('app/Modules/Purchasing/Requests/'.$request.'.php');
            $this->assertFileExists($path, $request.' seam is missing');
            $source = file_get_contents($path);
            $this->assertStringContainsString('namespace App\\Modules\\Purchasing\\Requests;', $source);
            if (in_array($request, ['ChangePurchaseRequisitionStatusRequest', 'SavePurchaseRequisitionRequest', 'SavePurchaseOrderRequest', 'ChangePurchaseDocumentStatusRequest', 'PostPurchaseDocumentRequest', 'PurchaseVarianceDecisionRequest', 'SavePurchaseDocumentRequest', 'SaveSupplierRequest'], true)) {
                $this->assertStringContainsString('extends FormRequest', $source);
            } else {
                $this->assertStringContainsString('extends \\App\\Modules\\Wms\\Requests\\'.$request, $source);
            }
        }
    }

    public function test_pr_and_po_controllers_resolve_canonical_purchasing_dependencies(): void
    {
        $pr = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseRequisitionController.php'));
        $po = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseOrderController.php'));

        $this->assertStringContainsString('use App\\Modules\\Purchasing\\Models\\PurchaseRequisition;', $pr);
        $this->assertStringContainsString('use App\\Modules\\Purchasing\\Requests\\SavePurchaseRequisitionRequest;', $pr);
        $this->assertStringContainsString('use App\\Modules\\Purchasing\\Models\\PurchaseOrder;', $po);
        $this->assertStringContainsString('use App\\Modules\\Purchasing\\Requests\\SavePurchaseOrderRequest;', $po);
    }

    public function test_purchasing_requisition_state_is_canonical_after_wms_cleanup(): void
    {
        $canonical = file_get_contents(base_path('app/Modules/Purchasing/Support/PurchaseRequisitionState.php'));

        $this->assertStringContainsString('namespace App\\Modules\\Purchasing\\Support;', $canonical);
        $this->assertFileDoesNotExist(base_path('app/Modules/Wms/Support/PurchaseRequisitionState.php'));
    }

    public function test_purchase_document_domain_support_is_canonical_after_wms_cleanup(): void
    {
        foreach (['PurchaseDocumentState', 'PurchaseDocumentCalculator'] as $support) {
            $canonical = file_get_contents(base_path('app/Modules/Purchasing/Support/'.$support.'.php'));

            $this->assertStringContainsString('namespace App\\Modules\\Purchasing\\Support;', $canonical);
            $this->assertFileDoesNotExist(base_path('app/Modules/Wms/Support/'.$support.'.php'));
        }
    }

    public function test_purchasing_request_has_no_legacy_wms_route_compatibility(): void
    {
        $request = file_get_contents(base_path('app/Modules/Purchasing/Requests/ChangePurchaseDocumentStatusRequest.php'));
        $controllers = collect(glob(base_path('app/Modules/Purchasing/Controllers/*.php')))
            ->map(fn (string $path): string => file_get_contents($path))
            ->implode("\n");

        $this->assertStringNotContainsString("routeIs('wms.", $request);
        $this->assertStringContainsString("routeIs('purchasing.purchase-documents.approve'", $request);
        $this->assertStringNotContainsString("route('wms.", $controllers);
        $this->assertStringNotContainsString("hasPermission('wms.", $controllers);
    }

    public function test_purchasing_document_views_have_local_seams_before_markup_extraction(): void
    {
        foreach ([
            'purchase-requisitions/index',
            'purchase-requisitions/form',
            'purchase-orders/index',
            'purchase-orders/form',
            'purchase-orders/show',
            'purchase-receipts/index',
            'purchase-receipts/form',
            'purchase-documents/index',
            'purchase-documents/form',
            'purchase-documents/show',
        ] as $view) {
            $path = base_path('app/Modules/Purchasing/Views/'.$view.'.blade.php');
            $this->assertFileExists($path, $view.' view seam is missing');
            $source = file_get_contents($path);
            $this->assertStringContainsString("@extends(\$moduleRoutePrefix === 'purchasing'", $source);
        }
    }

    public function test_purchasing_views_are_canonical_after_wms_cleanup(): void
    {
        $provider = file_get_contents(base_path('app/Modules/Purchasing/Providers/PurchasingServiceProvider.php'));
        $sidebar = file_get_contents(base_path('app/Modules/Purchasing/Views/partials/sidebar.blade.php'));

        $this->assertStringNotContainsString("../../Wms/Views", $provider);
        $this->assertStringContainsString("@include('Purchasing::partials.sidebar')", file_get_contents(base_path('app/Modules/Purchasing/Views/layout.blade.php')));
        $this->assertStringContainsString("purchasing.purchase-documents.view", $sidebar);

        foreach ([
            'purchase-requisitions/index', 'purchase-requisitions/form',
            'purchase-orders/index', 'purchase-orders/form', 'purchase-orders/show',
            'purchase-receipts/index', 'purchase-receipts/form',
            'purchase-documents/index', 'purchase-documents/form', 'purchase-documents/show',
        ] as $view) {
            $canonical = file_get_contents(base_path('app/Modules/Purchasing/Views/'.$view.'.blade.php'));
            $this->assertStringContainsString("@extends(\$moduleRoutePrefix === 'purchasing'", $canonical);
            $this->assertFileDoesNotExist(base_path('app/Modules/Wms/Views/'.$view.'.blade.php'));
        }
    }

    public function test_shared_purchase_order_show_uses_module_route_prefix_for_canonical_surface(): void
    {
        $show = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-orders/show.blade.php'));

        $this->assertStringContainsString("route(\$moduleRoutePrefix.'.purchase-orders.pdf'", $show);
        $this->assertStringContainsString("route(\$moduleRoutePrefix.'.purchase-orders.index'", $show);
        $this->assertStringNotContainsString("route('wms.purchase-orders.pdf'", $show);
        $this->assertStringNotContainsString("route('wms.purchase-orders.index'", $show);
    }

    public function test_shared_purchase_requisition_filter_uses_module_route_prefix(): void
    {
        $index = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-requisitions/index.blade.php'));

        $this->assertStringContainsString("route(\$moduleRoutePrefix.'.purchase-requisitions.supplier-options'", $index);
        $this->assertStringNotContainsString("route('wms.purchase-requisitions.supplier-options'", $index);
    }

    public function test_shared_purchase_order_filter_uses_module_route_prefix(): void
    {
        $index = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-orders/index.blade.php'));

        $this->assertStringContainsString("route(\$moduleRoutePrefix.'.purchase-documents.supplier-options'", $index);
        $this->assertStringNotContainsString("route('wms.purchase-documents.supplier-options'", $index);
    }

    public function test_legacy_wms_purchasing_surface_is_retired(): void
    {
        $wmsRoutes = file_get_contents(base_path('app/Modules/Wms/Routes/web.php'));

        foreach ([
            'SupplierController',
            'PurchaseRequisitionController',
            'PurchaseOrderController',
            'PurchaseReceiptController',
            'PurchaseDocumentController',
        ] as $controller) {
            $this->assertStringNotContainsString($controller, $wmsRoutes, $controller.' must be retired from WMS routes');
            $this->assertFileDoesNotExist(base_path('app/Modules/Wms/Controllers/'.$controller.'.php'));
        }

        foreach ([
            'SaveSupplierRequest',
            'SavePurchaseRequisitionRequest',
            'SavePurchaseOrderRequest',
            'SavePurchaseDocumentRequest',
        ] as $request) {
            $this->assertFileDoesNotExist(base_path('app/Modules/Wms/Requests/'.$request.'.php'));
        }

        $this->assertStringNotContainsString('purchase-documents', $wmsRoutes);
        $this->assertStringNotContainsString('purchase-orders', $wmsRoutes);
        $this->assertStringNotContainsString('purchase-receipts', $wmsRoutes);
        $this->assertStringNotContainsString('purchase-requisitions', $wmsRoutes);
        $this->assertStringNotContainsString('suppliers', $wmsRoutes);
    }

    public function test_cleanup_removes_stale_purchasing_adapter_and_legacy_wrappers(): void
    {
        $this->assertFileDoesNotExist(base_path('app/Modules/Purchasing/Controllers/PurchaseRequisitionControllerAdapter.php'));

        foreach ([
            'PurchaseRequisitionController',
            'PurchaseOrderController',
            'PurchaseReceiptController',
            'PurchaseDocumentController',
            'SupplierController',
        ] as $controller) {
            $path = base_path('app/Modules/Wms/Controllers/'.$controller.'.php');
            $this->assertFileDoesNotExist($path, $controller.' legacy wrapper must be removed after retirement');
        }
    }

    public function test_supplier_reference_audit_is_purchasing_only_after_wms_cleanup(): void
    {
        $purchasingRoutes = file_get_contents(base_path('app/Modules/Purchasing/Routes/web.php'));
        $wmsRoutes = file_get_contents(base_path('app/Modules/Wms/Routes/web.php'));
        $purchasingController = file_get_contents(base_path('app/Modules/Purchasing/Controllers/SupplierController.php'));

        // Purchasing owns the only active supplier surface after retirement.
        $this->assertStringContainsString('use App\\Modules\\Purchasing\\Controllers\\SupplierController;', $purchasingRoutes);
        $this->assertStringContainsString('namespace App\\Modules\\Purchasing\\Controllers;', $purchasingController);
        $this->assertFileDoesNotExist(base_path('app/Modules/Wms/Controllers/SupplierController.php'));

        // Canonical Purchasing owns its permission namespace; the legacy WMS
        // No legacy WMS purchasing permission route remains.
        $this->assertStringContainsString('permission:purchasing.suppliers.view', $purchasingRoutes);

        $canonicalSupplier = app('router')->getRoutes()->getByName('purchasing.suppliers.index');
        $this->assertNotNull($canonicalSupplier);
        $this->assertContains('permission:purchasing.suppliers.view', $canonicalSupplier->middleware());

        $this->assertStringContainsString("'Purchasing::layout'", file_get_contents(base_path('app/Modules/Purchasing/Views/suppliers/index.blade.php')));
        $this->assertStringContainsString("'Purchasing::layout'", file_get_contents(base_path('app/Modules/Purchasing/Views/suppliers/form.blade.php')));
    }

    public function test_every_canonical_purchasing_route_uses_purchasing_permission_namespace(): void
    {
        $canonicalRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->getName() ?? '', 'purchasing.'));

        $this->assertGreaterThan(0, $canonicalRoutes->count());

        foreach ($canonicalRoutes as $route) {
            $middleware = $route->middleware();
            $this->assertFalse(
                collect($middleware)->contains(fn (string $entry): bool => str_starts_with($entry, 'permission:wms.')),
                'Canonical route must not use legacy WMS permission: '.($route->getName() ?? '(unnamed)')
            );
        }
    }

    public function test_canonical_option_and_datatable_routes_win_over_wildcards(): void
    {
        foreach ([
            '/purchasing/suppliers/data' => 'purchasing.suppliers.data',
            '/purchasing/purchase-requisitions/data' => 'purchasing.purchase-requisitions.data',
            '/purchasing/purchase-orders/data' => 'purchasing.purchase-orders.data',
            '/purchasing/purchase-receipts/data' => 'purchasing.purchase-receipts.data',
            '/purchasing/purchase-documents/data' => 'purchasing.purchase-documents.data',
            '/purchasing/purchase-documents/supplier-options' => 'purchasing.purchase-documents.supplier-options',
            '/purchasing/purchase-documents/goods-receipt-lines' => 'purchasing.purchase-documents.goods-receipt-lines',
        ] as $uri => $expectedName) {
            $route = app('router')->getRoutes()->match(Request::create($uri, 'GET'));

            $this->assertSame($expectedName, $route->getName(), $uri.' must not be captured by a wildcard route');
        }
    }

    public function test_canonical_route_actions_keep_purchasing_ownership_boundary(): void
    {
        foreach ([
            'purchasing.suppliers.index' => 'App\\Modules\\Purchasing\\Controllers\\SupplierController',
            'purchasing.purchase-requisitions.index' => 'App\\Modules\\Purchasing\\Controllers\\PurchaseRequisitionController',
            'purchasing.purchase-orders.index' => 'App\\Modules\\Purchasing\\Controllers\\PurchaseOrderController',
            'purchasing.purchase-receipts.index' => 'App\\Modules\\Purchasing\\Controllers\\PurchaseReceiptController',
            'purchasing.purchase-documents.index' => 'App\\Modules\\Purchasing\\Controllers\\PurchaseDocumentController',
        ] as $name => $controller) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, $name.' route is missing');
            $this->assertSame($controller, $route->getControllerClass(), $name.' must keep its expected controller boundary');
            $this->assertContains('program:purchasing', $route->middleware(), $name.' must keep the Purchasing program boundary');
        }
    }

    public function test_purchasing_views_do_not_emit_legacy_wms_routes_or_permissions(): void
    {
        foreach (glob(base_path('app/Modules/Purchasing/Views/**/*.blade.php')) ?: [] as $path) {
            $source = file_get_contents($path);

            $this->assertStringNotContainsString("route('wms.", $source, $path);
            $this->assertStringNotContainsString("hasPermission('wms.", $source, $path);
        }
    }

    public function test_cross_module_purchasing_consumers_use_canonical_routes_and_permissions(): void
    {
        $journal = file_get_contents(base_path('app/Modules/Accounting/Controllers/JournalEntryController.php'));
        $runtime = file_get_contents(base_path('app/Modules/Platform/Services/WorkflowRuntimeResolver.php'));
        $catalog = file_get_contents(base_path('app/Modules/Platform/Services/WorkflowCatalog.php'));

        foreach ([$journal, $runtime, $catalog] as $source) {
            $this->assertStringNotContainsString('wms.purchase-documents', $source);
            $this->assertStringNotContainsString('wms.purchase-requisitions', $source);
        }
        $this->assertStringContainsString("route('purchasing.purchase-documents.show'", $journal);
        $this->assertStringContainsString("'purchasing.purchase-requisitions.index'", $runtime);
        $this->assertStringContainsString("'purchasing.purchase-documents.view'", $catalog);
    }

    public function test_purchasing_permission_namespace_is_canonical(): void
    {
        $user = file_get_contents(base_path('app/Models/User.php'));
        $rbac = file_get_contents(base_path('database/seeders/RbacSeeder.php'));

        $this->assertStringContainsString('fn (Permission $rolePermission): bool => $rolePermission->code === $permission', $user);
        $this->assertStringContainsString("'purchasing.purchase-orders.view'", $rbac);
        $this->assertStringContainsString("'purchasing.purchase-documents.post'", $rbac);
    }

    public function test_select_program_uses_separate_purchasing_and_wms_entry_routes(): void
    {
        $seeder = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));
        $context = file_get_contents(base_path('app/Modules/Platform/Controllers/ContextController.php'));
        $entry = file_get_contents(base_path('app/Modules/Platform/Controllers/EntryController.php'));

        $this->assertStringContainsString("'code' => 'wms'", $seeder);
        $this->assertStringContainsString("'entry_route' => 'purchasing.index'", $seeder);
        $this->assertStringContainsString("'entry_route' => 'wms.index'", $seeder);
        $this->assertStringContainsString('canonicalEntryRoute', $context);
        $this->assertStringContainsString("'wms'", $context);
        $this->assertStringContainsString("'wms'", $entry);

        $routeNames = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): ?string => $route->getName())
            ->filter()
            ->values();
        $this->assertTrue($routeNames->contains('purchasing.index'));
        $this->assertTrue($routeNames->contains('wms.index'));
    }

    public function test_receipt_snapshot_boundary_carries_reconciliation_proof(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Support/GoodsReceiptInventoryPostingContract.php'));
        foreach ([
            "'rounding_delta' => (string) \$line->rounding_delta",
            '$expectedStockQuantity',
            'purchase quantity × factor',
            'ต้นทุนรวมไม่ตรงกับ stock unit cost และ rounding delta',
            "snapshot['business_date']",
            "snapshot['purchase_uom_id']",
            "snapshot['stock_uom_id']",
        ] as $proof) {
            $this->assertStringContainsString($proof, $source);
        }
    }

    public function test_goods_receipt_migration_uses_recoverable_short_index(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_08_22_324000_create_goods_receipts_tables.php'));
        $this->assertStringContainsString("'grl_receipt_po_line_uq'", $migration);
        $this->assertStringContainsString("Schema::hasTable('goods_receipt_lines')", $migration);
    }

    public function test_optional_mockup_is_not_part_of_the_default_seed_and_never_posts(): void
    {
        $databaseSeeder = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));
        $this->assertStringNotContainsString('PurchasingGoodsReceiptMockupSeeder', $databaseSeeder);

        $mockup = file_get_contents(base_path('database/seeders/PurchasingGoodsReceiptMockupSeeder.php'));
        $this->assertStringContainsString("'status' => 'DRAFT'", $mockup);
        $this->assertStringNotContainsString('JournalPostingService', $mockup);
        $this->assertStringNotContainsString('InventoryPost', $mockup);
        $this->assertStringNotContainsString('StockMovement', $mockup);
    }
}
