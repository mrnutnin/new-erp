<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmTeamWorkContractTest extends TestCase
{
    #[Test]
    public function team_work_is_permission_and_branch_scoped_with_bounded_ajax_pagination(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Crm/Controllers/TeamWorkController.php'));
        $routes = file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $seeder = file_get_contents(base_path('database/seeders/RbacSeeder.php'));

        self::assertStringContainsString("'crm.team-work.view'", $seeder);
        self::assertStringContainsString("permission:crm.team-work.view", $routes);
        self::assertStringContainsString("where('branch_id', \$this->branchId(\$request))", $controller);
        self::assertStringContainsString('paginate(12)', $controller);
        self::assertStringContainsString("Rule::in(['CALL', 'MEETING', 'TASK', 'NOTE'])", $controller);
    }

    #[Test]
    public function team_work_has_workload_user_filter_and_responsible_person_cards(): void
    {
        $index = file_get_contents(base_path('app/Modules/Crm/Views/team-work/index.blade.php'));
        $cards = file_get_contents(base_path('app/Modules/Crm/Views/my-work/_cards.blade.php'));
        $sidebar = file_get_contents(base_path('app/Modules/Crm/Views/partials/sidebar.blade.php'));

        self::assertStringContainsString('ภาระงานรายคน', $index);
        self::assertStringContainsString('erpInitSelect2', $index);
        self::assertStringContainsString('$.getJSON(dataUrl', $index);
        self::assertStringContainsString('ผู้รับผิดชอบ', $cards);
        self::assertStringContainsString("hasPermission('crm.team-work.view')", $sidebar);
    }
}
