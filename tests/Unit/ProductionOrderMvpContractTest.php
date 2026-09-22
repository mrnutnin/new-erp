<?php

namespace Tests\Unit;

use Tests\TestCase;

final class ProductionOrderMvpContractTest extends TestCase
{
    public function test_production_order_schema_routes_and_installer_are_present(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_18_050000_create_production_order_tables.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $sequence = file_get_contents(base_path('database/seeders/SystemDocumentSequenceSeeder.php'));
        $databaseSeeder = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        self::assertStringContainsString("Schema::create('production_orders'", $migration);
        self::assertStringContainsString("Schema::create('production_order_materials'", $migration);
        self::assertStringContainsString("required_delivery_date", $migration);
        self::assertStringContainsString("'production_orders' =>", $installer);
        self::assertStringContainsString('completed_quantity', $installer);
        self::assertStringContainsString('completed_at', $installer);
        self::assertStringContainsString("'wms_inventory_adjustment_documents' => ['document_context', 'source_issue_id'", $installer);
        self::assertStringContainsString("'wms_production_receipt_sources' =>", $installer);
        self::assertStringContainsString("'sales_orders' => ['required_delivery_date'", $installer);
        self::assertStringContainsString("Route::get('/demand'", $routes);
        self::assertStringContainsString("orders.store-from-demand", $routes);
        self::assertStringContainsString("'type' => 'PRODUCTION_ORDER'", $sequence);
        self::assertStringContainsString('$this->call(SystemDocumentSequenceSeeder::class);', $databaseSeeder);
    }

    public function test_shop_floor_cockpit_is_scoped_and_uses_prepare_permission(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $view = file_get_contents(base_path('app/Modules/Production/Views/shop-floor/index.blade.php'));
        $detail = file_get_contents(base_path('app/Modules/Production/Views/shop-floor/show.blade.php'));
        $plan = file_get_contents(base_path('PRODUCTION_MODULE_PLANING.md'));

        self::assertStringContainsString("Route::get('/shop-floor'", $routes);
        self::assertStringContainsString("Route::get('/shop-floor/{order}'", $routes);
        self::assertStringContainsString('permission:production.shop_floor.use', $routes);
        self::assertStringContainsString("where('issue_warehouse_id', \$warehouseId)", $controller);
        self::assertStringContainsString("whereIn('status', ['RELEASED', 'IN_PROGRESS'])", $controller);
        self::assertStringContainsString('issueStatuses', $controller);
        self::assertStringContainsString('รอ Supervisor', $view);
        self::assertStringContainsString('shop-floor-search', $view);
        self::assertStringContainsString('production.shop-floor.show', $view);
        self::assertStringContainsString('js-shop-create', $view);
        self::assertStringContainsString('inputmode="search"', $view);
        self::assertStringContainsString('สแกน QR/Barcode WO', $view);
        self::assertStringContainsString('shop-floor-action-modal', $view);
        self::assertStringContainsString('js-shop-return', $view);
        self::assertStringContainsString('js-shop-scrap', $view);
        self::assertStringContainsString('js-shop-receipt', $view);
        self::assertStringContainsString('production.orders.finished-receipt', $view);
        self::assertStringContainsString('js-shop-create', $view);
        self::assertStringContainsString('window.location.reload()', $view);
        self::assertStringNotContainsString('route(\'production.orders.show\', $order)', $view);
        self::assertStringContainsString('วัตถุดิบที่ต้องใช้', $detail);
        self::assertStringContainsString('js-shop-action', $detail);
        self::assertStringContainsString('ยืนยันเริ่มงานผลิต', $detail);
        self::assertStringContainsString('shop-floor-feedback', $detail);
        self::assertStringContainsString('aria-live="polite"', $detail);
        self::assertStringContainsString('Swal.fire', $detail);
        self::assertStringContainsString('confirmAction', $detail);
        self::assertStringContainsString('btn-lg', $detail);
        self::assertStringContainsString('role="status"', $detail);
        self::assertStringContainsString('ยังไม่พบใบเบิกวัตถุดิบที่ลง Stock แล้ว', $detail);
        self::assertStringContainsString("Route::post('/shop-floor/{order}/start'", $routes);
        self::assertStringContainsString('startFromShopFloor', $controller);
        self::assertStringContainsString('js-shop-create', $detail);
        self::assertStringContainsString('Shop Floor Operator Experience', $plan);
        self::assertStringContainsString('Flow ต้องจบได้ใน Cockpit เดียว', $plan);
    }

    public function test_planning_board_is_scoped_read_only_and_mobile_ready(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $view = file_get_contents(base_path('app/Modules/Production/Views/orders/planning.blade.php'));
        $plan = file_get_contents(base_path('PRODUCTION_MODULE_PLANING.md'));

        self::assertStringContainsString("Route::get('/planning'", $routes);
        self::assertStringContainsString("permission:production.orders.view", $routes);
        self::assertStringContainsString('where(\'issue_warehouse_id\', $warehouseId)', $controller);
        self::assertStringContainsString('limit(200)', $controller);
        self::assertStringContainsString('planning-date-from', $view);
        self::assertStringContainsString('แผนใช้เวลา', $view);
        self::assertStringContainsString('ใช้จริง', $view);
        self::assertStringContainsString('diffInMinutes', $view);
        self::assertStringContainsString('planning-responsible', $view);
        self::assertStringContainsString('production.orders.show', $view);
        self::assertStringContainsString('Production Planning Board', $plan);
        self::assertStringContainsString('ไม่ทำ finite-capacity scheduling', $plan);
    }

    public function test_wo_index_filters_are_server_side_and_export_scope_is_labeled(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $index = file_get_contents(base_path('app/Modules/Production/Views/orders/index.blade.php'));
        $datatable = file_get_contents(base_path('public/js/datatables.js'));

        self::assertStringContainsString("'status' => ['nullable', 'in:DRAFT,RELEASED,IN_PROGRESS,COMPLETED,CANCELLED']", $controller);
        self::assertStringContainsString("->when(\$filters['date_from']", $controller);
        self::assertStringContainsString('production-order-filters', $index);
        self::assertStringContainsString('reset-production-order-filters', $index);
        self::assertStringContainsString('filters.serializeArray()', $index);
        self::assertStringContainsString('ส่งออก Excel (หน้านี้)', $datatable);
    }

    public function test_make_to_stock_order_can_be_created_from_active_bom(): void
    {
        $service = file_get_contents(base_path('app/Modules/Production/Services/ProductionOrderService.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $index = file_get_contents(base_path('app/Modules/Production/Views/orders/index.blade.php'));
        $form = file_get_contents(base_path('app/Modules/Production/Views/orders/form.blade.php'));

        self::assertStringContainsString('public function createMakeToStock(', $service);
        self::assertStringContainsString('public function updateDraft(', $service);
        self::assertStringContainsString('public function deleteDraft(', $service);
        self::assertStringContainsString('production.order.deleted', $service);
        self::assertStringContainsString("'order_type' => 'MAKE_TO_STOCK'", $service);
        self::assertStringContainsString("BomRevision::query()->with(['bom', 'lines.substitutes'])", $service);
        self::assertStringContainsString('Active BOM', $form);
        self::assertStringContainsString('public function create(', $controller);
        self::assertStringContainsString('public function store(', $controller);
        self::assertStringContainsString('public function edit(', $controller);
        self::assertStringContainsString('public function update(', $controller);
        self::assertStringContainsString('public function destroy(', $controller);
        self::assertStringContainsString('deleteDraft($order', $controller);
        self::assertStringContainsString("Route::get('/orders/create'", $routes);
        self::assertStringContainsString("Route::post('/orders'", $routes);
        self::assertStringContainsString("Route::get('/orders/{order}/edit'", $routes);
        self::assertStringContainsString("Route::put('/orders/{order}'", $routes);
        self::assertStringContainsString("Route::delete('/orders/{order}'", $routes);
        self::assertStringContainsString('production.orders.create', $index);
        self::assertStringContainsString('สร้างใบสั่งผลิต', $form);
        self::assertStringContainsString('production.orders.update', $form);
        self::assertStringContainsString('@method(\'PUT\')', $form);
        self::assertStringContainsString('edit_url', $index);
        self::assertStringContainsString('ลบร่าง', $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php')));
        self::assertStringContainsString('production.orders.destroy', $show);
    }

    public function test_made_to_order_creation_reuses_one_command_and_guards_mvp_rules(): void
    {
        $service = file_get_contents(base_path('app/Modules/Production/Services/ProductionOrderService.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $demand = file_get_contents(base_path('app/Modules/Production/Views/orders/demand.blade.php'));
        $sidebar = file_get_contents(base_path('app/Modules/Production/Views/partials/sidebar.blade.php'));

        foreach (["status !== 'CONFIRMED'", "whereNot('status', 'CANCELLED')", 'base_uom_id', "document_type', 'PRODUCTION_ORDER'", "'order_type' => 'MAKE_TO_ORDER'", "'event_type' => 'created'"] as $contract) {
            self::assertStringContainsString($contract, $service);
        }
        self::assertStringContainsString('createFromSalesOrderLine($line', $controller);
        self::assertStringContainsString('whereExists', $controller);
        self::assertStringContainsString('whereNotExists', $controller);
        self::assertStringContainsString('js-create-wo', $demand);
        self::assertStringContainsString('คำสั่งขายรอผลิต', $sidebar);
    }

    public function test_release_uses_material_readiness_and_stays_inside_production_permission(): void
    {
        $service = file_get_contents(base_path('app/Modules/Production/Services/ProductionOrderService.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));

        self::assertStringContainsString('public function materialReadiness(', $service);
        self::assertStringContainsString('StockBalanceService', $service);
        self::assertStringContainsString('postedIssueQuantity(', $service);
        self::assertStringContainsString('postedReturnQuantity(', $service);
        self::assertStringContainsString('materialReadinessStatus(', $service);
        self::assertStringContainsString("'net_issued_quantity'", $service);
        self::assertStringContainsString("status !== 'DRAFT'", $service);
        self::assertStringContainsString("'status' => 'RELEASED'", $service);
        self::assertStringContainsString("'event_type' => 'released'", $service);
        self::assertStringContainsString('public function release(', $controller);
        self::assertStringContainsString("Route::post('/orders/{order}/release'", $routes);
        self::assertStringContainsString('permission:production.orders.release', $routes);
        self::assertStringContainsString('Material readiness', $show);
        self::assertStringContainsString('เบิกสุทธิ', $show);
        self::assertStringContainsString('NOT_READY', $show);
        self::assertStringContainsString('ISSUED', $show);
        self::assertStringContainsString('js-wo-action', $show);
        self::assertStringContainsString('disabled', $show);
    }

    public function test_draft_or_released_order_can_cancel_and_release_material_reservations(): void
    {
        $service = file_get_contents(base_path('app/Modules/Production/Services/ProductionOrderService.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));

        self::assertStringContainsString('public function cancel(', $service);
        self::assertStringContainsString("in_array($".'locked->status, [\'DRAFT\', \'RELEASED\']', $service);
        self::assertStringContainsString("where('status', 'POSTED')", $service);
        self::assertStringContainsString("where('source_type', 'PRODUCTION_ORDER')", $service);
        self::assertStringContainsString('->release($reservation)', $service);
        self::assertStringContainsString("'status' => 'CANCELLED'", $service);
        self::assertStringContainsString("'event_type' => 'cancelled'", $service);
        self::assertStringContainsString('public function cancel(', $controller);
        self::assertStringContainsString("Route::post('/orders/{order}/cancel'", $routes);
        self::assertStringContainsString('permission:production.orders.cancel', $routes);
        self::assertStringContainsString('ยกเลิกเอกสาร', $show);
    }

    public function test_released_order_can_reserve_materials_with_wms_reservation_service(): void
    {
        $service = file_get_contents(base_path('app/Modules/Production/Services/ProductionOrderService.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));

        self::assertStringContainsString('public function reserveMaterials(', $service);
        self::assertStringContainsString('StockReservationService', $service);
        self::assertStringContainsString("'source_type' => 'PRODUCTION_ORDER'", $service);
        self::assertStringContainsString('materialReservationKey(', $service);
        self::assertStringContainsString("'event_type' => 'materials_reserved'", $service);
        self::assertStringContainsString('reserved_quantity', $service);
        self::assertStringContainsString('shortage_quantity', $service);
        self::assertStringContainsString('public function reserveMaterials(', $controller);
        self::assertStringContainsString("Route::post('/orders/{order}/reserve-materials'", $routes);
        self::assertStringContainsString('production.execution.prepare', $routes);
        self::assertStringContainsString('จองวัตถุดิบ', $show);
    }

    public function test_released_order_can_create_one_wms_material_issue_from_snapshot(): void
    {
        $service = file_get_contents(base_path('app/Modules/Production/Services/ProductionOrderService.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));

        self::assertStringContainsString('public function createMaterialIssue(', $service);
        self::assertStringContainsString('IssueReturnService', $service);
        self::assertStringContainsString("status !== 'RELEASED'", $service);
        self::assertStringContainsString("'issue_type' => 'PRODUCTION'", $service);
        self::assertStringContainsString("'event_type' => 'material_issue_created'", $service);
        self::assertStringContainsString("whereIn('status', ['DRAFT', 'APPROVED', 'POSTED'])", $service);
        self::assertStringContainsString('materialReadiness($locked)', $service);
        self::assertStringContainsString("whereIn('status', ['DRAFT', 'APPROVED', 'POSTED'])", $service);
        self::assertStringContainsString('public function createMaterialIssue(', $controller);
        self::assertStringContainsString('public function approveMaterialIssue(', $controller);
        self::assertStringContainsString('public function postMaterialIssue(', $controller);
        self::assertStringContainsString('assertMaterialIssueBelongsToOrder(', $controller);
        self::assertStringContainsString("Route::post('/orders/{order}/material-issue'", $routes);
        self::assertStringContainsString("Route::post('/orders/{order}/material-issues/{document}/approve'", $routes);
        self::assertStringContainsString("Route::post('/orders/{order}/material-issues/{document}/post'", $routes);
        self::assertStringContainsString("Route::post('/orders/{order}/material-issues/{document}/reverse'", $routes);
        self::assertStringContainsString('production.execution.post', $routes);
        self::assertStringContainsString('production.execution.approve', $routes);
        self::assertStringContainsString('production.execution.reverse', $routes);
        self::assertStringContainsString('สร้างใบเบิกวัตถุดิบ', $show);
        self::assertStringContainsString('production.orders.material-issues.approve', $show);
        self::assertStringContainsString('production.orders.material-issues.post', $show);
        self::assertStringContainsString('production.orders.material-issues.reverse', $show);
        self::assertStringContainsString('js-reverse-material-issue', $show);
        self::assertStringContainsString('wms.production.material-issues.show', $show);

        $issueService = file_get_contents(base_path('app/Modules/Wms/Services/IssueReturnService.php'));
        self::assertStringContainsString('assertProductionMaterialIssueCanPost', $issueService);
        self::assertStringContainsString('1 WO มีใบเบิกวัตถุดิบได้เพียงใบเดียว', $issueService);
        $checklist = file_get_contents(base_path('PRODUCTION_MVP_CHECKLIST.md'));
        self::assertStringContainsString('Shop Floor ต้องยืนยันเริ่มงานก่อนเปิด Action ผลิตต่อ', $checklist);
        self::assertStringContainsString('Finished Receipt POST', $checklist);
        self::assertStringContainsString('ไม่อนุญาตให้มี Material Issue เพิ่ม', $checklist);
    }

    public function test_wo_detail_links_material_return_from_posted_material_issue(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));

        self::assertStringContainsString('IssueReturn', $controller);
        self::assertStringContainsString('$materialReturns', $controller);
        self::assertStringContainsString('production.orders.material-return', $show);
        self::assertStringContainsString('material-return-modal', $show);
        self::assertStringContainsString('material-return-form', $show);
        self::assertStringContainsString('คืนวัตถุดิบ', $show);
        self::assertStringContainsString('ใบรับคืนวัตถุดิบ', $show);
        self::assertStringContainsString('เอกสารที่เกี่ยวข้อง', $show);
    }

    public function test_voided_or_deleted_wo_material_issue_syncs_order_without_touching_manual_wms(): void
    {
        $service = file_get_contents(base_path('app/Modules/Wms/Services/IssueReturnService.php'));
        $productionController = file_get_contents(base_path('app/Modules/Wms/Controllers/ProductionMaterialIssueController.php'));
        $genericController = file_get_contents(base_path('app/Modules/Wms/Controllers/IssueReturnController.php'));

        self::assertStringContainsString('public function syncProductionOrderAfterMaterialIssueVoided(', $service);
        self::assertStringContainsString("issue_type !== 'PRODUCTION'", $service);
        self::assertStringContainsString("where('event_type', 'material_issue_created')", $service);
        self::assertStringContainsString("where('status', 'POSTED')", $service);
        self::assertStringContainsString("'status' => 'RELEASED'", $service);
        self::assertStringContainsString('material_issue_cancelled', $service);
        self::assertStringContainsString('material_issue_deleted', $productionController);
        self::assertStringContainsString('syncProductionOrderAfterMaterialIssueVoided', $productionController);
        self::assertStringContainsString('syncProductionOrderAfterMaterialIssueVoided', $genericController);
    }

    public function test_posted_wo_material_issue_moves_order_to_in_progress_without_coupling_manual_wms(): void
    {
        $service = file_get_contents(base_path('app/Modules/Wms/Services/IssueReturnService.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));

        self::assertStringContainsString('private function syncProductionOrderInProgress(', $service);
        self::assertStringContainsString("issue_type !== 'PRODUCTION'", $service);
        self::assertStringContainsString("Schema::hasTable('production_order_events')", $service);
        self::assertStringContainsString("where('event_type', 'material_issue_created')", $service);
        self::assertStringContainsString("where('status', 'RELEASED')", $service);
        self::assertStringContainsString("'status' => 'IN_PROGRESS'", $service);
        self::assertStringContainsString("'event_type' => 'material_issue_posted'", $service);
        self::assertStringContainsString('private function productionMaterialReservation(', $service);
        self::assertStringContainsString('StockReservationService::class)->consume', $service);
        self::assertStringContainsString('public function reverseIssue(', $service);
        self::assertStringContainsString('syncProductionOrderAfterMaterialIssueReversed', $service);
        self::assertStringContainsString('material_issue_reversed', $service);
        self::assertStringContainsString("'status' => 'RELEASED'", $service);
        self::assertStringContainsString("where('source_type', 'PRODUCTION_ORDER')", $service);
        self::assertStringContainsString('production_order_materials', $service);
        self::assertStringContainsString('รับสินค้าผลิตเสร็จ', $show);
        self::assertStringContainsString('production.orders.finished-receipt', $show);
        self::assertStringContainsString('js-finished-receipt', $show);
        self::assertStringContainsString('$materialIssue?->status === \'POSTED\'', $show);
    }

    public function test_wo_can_create_recoverable_scrap_receipt_draft_from_available_wip(): void
    {
        $service = file_get_contents(base_path('app/Modules/Production/Services/ProductionOrderService.php'));
        $postingService = file_get_contents(base_path('app/Modules/Wms/Services/ProductionScrapReceiptService.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));
        $sequence = file_get_contents(base_path('database/seeders/SystemDocumentSequenceSeeder.php'));

        self::assertStringContainsString('createRecoverableScrapReceipt(', $service);
        self::assertStringContainsString("'document_context' => 'PRODUCTION_SCRAP_RECEIPT'", $service);
        self::assertStringContainsString("'scrap_type' => 'RECOVERABLE_SCRAP'", $service);
        self::assertStringContainsString('recovery_total_value', $service);
        self::assertStringContainsString('Available WIP', file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php')));
        self::assertStringContainsString('recoverable_scrap_receipt_created', $service);
        self::assertStringContainsString('PRODUCTION_SCRAP_RECEIPT', $sequence);
        self::assertStringContainsString('public function createRecoverableScrapReceipt(', $controller);
        self::assertStringContainsString("Route::post('/orders/{order}/recoverable-scrap-receipt'", $routes);
        self::assertStringContainsString('รับเศษผลิต', $show);
        self::assertStringContainsString('final class ProductionScrapReceiptService', $postingService);
        self::assertStringContainsString('public function approve(', $postingService);
        self::assertStringContainsString('public function post(', $postingService);
        self::assertStringContainsString('public function reverse(', $postingService);
        self::assertStringContainsString('ProductionScrapReceiptPostingContract::plan', $postingService);
        self::assertStringContainsString('ProductionScrapReceiptReversalContract::plan', $postingService);
        self::assertStringContainsString('scrap_receipt_posted', $postingService);
        self::assertStringContainsString('scrap_receipt_reversed', $postingService);
        self::assertStringContainsString("Route::post('/orders/{order}/recoverable-scrap-receipts/{document}/approve'", $routes);
        self::assertStringContainsString("Route::post('/orders/{order}/recoverable-scrap-receipts/{document}/post'", $routes);
        self::assertStringContainsString("Route::post('/orders/{order}/recoverable-scrap-receipts/{document}/reverse'", $routes);
        self::assertStringContainsString('ลง Stock', $show);
        self::assertStringContainsString('ยกเลิกเอกสาร', $show);
    }

    public function test_wo_can_report_non_recoverable_scrap_without_stock_movement(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_18_050000_create_production_order_tables.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));
        $model = file_get_contents(base_path('app/Modules/Production/Models/ProductionOrderScrap.php'));
        $service = file_get_contents(base_path('app/Modules/Production/Services/ProductionOrderService.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));

        self::assertStringContainsString("Schema::create('production_order_scraps'", $migration);
        self::assertStringContainsString("'production_order_scraps' =>", $installer);
        self::assertStringContainsString('final class ProductionOrderScrap', $model);
        self::assertStringContainsString('reportNonRecoverableScrap(', $service);
        self::assertStringContainsString("'scrap_type' => 'NON_RECOVERABLE_SCRAP'", $service);
        self::assertStringContainsString("'event_type' => 'non_recoverable_scrap_reported'", $service);
        self::assertStringContainsString('public function reportNonRecoverableScrap(', $controller);
        self::assertStringContainsString("Route::post('/orders/{order}/non-recoverable-scrap'", $routes);
        self::assertStringContainsString('บันทึกของเสีย', $show);
        self::assertStringContainsString('ของเสียไม่มีมูลค่า', $show);
        self::assertStringContainsString('data-material-lines=', $show);
        self::assertStringContainsString('source_material_line_id:sourceMaterialLineId', $show);
    }

    public function test_wo_detail_shows_scoped_wip_material_cost_summary(): void
    {
        $service = file_get_contents(base_path('app/Modules/Production/Services/ProductionOrderService.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));

        self::assertStringContainsString('public function wipSummary(', $service);
        self::assertStringContainsString('wms_cost_allocations', $service);
        self::assertStringContainsString('wms_issue_return_line_allocations', $service);
        self::assertStringContainsString('PRODUCTION_RECEIPT', $service);
        self::assertStringContainsString('PRODUCTION_SCRAP_RECEIPT', $service);
        self::assertStringContainsString("'available' =>", $service);
        self::assertStringContainsString('$wipSummary = $service->wipSummary($materialIssue);', $controller);
        self::assertStringContainsString('production.orders.cost.view', $show);
        self::assertStringContainsString('WIP material cost', $show);
        self::assertStringContainsString('Available WIP', $show);
        self::assertStringContainsString('$scrapReceipts', $controller);
        self::assertStringContainsString('ใบรับเศษผลิต', $show);
    }

    public function test_material_return_post_and_reverse_are_recorded_against_wo(): void
    {
        $service = file_get_contents(base_path('app/Modules/Wms/Services/IssueReturnService.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));

        self::assertStringContainsString('private function syncProductionOrderMaterialReturnEvent(', $service);
        self::assertStringContainsString('material_return_posted', $service);
        self::assertStringContainsString('material_return_reversed', $service);
        self::assertStringContainsString('IssueReturn::class', $service);
        self::assertStringContainsString("where('event_type', 'material_issue_created')", $service);
        self::assertStringContainsString('issue_document_id', $service);
        self::assertStringContainsString("where('idempotency_key', \$idempotencyKey)", $service);
        self::assertStringContainsString("material-return:issue:'", $controller);
        self::assertStringContainsString('public function createMaterialReturn(', $controller);
        self::assertStringContainsString('materialReturnLines(', $controller);
        self::assertStringContainsString('public function approveMaterialReturn(', $controller);
        self::assertStringContainsString('public function postMaterialReturn(', $controller);
        self::assertStringContainsString("Route::post('/orders/{order}/material-return'", $routes);
        self::assertStringContainsString("Route::post('/orders/{order}/material-returns/{document}/approve'", $routes);
        self::assertStringContainsString("Route::post('/orders/{order}/material-returns/{document}/post'", $routes);
        self::assertStringContainsString("Route::post('/orders/{order}/material-returns/{document}/reverse'", $routes);
        self::assertStringContainsString('public function reverseMaterialReturn(', $controller);
        self::assertStringContainsString('assertMaterialReturnBelongsToOrder', $controller);
        self::assertStringContainsString('js-reverse-material-return', $show);
        self::assertStringContainsString('material-return-form', $show);
    }

    public function test_wo_detail_shows_audit_timeline(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));
        $checklist = file_get_contents(base_path('PRODUCTION_MVP_CHECKLIST.md'));

        self::assertStringContainsString("'events.creator'", $controller);
        self::assertStringContainsString('Audit timeline', $show);
        self::assertStringContainsString('$eventLabels', $show);
        self::assertStringContainsString('$order->events->sortByDesc(\'occurred_at\')', $show);
        self::assertStringContainsString('json_encode($event->payload', $show);
        self::assertStringContainsString('- [x] Audit timeline เต็มใน WO detail', $checklist);
    }

    public function test_wo_gl_preview_is_scoped_to_related_production_journals(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));
        $checklist = file_get_contents(base_path('PRODUCTION_MVP_CHECKLIST.md'));

        self::assertStringContainsString('public function journalPreview(', $controller);
        self::assertStringContainsString('productionJournalIds(', $controller);
        self::assertStringContainsString("where('source_type', 'WMS_ISSUE')", $controller);
        self::assertStringContainsString("where('source_type', 'WMS_ISSUE_RETURN')", $controller);
        self::assertStringContainsString("where('source_type', 'WMS_PRODUCTION_RECEIPT')", $controller);
        self::assertStringContainsString("where('source_type', 'WMS_PRODUCTION_SCRAP_RECEIPT')", $controller);
        self::assertStringContainsString("permission:production.orders.gl.view", $routes);
        self::assertStringContainsString('data-journal-preview-urls', $show);
        self::assertStringContainsString('ดู GL ทั้งหมด', $show);
        self::assertStringContainsString('Accounting proof / GL', $show);
        self::assertStringContainsString('$journalProofRows', $show);
        self::assertStringContainsString('JournalEntry::query()->whereIn', $controller);
        self::assertStringContainsString('- [x] GL preview เฉพาะ journals', $checklist);
        self::assertStringContainsString('- [x] accounting proof/GL', $checklist);
    }

    public function test_wo_embeds_finished_receipt_create_approve_and_post(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));
        $checklist = file_get_contents(base_path('PRODUCTION_MVP_CHECKLIST.md'));

        self::assertStringContainsString('public function createFinishedReceipt(', $controller);
        self::assertStringContainsString('ProductionFinishedReceiptDocumentService', $controller);
        self::assertStringContainsString('ProductionReceiptSourceAllocator', $controller);
        self::assertStringContainsString('finishedReceiptSourceRows(', $controller);
        self::assertStringContainsString('hasActiveFinishedReceipt(', $controller);
        self::assertStringContainsString('1 WO ต้องรับผลิตครั้งเดียวเท่ากับ Planned quantity', $controller);
        self::assertStringContainsString("'idempotency_key' => 'production-order:'.\$order->id.':finished-receipt:issue:'.\$issue->id", $controller);
        self::assertStringContainsString('ManualProductionReceiptPostingService', $controller);
        self::assertStringContainsString('$finishedReceiptPosting->preflight($receipt->toArray())', $controller);
        self::assertStringContainsString('public function approveFinishedReceipt(', $controller);
        self::assertStringContainsString('public function postFinishedReceipt(', $controller);
        self::assertStringContainsString("Route::post('/orders/{order}/finished-receipt'", $routes);
        self::assertStringContainsString("Route::post('/orders/{order}/finished-receipts/{document}/approve'", $routes);
        self::assertStringContainsString("Route::post('/orders/{order}/finished-receipts/{document}/post'", $routes);
        self::assertStringContainsString('js-finished-receipt', $show);
        self::assertStringContainsString('$finishedReceipts', $controller);
        self::assertStringContainsString('ใบรับสินค้าผลิตเสร็จ', $show);
        self::assertStringContainsString('รับสินค้าผลิตเสร็จ 1 ใบ เท่ากับ Planned quantity', $show);
        self::assertStringContainsString('Flow เอกสารผลิต', $show);
        self::assertStringContainsString('1 WO', $show);
        self::assertStringContainsString('1 ใบเบิกวัตถุดิบ', $show);
        self::assertStringContainsString('1 ใบรับผลิต', $show);
        self::assertStringContainsString('$statusLabels', $show);
        self::assertStringNotContainsString('จำนวนรับผลิตเสร็จ (เว้นว่าง = ยอดคงเหลือตามแผน)', $show);
        self::assertStringContainsString('Posting blocker', $show);
        self::assertStringContainsString('@disabled(!($ready[\'ready\'] ?? false))', $show);
        self::assertStringContainsString('- [x] Deterministic idempotency keys', $checklist);
        self::assertStringContainsString('- [x] finished receipt keys from WO/issue', $checklist);
    }

    public function test_posted_finished_receipt_completes_production_order_when_planned_quantity_is_reached(): void
    {
        $posting = file_get_contents(base_path('app/Modules/Wms/Services/ManualProductionReceiptPostingService.php'));
        $show = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));

        self::assertStringContainsString('private function syncProductionOrderCompletion(', $posting);
        self::assertStringContainsString('syncProductionOrderCompletion($locked, $actor)', $posting);
        self::assertStringContainsString("where('event_type', 'material_issue_created')", $posting);
        self::assertStringContainsString("where('wms_inventory_adjustment_documents.status', 'POSTED')", $posting);
        self::assertStringContainsString("'completed_quantity' => $", $posting);
        self::assertStringContainsString('\'status\' => $done ? \'COMPLETED\' : \'IN_PROGRESS\'', $posting);
        self::assertStringContainsString("'event_type' => 'finished_receipt_posted'", $posting);
        self::assertStringContainsString('productionOrderReceiptLimitBlockers', $posting);
        self::assertStringContainsString('PRODUCTION_ORDER_RECEIPT_NOT_PLANNED_QUANTITY', $posting);
        self::assertStringContainsString('PRODUCTION_ORDER_FINISHED_RECEIPT_EXISTS', $posting);
        self::assertStringContainsString('PRODUCTION_ORDER_PENDING_MATERIAL_RETURN', $posting);
        self::assertStringContainsString('PRODUCTION_ORDER_PENDING_SCRAP_RECEIPT', $posting);
        self::assertStringContainsString('StockReservation::SOURCE_SALES_ORDER_LINE', $posting);
        self::assertStringContainsString('finished_goods_reserved', $posting);
        self::assertStringContainsString("'idempotency_key' => 'sales-order-line:'", $posting);
        self::assertStringContainsString('WO นี้ปิดครบ 1 ใบเบิกวัตถุดิบ และ 1 ใบรับผลิตแล้ว', $show);
    }

    public function test_sales_order_cancel_is_blocked_when_active_wo_exists(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Pos/Controllers/SalesOrderController.php'));
        $checklist = file_get_contents(base_path('PRODUCTION_MVP_CHECKLIST.md'));

        self::assertStringContainsString('ProductionOrder::query()', $controller);
        self::assertStringContainsString('where(\'sales_order_id\', $order->id)', $controller);
        self::assertStringContainsString("where('status', '!=', 'CANCELLED')", $controller);
        self::assertStringContainsString('SALES_ORDER_CANCELLED', $controller);
        self::assertStringContainsString('Sales Order cancelled exception', $checklist);
    }

    public function test_sales_order_detail_shows_production_status_and_shortcut(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Pos/Controllers/SalesOrderController.php'));
        $show = file_get_contents(base_path('app/Modules/Pos/Views/sales-orders/show.blade.php'));

        self::assertStringContainsString('ProductionOrder::query()', $controller);
        self::assertStringContainsString("'productionOrders' => $", $controller);
        self::assertStringContainsString('$productionOrders->get($line->id)', $show);
        self::assertStringContainsString('Production', $show);
        self::assertStringContainsString('production.orders.show', $show);
        self::assertStringContainsString('production.orders.store-from-demand', $show);
        self::assertStringContainsString('สร้างใบสั่งผลิต', $show);
        self::assertStringContainsString('js-create-wo', $show);
    }

    public function test_reversing_finished_receipt_reopens_or_recomputes_production_order_completion(): void
    {
        $reversal = file_get_contents(base_path('app/Modules/Wms/Services/ProductionFinishedReceiptReversalService.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));

        self::assertStringContainsString('private function syncProductionOrderAfterReversal(', $reversal);
        self::assertStringContainsString('syncProductionOrderAfterReversal($locked, $actor)', $reversal);
        self::assertStringContainsString("where('event_type', 'material_issue_created')", $reversal);
        self::assertStringContainsString("where('wms_inventory_adjustment_documents.status', 'POSTED')", $reversal);
        self::assertStringContainsString('finished_receipt_reversed', $reversal);
        self::assertStringContainsString('StockReservation::SOURCE_SALES_ORDER_LINE', $reversal);
        self::assertStringContainsString('$this->reservations->release($reservation)', $reversal);
        self::assertStringContainsString('\'status\' => $done ? \'COMPLETED\' : \'IN_PROGRESS\'', $reversal);
        self::assertStringContainsString('completed_quantity', $installer);
        self::assertStringContainsString('completed_by', $installer);
    }
}

