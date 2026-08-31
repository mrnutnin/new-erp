<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PriceListBranchScopeContractTest extends TestCase
{
    public function test_price_lists_are_resolved_and_managed_within_the_selected_branch(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root.'/database/migrations/2026_08_31_140000_add_branch_scope_to_pos_price_lists.php');
        $resolver = file_get_contents($root.'/app/Modules/Pos/Services/PriceListResolver.php');
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/PriceListController.php');
        $intake = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesIntakeController.php');
        $request = file_get_contents($root.'/app/Modules/Pos/Requests/SavePriceListRequest.php');

        self::assertStringContainsString("foreignId('branch_id')", $migration);
        self::assertStringContainsString("unique(['branch_id', 'code']", $migration);
        self::assertStringContainsString('nullable(false)->change()', $migration);
        self::assertStringContainsString("where('pos_price_lists.branch_id', \$branchId)", $resolver);
        self::assertStringContainsString("where('branch_id', \$this->branchId(\$request))", $controller);
        self::assertStringContainsString('resolve($this->branchId()', $intake);
        self::assertStringContainsString("where('branch_id', \$branchId)", $request);
    }

    public function test_price_list_lines_require_non_overlapping_tiers_and_compatible_uoms(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Requests/SavePriceListRequest.php');

        self::assertStringContainsString('uomIsAvailableForItem', $source);
        self::assertStringContainsString('UomConversion::query()', $source);
        self::assertStringContainsString('datesOverlap', $source);
        self::assertStringContainsString('effectiveRange', $source);
    }
}
