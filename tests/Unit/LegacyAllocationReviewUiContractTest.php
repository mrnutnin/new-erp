<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\LegacyAllocationReviewDecisionContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LegacyAllocationReviewUiContractTest extends TestCase
{
    public function test_review_contract_is_read_only_and_has_no_auto_promote_state(): void
    {
        $contract = new LegacyAllocationReviewDecisionContract;

        $this->assertSame('REVIEW_REQUIRED', $contract->normalize(' review_required '));
        $this->assertSame('ESCALATE', $contract->normalize('escalate'));
        $this->assertFalse($contract->isMutationAllowed());
    }

    public function test_unknown_decision_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LegacyAllocationReviewDecisionContract)->normalize('POSTED');
    }

    public function test_review_datatable_uses_shared_defaults_and_html5_export(): void
    {
        $view = file_get_contents(__DIR__.'/../../app/Modules/Wms/Views/legacy-allocation-reviews/index.blade.php');

        $this->assertStringContainsString('window.erpDataTableDefaults', $view);
        $this->assertStringContainsString('window.erpExcelButton(table)', $view);
        $this->assertStringContainsString('serverSide: true', $view);
        $this->assertStringContainsString('ตรวจสอบ Legacy Allocation', $view);
    }

    public function test_review_decision_endpoints_require_approve_permission_separate_from_view(): void
    {
        $routes = file_get_contents(__DIR__.'/../../app/Modules/Wms/Routes/web.php');

        $this->assertStringContainsString("Route::get('/legacy-allocation-reviews/{review}', [LegacyAllocationReviewController::class, 'show'])->middleware('permission:wms.cost-allocation-reviews.view')", $routes);
        $this->assertStringContainsString("Route::post('/legacy-allocation-reviews/{review}/resolve', [LegacyAllocationReviewController::class, 'resolve'])->middleware('permission:wms.cost-allocation-reviews.approve')", $routes);
        $this->assertStringContainsString("Route::post('/legacy-allocation-reviews/{review}/approve-no-action', [LegacyAllocationReviewController::class, 'approveNoAction'])->middleware('permission:wms.cost-allocation-reviews.approve')", $routes);

        $view = file_get_contents(__DIR__.'/../../app/Modules/Wms/Views/legacy-allocation-reviews/show.blade.php');
        $this->assertStringContainsString("hasPermission('wms.cost-allocation-reviews.approve')", $view);
        $this->assertStringContainsString('ไม่มีสิทธิ์ตัดสินใจ', $view);
    }
}
