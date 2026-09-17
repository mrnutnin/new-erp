<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmActivityPaginationContractTest extends TestCase
{
    #[Test]
    public function opportunity_detail_loads_activities_through_bounded_ajax_pagination(): void
    {
        $activityController = file_get_contents(base_path('app/Modules/Crm/Controllers/ActivityController.php'));
        $opportunityController = file_get_contents(base_path('app/Modules/Crm/Controllers/OpportunityController.php'));
        $routes = file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $show = file_get_contents(base_path('app/Modules/Crm/Views/opportunities/show.blade.php'));

        self::assertStringContainsString("Route::get('/opportunities/{opportunity}/activities'", $routes);
        self::assertStringContainsString('paginate(8)', $activityController);
        self::assertStringContainsString("where('opportunity_id', \$opportunity->id)", $activityController);
        self::assertStringContainsString("\$activityCount = \$opportunity->activities()->count()", $opportunityController);
        self::assertStringNotContainsString('$opportunity->activities as', $show);
        self::assertStringContainsString('loadActivities(1)', $show);
        self::assertStringContainsString('activity-filter-form', $show);
    }

    #[Test]
    public function activity_cards_keep_completion_and_audit_context(): void
    {
        $cards = file_get_contents(base_path('app/Modules/Crm/Views/opportunities/_activity-cards.blade.php'));

        self::assertStringContainsString('js-complete-activity', $cards);
        self::assertStringContainsString('บันทึกโดย', $cards);
        self::assertStringContainsString('app-status-{{ $semantic }}', $cards);
        self::assertStringContainsString('crm-activity-action-{{ $semantic }}', $cards);
    }
}
