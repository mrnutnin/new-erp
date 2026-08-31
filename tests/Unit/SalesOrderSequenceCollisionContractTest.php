<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesOrderSequenceCollisionContractTest extends TestCase
{
    public function test_sales_order_sources_issue_branch_scoped_numbers(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesOrderController.php');

        self::assertSame(3, substr_count($controller, 'issueOrderNumber($sequences, $sequence, $request,'));
        self::assertStringContainsString('issueAvailableForBranch($sequence, $request->attributes->get(\'selectedBranch\')', $controller);
        self::assertStringContainsString('public function issueAvailableForBranch(', file_get_contents($root.'/app/Modules/Finance/Services/DocumentSequenceService.php'));
        self::assertStringContainsString('issueAvailableForBranch($sequence, $r->attributes->get(\'selectedBranch\')', file_get_contents($root.'/app/Modules/Pos/Controllers/SalesRfqController.php'));
    }
}
