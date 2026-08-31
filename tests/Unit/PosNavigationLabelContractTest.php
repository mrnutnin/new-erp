<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosNavigationLabelContractTest extends TestCase
{
    public function test_pos_uses_central_navigation_and_document_cancellation_labels(): void
    {
        $layout = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Views/layout.blade.php');

        self::assertStringContainsString("setLabel(element, 'ย้อนกลับ')", $layout);
        self::assertStringContainsString("setLabel(element, 'ยกเลิกเอกสาร')", $layout);
        self::assertStringContainsString('new MutationObserver(normalizeLabels)', $layout);
    }
}
