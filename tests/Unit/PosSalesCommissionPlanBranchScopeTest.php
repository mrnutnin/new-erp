<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosSalesCommissionPlanBranchScopeTest extends TestCase
{
    public function test_plan_listing_and_mutations_are_scoped_to_the_selected_branch(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesCommissionPlanController.php');

        self::assertStringContainsString("whereHas('assignments'", $controller);
        self::assertStringContainsString("where('branch_id', \$this->branchId(\$request))", $controller);
        self::assertStringContainsString('private function scopedPlan', $controller);
        self::assertStringContainsString("where('branch_id', \$assignments[0]['branch_id'])->delete()", $controller);
    }

    public function test_assignment_form_is_branch_only_and_can_add_rows_on_create_or_edit(): void
    {
        $root = dirname(__DIR__, 2);
        $form = file_get_contents($root.'/app/Modules/Pos/Views/sales-commission-plans/form.blade.php');
        $request = file_get_contents($root.'/app/Modules/Pos/Requests/SaveSalesCommissionPlanRequest.php');

        self::assertStringContainsString('กำหนดผู้รับสำหรับสาขา', $form);
        self::assertStringContainsString('รายละเอียดแผนเป็นข้อมูลกลาง', $form);
        self::assertStringContainsString('name="assignments[__INDEX__][branch_id]"', $form);
        self::assertStringContainsString('template.replace(/__INDEX__/g,nextIndex++)', $form);
        self::assertStringNotContainsString('warehouse_id', $form);
        self::assertStringContainsString("\$assignment['branch_id'] = \$this->attributes->get('selectedBranch')?->id", $request);
        self::assertStringContainsString("'assignments.*.branch_id' => ['required'", $request);
    }

    public function test_used_plan_is_not_deletable_and_instead_requires_deactivation(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesCommissionPlanController.php');

        self::assertStringContainsString('commissionRecords()->exists()', $controller);
        self::assertStringContainsString('ไม่สามารถลบได้ กรุณาปิดใช้งานแทน', $controller);
        self::assertStringContainsString('commission_records_count === 0', $controller);
    }
}
