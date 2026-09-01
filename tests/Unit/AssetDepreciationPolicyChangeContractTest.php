<?php

namespace Tests\Unit;

use App\Modules\Asset\Services\AssetDepreciationPolicyChangeService;
use PHPUnit\Framework\TestCase;

final class AssetDepreciationPolicyChangeContractTest extends TestCase
{
    public function test_policy_changes_are_per_book_and_stay_prospective(): void
    {
        $service = file_get_contents((new \ReflectionClass(AssetDepreciationPolicyChangeService::class))->getFileName());

        self::assertStringContainsString("whereDate('start_date', \$date)", $service);
        self::assertStringContainsString("where('status', 'OPEN')", $service);
        self::assertStringContainsString('$baseline = $this->baseline', $service);
        self::assertStringContainsString("'accumulated_depreciation' => (string) \$book->accumulated_depreciation", $service);
        self::assertStringContainsString("'remaining_book_value'", $service);
        self::assertStringContainsString("\$book->book_type === 'BOOK'", $service);
        self::assertStringContainsString('BigDecimal::of($asset->book_value)', $service);
        self::assertStringNotContainsString('AssetDepreciationRunService', $service);
    }

    public function test_draft_policy_change_can_be_cancelled_without_affecting_an_approved_policy(): void
    {
        $service = file_get_contents((new \ReflectionClass(AssetDepreciationPolicyChangeService::class))->getFileName());

        self::assertStringContainsString("if (\$change->status !== 'DRAFT')", $service);
        self::assertStringContainsString("'status' => 'VOID'", $service);
        self::assertStringContainsString("'cancellation_reason' => \$reason", $service);
    }
}
