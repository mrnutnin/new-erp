<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmCustomerDuplicateReviewContractTest extends TestCase
{
    #[Test]
    public function duplicate_review_is_read_only_permissioned_and_server_paginated():void
    {
        $controller=file_get_contents(base_path('app/Modules/Crm/Controllers/CustomerController.php'));
        $routes=file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $view=file_get_contents(base_path('app/Modules/Crm/Views/customers/duplicates.blade.php'));
        self::assertStringContainsString("[CustomerController::class, 'duplicateReview']",$routes);
        self::assertStringContainsString("[CustomerController::class, 'duplicateData']",$routes);
        self::assertStringContainsString('permission:crm.customers.update',$routes);
        self::assertStringContainsString('paginate(12)',$controller);
        self::assertStringContainsString('GROUP_CONCAT(parties.id',$controller);
        self::assertStringContainsString("'TAX','PHONE','EMAIL','NAME'",$controller);
        self::assertStringContainsString('history.pushState',$view);
        self::assertStringContainsString('ยังไม่มีการรวม เปลี่ยนแปลง หรือลบข้อมูลอัตโนมัติ',$view);
    }

    #[Test]
    public function duplicate_merge_is_separately_permissioned_transactional_and_audited():void
    {
        $controller=file_get_contents(base_path('app/Modules/Crm/Controllers/CustomerController.php'));
        $routes=file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $migration=file_get_contents(base_path('database/migrations/2026_09_17_210000_create_crm_customer_duplicate_resolutions.php'));
        self::assertStringContainsString('permission:crm.customers.merge',$routes);
        self::assertStringContainsString("[CustomerController::class, 'mergePreview']",$routes);
        self::assertStringContainsString("[CustomerController::class, 'merge']",$routes);
        self::assertStringContainsString('lockForUpdate()',$controller);
        self::assertStringContainsString('hasExternalBusinessHistory',$controller);
        self::assertStringContainsString("crm.customer.merged",$controller);
        self::assertStringContainsString("crm.customer.duplicate-dismissed",$controller);
        self::assertStringContainsString("\$table->char('signature',64)->unique()",$migration);
    }

    #[Test]
    public function review_normalizes_phone_email_and_labels_confidence():void
    {
        $controller=file_get_contents(base_path('app/Modules/Crm/Controllers/CustomerController.php'));
        $cards=file_get_contents(base_path('app/Modules/Crm/Views/customers/_duplicate-cards.blade.php'));
        self::assertStringContainsString("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(parties.phone",$controller);
        self::assertStringContainsString('LOWER(TRIM(parties.email))',$controller);
        self::assertStringContainsString("CONCAT(parties.tax_id, '|'",$controller);
        self::assertStringContainsString("\$name=\$group('NAME','parties.normalized_name')",$controller);
        self::assertStringContainsString('ความมั่นใจสูงมาก',$cards);
        self::assertStringContainsString('ต้องตรวจสอบ',$cards);
        self::assertStringContainsString("'parties' => ['normalized_name', 'deleted_at']",file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php')));
    }
}
