<?php

namespace Tests\Unit;

use App\Modules\Production\Controllers\OrderController;
use App\Modules\Production\Support\ProductionDemandQuery;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PosProductionDemandContractTest extends TestCase
{
    public function test_queue_uses_legacy_or_current_flag_and_excludes_sales_and_active_works(): void
    {
        $original = Model::getConnectionResolver();
        $db = new Capsule;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        try {
            $query = ProductionDemandQuery::eligible(7);
            $sql = $query->toSql();
            self::assertStringContainsString('production_requested_at', $sql);
            self::assertStringContainsString('production_legacy_eligible', $sql);
            self::assertStringContainsString('can_manufacture', $sql);
            self::assertStringContainsString('pos_physical_sales', $sql);
            self::assertStringContainsString('production_orders', $sql);
            self::assertStringNotContainsString('production_boms', $sql);
            self::assertContains(7, $query->getBindings());
            self::assertStringContainsString('production_bom_lines', ProductionDemandQuery::bomReadySql());
        } finally {
            if ($original) Model::setConnectionResolver($original);
            else Model::unsetConnectionResolver();
        }
    }

    public function test_sales_request_is_persisted_and_gatekeeps_planner_without_new_permissions(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root.'/database/migrations/2026_09_24_010000_add_sales_order_production_requests.php');
        $installer = file_get_contents($root.'/app/Modules/Installer/Services/DatabasePreparationService.php');
        $model = file_get_contents($root.'/app/Modules/Pos/Models/SalesOrderLine.php');
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesOrderController.php');
        $demand = file_get_contents($root.'/app/Modules/Production/Views/orders/demand.blade.php');
        $shop = file_get_contents($root.'/app/Modules/Production/Views/shop-floor/show.blade.php');
        foreach (['production_requested_at', 'production_requested_by', 'requested_delivery_date', 'requested_start_date', 'production_specification'] as $field) {
            self::assertStringContainsString($field, $migration);
            self::assertStringContainsString($field, $installer);
            self::assertStringContainsString($field, $model);
        }
        self::assertStringContainsString("dropForeign(['production_requested_by'])", $migration);
        self::assertStringContainsString('permission:pos.sales-orders.confirm', $routes);
        self::assertStringContainsString('requestProduction(', $controller);
        self::assertStringContainsString('lockForUpdate()', $controller);
        self::assertStringContainsString("'pos.sales-order.production-requested'", $controller);
        self::assertStringContainsString('production_specification', $demand);
        self::assertStringContainsString('render:function(value,type,row){return esc(value || row.description', $demand);
        self::assertStringContainsString('</i>สร้างใบสั่งผลิต</button>', $demand);
        self::assertStringContainsString('customer_specification', $shop);
    }

    public function test_planner_modal_saves_timeline_times_without_overwriting_customer_request(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/app/Modules/Production/Views/orders/demand.blade.php');
        $controller = file_get_contents($root.'/app/Modules/Production/Controllers/OrderController.php');
        $service = file_get_contents($root.'/app/Modules/Production/Services/ProductionOrderService.php');
        $timeline = file_get_contents($root.'/app/Modules/Production/Support/ProductionDayTimeline.php');
        self::assertStringContainsString('id="demand-create-modal"', $view);
        self::assertStringContainsString('id="demand-wo-customer-delivery"', $view);
        self::assertStringContainsString("$('#demand-wo-notes').val(btn.attr('data-spec') || '')", $view);
        self::assertStringNotContainsString('Swal.fire', $view);
        foreach (['planned_start_at', 'planned_finish_at', 'required_delivery_at', 'notes'] as $field) {
            self::assertStringContainsString('name="'.$field.'"', $view);
            self::assertStringContainsString("'".$field."' =>", $controller);
            self::assertStringContainsString("'".$field."' => \$plan['".$field."']", $service);
        }
        self::assertStringContainsString("'required_delivery_date' => \$line->requested_delivery_date", $service);
        self::assertStringContainsString('required_with:planned_finish_at', $controller);
        self::assertStringContainsString('after:planned_start_at', $controller);
        self::assertStringContainsString('planned_start_at?->getTimestamp()', $timeline);
        self::assertStringContainsString('planned_finish_at?->getTimestamp()', $timeline);
    }

    public function test_readiness_requires_stock_item_and_active_bom(): void
    {
        $method = new ReflectionMethod(OrderController::class, 'demandReadiness');
        $row = (object) ['item_code' => 'FG', 'item_deleted_at' => null, 'item_active' => 1, 'item_type' => 'GOODS', 'is_stock_item' => 1, 'base_uom_id' => 2, 'uom_id' => 2, 'has_active_bom' => 0];
        $controller = new OrderController;
        self::assertSame('NEEDS_BOM', $method->invoke($controller, $row));
        $row->has_active_bom = 1;
        self::assertSame('READY', $method->invoke($controller, $row));
        $row->is_stock_item = 0;
        self::assertSame('INVALID', $method->invoke($controller, $row));
    }

    public function test_server_checks_both_new_wo_guards_and_sale_creation_locks_the_order(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Modules/Production/Services/ProductionOrderService.php');
        $sale = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');
        $sidebar = file_get_contents($root.'/app/Modules/Production/Providers/ProductionServiceProvider.php');
        $controller = file_get_contents($root.'/app/Modules/Production/Controllers/OrderController.php');
        self::assertStringContainsString('! $order->production_legacy_eligible && ! $line->item->can_manufacture', $service);
        self::assertStringContainsString("$"."order->physicalSales()->where('status', '!=', 'VOID')->exists()", $service);
        self::assertStringContainsString("->where('status', 'CONFIRMED')->lockForUpdate()->first()", $sale);
        self::assertStringContainsString('ProductionDemandQuery::eligible($branchId)', $sidebar);
        self::assertStringContainsString('ProductionDemandQuery::eligible($branchId)', $controller);
        self::assertStringContainsString('! $line->production_requested_at', $service);
        // A deleted draft still occupies the (SO line, revision) unique key.
        self::assertStringContainsString("ProductionOrder::withTrashed()->where('sales_order_line_id', \$line->id)->max('source_revision')", $service);
        self::assertStringContainsString('requested_delivery_date', $service);
        self::assertStringContainsString('production_specification', $service);
        self::assertStringContainsString('assertCustomerStartDate($locked)', $service);
        self::assertStringContainsString('assertCustomerStartDate($order)', $service);
        self::assertStringContainsString("->when(isset(\$plan['bom_revision_id'])", $service);
        self::assertStringContainsString('CONCAT(FORMAT(sales_order_lines.quantity, ?)', $controller);
        self::assertStringContainsString('CONCAT(sales_orders.document_number', $controller);
    }
}
