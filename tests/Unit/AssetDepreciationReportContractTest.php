<?php

namespace Tests\Unit;

use App\Modules\Asset\Services\AssetDepreciationReportService;
use PHPUnit\Framework\TestCase;

final class AssetDepreciationReportContractTest extends TestCase
{
    public function test_reports_read_only_posted_book_and_tax_run_snapshots(): void
    {
        $service = file_get_contents((new \ReflectionClass(AssetDepreciationReportService::class))->getFileName());

        self::assertStringContainsString("runs.status', 'POSTED'", $service);
        self::assertStringContainsString("runs.book_type', \$bookType", $service);
        self::assertStringContainsString("\$lines('BOOK')", $service);
        self::assertStringContainsString("\$lines('TAX')", $service);
    }
}
