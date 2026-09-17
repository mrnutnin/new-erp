<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmPosLifecycleSyncContractTest extends TestCase
{
    #[Test]
    public function posted_sales_mark_linked_opportunities_won_and_notify_idempotently():void
    {
        $service=file_get_contents(base_path('app/Modules/Crm/Services/OpportunityPosLifecycleService.php'));
        $posting=file_get_contents(base_path('app/Modules/Pos/Services/PhysicalSalePostingService.php'));
        $notifications=file_get_contents(base_path('app/Modules/Crm/Services/CrmNotificationService.php'));
        self::assertStringContainsString("source_type!=='SALES_ORDER'",$service);
        self::assertStringContainsString("where('sales_intake_id',\$intakeId)",$service);
        self::assertStringContainsString("['WON','LOST']",$service);
        self::assertStringContainsString("'stage'=>'WON'",$service);
        self::assertStringContainsString("'probability'=>100",$service);
        self::assertStringContainsString("'crm.opportunity.won-from-pos'",$service);
        self::assertStringContainsString('DB::afterCommit',$service);
        self::assertStringContainsString('crmLifecycle->markWon',$posting);
        self::assertStringContainsString('crm:won:sale:{$saleId}',$notifications);
        self::assertStringContainsString('OPPORTUNITY_WON',$notifications);
    }

    #[Test]
    public function cancelled_sales_notify_without_reverting_the_opportunity():void
    {
        $service=file_get_contents(base_path('app/Modules/Crm/Services/OpportunityPosLifecycleService.php'));
        $cancellation=file_get_contents(base_path('app/Modules/Pos/Services/PhysicalSaleCancellationService.php'));
        $notifications=file_get_contents(base_path('app/Modules/Crm/Services/CrmNotificationService.php'));
        self::assertStringContainsString('crmLifecycle->notifyCancellation',$cancellation);
        self::assertStringContainsString('sendOpportunitySaleCancelled',$service);
        self::assertStringContainsString('crm:sale-cancelled:{$saleId}:{$revision}',$notifications);
        self::assertStringContainsString('SALE_CANCELLED',$notifications);
        self::assertStringNotContainsString("'stage'=>'PROPOSAL'",$service);
    }
}
