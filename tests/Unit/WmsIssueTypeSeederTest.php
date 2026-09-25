<?php

namespace Tests\Unit;

use Database\Seeders\WmsIssueTypeSeeder;
use PHPUnit\Framework\TestCase;

class WmsIssueTypeSeederTest extends TestCase
{
    public function test_wms_issue_types_include_the_requested_categories_and_are_installer_versioned(): void
    {
        $definitions = array_column(WmsIssueTypeSeeder::definitions(), null, 'code');

        foreach ([
            'DAMAGED_LOST' => 'เบิกตัดชำรุด/สูญหาย',
            'SAMPLE' => 'เบิกเป็นสินค้าตัวอย่าง',
            'MARKETING' => 'เบิกเพื่อสนับสนุนการตลาด',
            'MAINTENANCE' => 'เบิกซ่อมบำรุง',
        ] as $code => $name) {
            self::assertSame($name, $definitions[$code]['name'] ?? null);
        }

        $installer = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Installer/Services/SystemDefaultOrchestrator.php');
        self::assertStringContainsString("'wms.issue_types' => '1.3'", $installer);
    }
}
