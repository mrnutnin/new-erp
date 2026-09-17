<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmQuickLogUiContractTest extends TestCase
{
    #[Test]
    public function customer_quick_log_prefills_existing_validated_activity_form(): void
    {
        $show = file_get_contents(base_path('app/Modules/Crm/Views/customers/show.blade.php'));

        foreach (['ติดต่อไม่ได้', 'ขอให้โทรกลับ', 'ส่งข้อมูลแล้ว', 'ติดต่อสำเร็จ'] as $template) {
            self::assertStringContainsString($template, $show);
        }
        self::assertStringContainsString("data-due=\"tomorrow\"", $show);
        self::assertStringContainsString("form.find('[name=\"type\"]')", $show);
        self::assertStringContainsString("form.find('[name=\"subject\"]')", $show);
        self::assertStringContainsString("form.find('[name=\"details\"]')", $show);
        self::assertStringContainsString("aria-pressed=\"false\"", $show);
        self::assertStringContainsString("route('crm.opportunities.activities.store',\$opportunity)", $show);
    }
}
