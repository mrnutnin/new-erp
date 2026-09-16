<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosDocumentActionPatternContractTest extends TestCase
{
    public function test_document_actions_keep_back_first_and_cancellation_last(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Pos/Views';
        $intake = file_get_contents("{$root}/sales-intakes/show.blade.php");
        $quotation = file_get_contents("{$root}/sales-quotations/show.blade.php");

        self::assertLessThan(strpos($intake, 'js-intake-cancel'), strpos($intake, "route('pos.sales-intakes.index')"));
        self::assertLessThan(strpos($quotation, 'js-quotation-cancel'), strpos($quotation, "route('pos.sales-quotations.index')"));
    }
}
