<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosDocumentActionPatternContractTest extends TestCase
{
    public function test_document_actions_keep_primary_before_cancellation_and_back_last(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Pos/Views';
        $intake = file_get_contents("{$root}/sales-intakes/show.blade.php");
        $quotation = file_get_contents("{$root}/sales-quotations/show.blade.php");

        self::assertLessThan(strpos($intake, 'js-intake-cancel'), strpos($intake, 'สร้างใบเสนอราคา'));
        self::assertLessThan(strpos($intake, "route('pos.sales-intakes.index')"), strpos($intake, 'js-intake-cancel'));
        self::assertLessThan(strpos($quotation, "route('pos.sales-quotations.index')"), strpos($quotation, 'js-quotation-cancel'));
    }
}
