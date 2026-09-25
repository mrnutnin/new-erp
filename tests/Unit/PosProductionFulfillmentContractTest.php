<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosProductionFulfillmentContractTest extends TestCase
{
    public function test_posted_sale_before_receipt_skips_finished_goods_reservation(): void
    {
        $receipt = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Wms/Services/ManualProductionReceiptPostingService.php');
        self::assertStringContainsString('$this->lockLinkedSalesOrder($locked);', $receipt);
        self::assertStringContainsString("->where('source_type', 'SALES_ORDER')->where('source_id', \$order->sales_order_id)", $receipt);
        self::assertStringContainsString("->where('status', 'POSTED')->whereNull('deleted_at')->lockForUpdate()->first(['id'])", $receipt);
        self::assertStringContainsString("if (\$done && \$order->order_type === 'MAKE_TO_ORDER'", $receipt);
        self::assertStringContainsString("'finished_goods_reserved'", $receipt);
    }

    public function test_different_warehouse_sale_releases_only_matching_finished_goods_after_post(): void
    {
        $root = dirname(__DIR__, 2);
        $posting = file_get_contents($root.'/app/Modules/Pos/Services/PhysicalSalePostingService.php');
        $reservations = file_get_contents($root.'/app/Modules/Wms/Services/StockReservationService.php');
        self::assertStringContainsString("DB::table('sales_orders')->where('id', \$sale->source_id)->lockForUpdate()", $posting);
        self::assertStringContainsString("'status' => 'POSTED'", $posting);
        self::assertStringContainsString('$this->releaseFinishedGoodsAtOtherWarehouses($sale, $lines);', $posting);
        self::assertStringContainsString("'sales-order-line:'.\$line->source_line_id.':finished-goods'", $posting);
        self::assertStringContainsString("->where('warehouse_id', '!=', \$sale->warehouse_id)->where('status', 'OPEN')", $posting);
        self::assertStringContainsString('if ($reservation) $this->reservations->release($reservation);', $posting);
        self::assertStringContainsString('public function release(StockReservation $reservation)', $reservations);
        self::assertStringContainsString('$this->reservations->consume($reservation', $posting);
    }

    public function test_sales_order_shows_only_authorized_valid_wo_actions(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesOrderController.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/sales-orders/show.blade.php');
        self::assertStringContainsString('$capabilities->isEnabled(ModuleCapability::PRODUCTION)', $controller);
        self::assertStringContainsString("->where('revision.status', 'ACTIVE')", $controller);
        self::assertStringContainsString("->whereExists(fn (\$q) => \$q->selectRaw('1')->from('production_bom_lines')", $controller);
        self::assertStringContainsString("auth()->user()->hasPermission('pos.sales-orders.confirm')", $view);
        self::assertStringNotContainsString("route('production.orders.store-from-demand'", $view);
        self::assertStringContainsString('$order->production_legacy_eligible || $line->item?->can_manufacture', $view);
        self::assertStringContainsString('data-quantity=', $view);
        self::assertStringContainsString("route('pos.sales-orders.production-request'", $view);
        self::assertStringContainsString('requested_delivery_date', $view);
    }
}
