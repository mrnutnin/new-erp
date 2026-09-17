<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmOwnershipTransferContractTest extends TestCase
{
    #[Test]
    public function transfer_is_permissioned_scoped_previewed_and_audited():void
    {
        $routes=file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $controller=file_get_contents(base_path('app/Modules/Crm/Controllers/OwnershipTransferController.php'));
        $view=file_get_contents(base_path('app/Modules/Crm/Views/ownership-transfers/index.blade.php'));
        self::assertStringContainsString('permission:crm.customers.transfer-owner',$routes);
        self::assertStringContainsString("[OwnershipTransferController::class, 'preview']",$routes);
        self::assertStringContainsString("[OwnershipTransferController::class, 'store']",$routes);
        self::assertStringContainsString("['ALL','TEAM','SELECTED']",file_get_contents(base_path('app/Modules/Crm/Requests/TransferCustomerOwnershipRequest.php')));
        self::assertStringContainsString('lockForUpdate()',$controller);
        self::assertStringContainsString('crm.customer.ownership-transferred',$controller);
        self::assertStringContainsString('ตรวจสอบผลกระทบ',$view);
        self::assertStringContainsString('โอนเจ้าของและงาน',$view);
    }

    #[Test]
    public function transfer_only_moves_open_work_and_notifies_idempotently():void
    {
        $controller=file_get_contents(base_path('app/Modules/Crm/Controllers/OwnershipTransferController.php'));
        $notifications=file_get_contents(base_path('app/Modules/Crm/Services/CrmNotificationService.php'));
        self::assertStringContainsString("whereIn('stage',self::OPEN_STAGES)",$controller);
        self::assertStringContainsString("whereNull('completed_at')",$controller);
        self::assertStringContainsString('ผู้รับโอนต้องเป็นสมาชิกของทุกทีมที่ได้รับลูกค้า',$controller);
        self::assertStringContainsString('sendOwnershipTransfer',$notifications);
        self::assertStringContainsString("'crm:ownership-transfer:'.\$fingerprint",$notifications);
        self::assertStringContainsString("DB::afterCommit",$controller);
    }
}
