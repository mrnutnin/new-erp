<?php
namespace Tests\Unit;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
final class CrmSalesTeamContractTest extends TestCase
{
 #[Test] public function sales_teams_have_reversible_installer_managed_schema():void{$m=file_get_contents(base_path('database/migrations/2026_09_17_170000_create_crm_sales_teams.php'));$s=file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));self::assertStringContainsString("Schema::create('crm_sales_teams'",$m);self::assertStringContainsString("Schema::create('crm_sales_team_members'",$m);self::assertStringContainsString("Schema::dropIfExists('crm_sales_team_members')",$m);self::assertStringContainsString("'crm_sales_teams' =>",$s);}
 #[Test] public function management_is_permissioned_branch_scoped_unique_and_audited():void{$c=file_get_contents(base_path('app/Modules/Crm/Controllers/SalesTeamController.php'));$r=file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));self::assertStringContainsString('permission:crm.teams.manage',$r);self::assertStringContainsString("'branch_id'=>\$this->branchId(\$request)",$c);self::assertStringContainsString('assertAvailable',$c);self::assertStringContainsString('crm.sales-team.updated',$c);}
 #[Test] public function team_work_is_limited_to_managed_team_members_unless_view_all_is_granted():void{$c=file_get_contents(base_path('app/Modules/Crm/Controllers/TeamWorkController.php'));self::assertStringContainsString("hasPermission('crm.team-work.view-all')",$c);self::assertStringContainsString("->where('manager_id', \$request->user()->id)",$c);self::assertStringContainsString("->whereIn('assigned_to', \$memberIds)",$c);self::assertStringContainsString("->whereIn('owner_id', \$memberIds)",$c);}
}
