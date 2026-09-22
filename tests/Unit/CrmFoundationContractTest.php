<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmFoundationContractTest extends TestCase
{
    #[Test]
    public function crm_is_installer_managed_and_branch_scoped(): void
    {
        $providers = file_get_contents(base_path('bootstrap/providers.php'));
        $routes = file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $defaults = file_get_contents(base_path('app/Modules/Installer/Services/SystemDefaultOrchestrator.php'));
        $schema = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));
        $migration = file_get_contents(base_path('database/migrations/2026_09_17_140000_create_crm_foundation.php'));

        self::assertStringContainsString('CrmServiceProvider::class', $providers);
        self::assertStringContainsString("['auth', 'program:crm', 'branch']", $routes);
        self::assertStringContainsString("'core.rbac' => '3.0'", $defaults);
        self::assertStringContainsString("'core.programs' => '1.2'", $defaults);
        self::assertStringContainsString("['code' => 'crm'", $defaults);
        self::assertStringContainsString("'crm_opportunities' =>", $schema);
        self::assertStringContainsString("'crm_activities' =>", $schema);
        self::assertStringContainsString("Schema::dropIfExists('crm_activities')", $migration);
        self::assertStringContainsString("Schema::dropIfExists('crm_opportunities')", $migration);
    }

    #[Test]
    public function crm_reuses_customers_and_links_pos_safely(): void
    {
        $opportunity = file_get_contents(base_path('app/Modules/Crm/Controllers/OpportunityController.php'));
        $salesIntake = file_get_contents(base_path('app/Modules/Pos/Controllers/SalesIntakeController.php'));
        $request = file_get_contents(base_path('app/Modules/Pos/Requests/SaveSalesIntakeRequest.php'));

        self::assertStringContainsString("whereHas('customerRole'", $opportunity);
        self::assertStringContainsString("hasPermission('crm.opportunities.convert-pos')", $opportunity);
        self::assertStringContainsString("lockForUpdate()->where('branch_id'", $salesIntake);
        self::assertStringContainsString("'sales_intake_id' => \$x->id", $salesIntake);
        self::assertStringContainsString("'crm_opportunity_id' => ['nullable', 'integer', 'exists:crm_opportunities,id']", $request);
    }

    #[Test]
    public function responsible_user_fields_use_ajax_select2(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Crm/Controllers/OpportunityController.php'));
        $routes = file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $form = file_get_contents(base_path('app/Modules/Crm/Views/opportunities/form.blade.php'));
        $show = file_get_contents(base_path('app/Modules/Crm/Views/opportunities/show.blade.php'));
        $index = file_get_contents(base_path('app/Modules/Crm/Views/opportunities/index.blade.php'));

        self::assertStringContainsString("Route::get('/opportunities/owner-options'", $routes);
        self::assertStringContainsString('forPage(max(1, $request->integer(\'page\', 1)), 31)', $controller);
        self::assertStringContainsString("window.erpInitSelect2(owner", $form);
        self::assertStringContainsString('js-activity-assignee', $show);
        self::assertStringContainsString("window.erpInitSelect2(owner", $index);
    }

    #[Test]
    public function opportunity_list_loads_mobile_first_cards_through_ajax_pagination(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Crm/Controllers/OpportunityController.php'));
        $index = file_get_contents(base_path('app/Modules/Crm/Views/opportunities/index.blade.php'));
        $cards = file_get_contents(base_path('app/Modules/Crm/Views/opportunities/_cards.blade.php'));
        $form = file_get_contents(base_path('app/Modules/Crm/Views/opportunities/form.blade.php'));
        $appCss = file_get_contents(base_path('public/css/app.css'));
        $checklist = file_get_contents(base_path('CRM_IMPLEMENTATION_CHECKLIST.md'));

        self::assertStringContainsString("paginate(12)->withPath(route('crm.opportunities.index'))->withQueryString()", $controller);
        self::assertStringContainsString("view('Crm::opportunities._cards'", $controller);
        self::assertStringNotContainsString('DataTables::', $controller);
        self::assertStringContainsString("route('crm.opportunities.data')", $index);
        self::assertStringContainsString("$.ajax({url:dataUrl", $index);
        self::assertStringContainsString("searchParams.get('page')||1)));", $index);
        self::assertStringContainsString('crm-opportunity-list', $index);
        self::assertStringContainsString('module-dashboard--crm', $index);
        self::assertStringContainsString('.module-dashboard--crm', $appCss);
        self::assertStringContainsString('crm-probability-progress', $cards);
        self::assertStringContainsString('$showCustomer', $cards);
        self::assertStringNotContainsString('DataTable(', $index);
        self::assertStringContainsString('data-bs-target="#opportunity-filters"', $index);
        self::assertStringContainsString('col-12 col-md-', $form);
        self::assertStringContainsString('crm-form-actions', $form);
        self::assertStringContainsString('## Release Gate', $checklist);
    }
}
