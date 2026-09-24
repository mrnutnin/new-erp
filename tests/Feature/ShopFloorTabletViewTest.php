<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Production\Controllers\OrderController;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderMaterial;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ShopFloorTabletViewTest extends TestCase
{
    public function test_tablet_detail_renders_tabs_and_issue_reporting_for_active_orders(): void
    {
        $settings = \Mockery::mock(\App\Modules\Settings\Services\GlobalSettings::class);
        $settings->shouldReceive('value')->with('tax_decimal_places')->andReturn(2);
        $this->app->instance(\App\Modules\Settings\Services\GlobalSettings::class, $settings);
        $user = \Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasPermission')->andReturn(true);
        $this->actingAs($user);
        $order = (new ProductionOrder())->forceFill([
            'id' => 61, 'document_number' => 'WO-TABLET-61', 'planned_quantity' => 10,
            'materials_count' => 1, 'operations_count' => 0, 'completed_operations_count' => 0,
        ])->setRelations(['finishedItem' => null, 'uom' => null, 'bomRevision' => null]);
        $material = (new ProductionOrderMaterial())->forceFill(['line_number' => 1, 'required_quantity' => 10])
            ->setRelations(['item' => new Item(['code' => 'MAT-01', 'name' => '<script>alert(1)</script>']), 'uom' => null]);
        $empty = new LengthAwarePaginator([], 0, 5);
        $data = [
            'order' => $order, 'issue' => null, 'returnDocument' => null, 'scrapDocument' => null, 'receiptDocument' => null,
            'materials' => new LengthAwarePaginator([$material], 7, 6, 1, ['path' => '/production/shop-floor/61', 'pageName' => 'materials_page', 'fragment' => 'materials']),
            'operations' => $empty, 'productionIssues' => $empty, 'openIssuesCount' => 0,
            'materialReadiness' => ['ready' => false, 'rows' => [['line_number' => 1, 'available_quantity' => '0', 'shortage_quantity' => '10']]],
        ];
        foreach (['RELEASED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'] as $status) {
            $order->status = $status;
            $html = view('Production::shop-floor.show', $data)->render();
            self::assertStringContainsString('WO-TABLET-61', $html);
            self::assertSame(4, substr_count($html, 'role="tabpanel"'));
            self::assertStringContainsString('materials_page=2#materials', $html);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
            self::assertStringContainsString('WO นี้ไม่มี Routing', $html);
            self::assertSame(in_array($status, ['RELEASED', 'IN_PROGRESS'], true), str_contains($html, 'id="production-issue-modal"'));
        }
        $order->status = 'RELEASED';
        $reportedIssue = (new \App\Modules\Production\Models\ProductionOrderIssue())->forceFill(['id' => 7, 'status' => 'OPEN', 'severity' => 'LOW', 'description' => 'ต้องตรวจสอบเครื่องจักร'])
            ->setRelations(['reporter' => null, 'resolver' => null]);
        $data['productionIssues'] = new LengthAwarePaginator([$reportedIssue], 1, 5);
        $html = view('Production::shop-floor.show', $data)->render();
        self::assertStringContainsString('js-shop-resolve-issue', $html);
        self::assertStringContainsString('/production/shop-floor/issues/7/resolve', $html);
        self::assertStringContainsString('วัตถุดิบในคลังไม่พอ', $html);
        self::assertStringNotContainsString('สร้างร่างใบเบิกวัตถุดิบ</button>', $html);
        $order->status = 'IN_PROGRESS';
        $data['issue'] = (new IssueDocument())->forceFill(['id' => 1, 'status' => 'POSTED']);
        self::assertStringContainsString('ยืนยันเริ่มงานผลิต</button>', view('Production::shop-floor.show', $data)->render());
        $order->started_at = now();
        self::assertStringContainsString('สร้างร่างรับผลิตเสร็จ</button>', view('Production::shop-floor.show', $data)->render());

        $order->setRelation('events', collect())->setRelation('salesOrder', null);
        $orders = (new LengthAwarePaginator([$order], 5, 4, 1, ['path' => '/production/shop-floor']))->appends(['q' => 'WO', 'status' => 'IN_PROGRESS']);
        $html = view('Production::shop-floor.index', ['orders' => $orders, 'search' => 'WO', 'status' => 'IN_PROGRESS', 'issueStatuses' => collect(), 'receiptDocuments' => collect()])->render();
        self::assertStringContainsString('5 งาน', $html);
        self::assertStringContainsString('q=WO&amp;status=IN_PROGRESS&amp;page=2', $html);
        self::assertStringContainsString('/production/shop-floor/61', $html);
    }

    public function test_issue_report_retains_scope_and_validation_before_any_write(): void
    {
        foreach ([['RELEASED', 1, 1, 422], ['IN_PROGRESS', 1, 1, 422], ['COMPLETED', 1, 1, 422], ['CANCELLED', 1, 1, 422], ['DRAFT', 1, 1, 422], ['RELEASED', 2, 1, 404], ['RELEASED', 1, 2, 404]] as [$status, $branch, $warehouse, $expected]) {
            $request = Request::create('/production/shop-floor/61/issues', 'POST', ['severity' => 'INVALID', 'description' => 'short']);
            $request->attributes->set('selectedWarehouse', (object) ['id' => 1, 'branch_id' => 1]);
            $order = new ProductionOrder(['status' => $status, 'branch_id' => $branch, 'issue_warehouse_id' => $warehouse]);
            try {
                (new OrderController())->reportShopFloorIssue($request, $order);
                self::fail('Expected a validation or scope error before writing');
            } catch (ValidationException $e) {
                self::assertContains($status, ['RELEASED', 'IN_PROGRESS']);
                self::assertSame(['severity', 'description'], array_keys($e->errors()));
            } catch (HttpException $e) {
                self::assertSame($expected, $e->getStatusCode());
                if ($branch === 1 && $warehouse === 1) self::assertNotContains($status, ['RELEASED', 'IN_PROGRESS']);
            }
        }
    }
}
