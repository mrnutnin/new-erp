<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmCustomerAssignmentContractTest extends TestCase
{
    #[Test]
    public function assignment_is_branch_scoped_permissioned_and_installer_managed():void
    {
        $migration=file_get_contents(base_path('database/migrations/2026_09_17_230000_create_crm_customer_assignments.php'));
        $routes=file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $schema=file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));
        $rbac=file_get_contents(base_path('database/seeders/RbacSeeder.php'));
        self::assertStringContainsString("\$table->unique(['party_id','branch_id'])",$migration);
        self::assertStringContainsString('permission:crm.customers.assign',$routes);
        self::assertStringContainsString("[CustomerAssignmentController::class, 'update']",$routes);
        self::assertStringContainsString("'crm_customer_assignments' =>",$schema);
        self::assertStringContainsString("'crm.customers.assign' =>",$rbac);
    }

    #[Test]
    public function assignment_validates_branch_team_and_distinct_backup_and_audits():void
    {
        $request=file_get_contents(base_path('app/Modules/Crm/Requests/SaveCustomerAssignmentRequest.php'));
        $controller=file_get_contents(base_path('app/Modules/Crm/Controllers/CustomerAssignmentController.php'));
        $view=file_get_contents(base_path('app/Modules/Crm/Views/customers/show.blade.php'));
        self::assertStringContainsString("'different:owner_id'",$request);
        self::assertStringContainsString("from('user_branch')",$request);
        self::assertStringContainsString("where('branch_id',\$branchId)",$request);
        self::assertStringContainsString('lockForUpdate()',$controller);
        self::assertStringContainsString('ต้องเป็นสมาชิกทีมที่เลือก',$controller);
        self::assertStringContainsString('crm.customer.assignment-updated',$controller);
        self::assertStringContainsString('crm.customers.assignment.user-options',$view);
        self::assertStringContainsString('window.erpInitSelect2',$view);
        self::assertStringContainsString("CustomerAssignment::query()->where('party_id',\$source->id)",file_get_contents(base_path('app/Modules/Crm/Controllers/CustomerController.php')));
    }
}
