<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class JournalPreviewUiContractTest extends TestCase
{
    public function test_gl_preview_is_a_neutral_detail_utility(): void
    {
        $root = dirname(__DIR__, 2);
        $guidance = file_get_contents($root.'/AGENTS.md');
        $settlement = file_get_contents($root.'/app/Modules/Finance/Views/settlements/show.blade.php');
        $sale = file_get_contents($root.'/app/Modules/Pos/Views/physical-sales/show.blade.php');
        $financeViews = implode('', array_map('file_get_contents', array_merge(
            glob($root.'/app/Modules/Finance/Views/*.blade.php'),
            glob($root.'/app/Modules/Finance/Views/*/*.blade.php'),
        )));

        self::assertStringContainsString('### GL preview controls', $guidance);
        self::assertLessThan(strpos($settlement, 'ดู GL'), strpos($settlement, 'กลับหน้ารายการ'));
        self::assertLessThan(strpos($settlement, 'ยกเลิกเอกสาร'), strpos($settlement, 'ดู GL'));
        self::assertStringContainsString('btn btn-app-soft', $sale);
        self::assertStringContainsString('ดู GL ทั้งหมด', $sale);
        self::assertStringNotContainsString('btn btn-app-danger" type="button" data-journal-preview-url', $financeViews);
    }
}
