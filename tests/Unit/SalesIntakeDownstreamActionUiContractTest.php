<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesIntakeDownstreamActionUiContractTest extends TestCase
{
    public function test_active_quotation_hides_duplicate_downstream_actions(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Views/sales-intakes/show.blade.php');

        self::assertStringContainsString("(! \$x->quotation || \$x->quotation->status === 'CANCELLED')", $view);
        self::assertStringContainsString("! \$x->quotation && auth()->user()->hasPermission('pos.sales-orders.create')", $view);
    }
}
