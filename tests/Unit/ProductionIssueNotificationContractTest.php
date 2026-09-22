<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionIssueNotificationContractTest extends TestCase
{
    public function test_production_issue_notification_supports_database_and_web_push(): void
    {
        $notification = file_get_contents(__DIR__.'/../../app/Modules/Production/Notifications/ProductionIssueReportedNotification.php');
        $controller = file_get_contents(__DIR__.'/../../app/Modules/Production/Controllers/OrderController.php');

        self::assertStringContainsString("['database', WebPushChannel::class]", $notification);
        self::assertStringContainsString("'kind' => 'PRODUCTION_ISSUE'", $notification);
        self::assertStringContainsString("production.orders.view", $controller);
        self::assertStringContainsString('whereKey($order->branch_id)', $controller);
        self::assertStringContainsString('whereKey($order->issue_warehouse_id)', $controller);
    }
}
