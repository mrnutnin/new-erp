<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmSalesForecastContractTest extends TestCase
{
    #[Test]
    public function forecast_is_permissioned_branch_team_scoped_and_server_paginated():void
    {
        $routes=file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $service=file_get_contents(base_path('app/Modules/Crm/Services/SalesForecastService.php'));
        $view=file_get_contents(base_path('app/Modules/Crm/Views/forecast/index.blade.php'));
        self::assertStringContainsString('permission:crm.forecast.view',$routes);
        self::assertStringContainsString("[ForecastController::class, 'data']",$routes);
        self::assertStringContainsString("where('branch_id',\$branchId)",$service);
        self::assertStringContainsString("crm.team-work.view-all",$service);
        self::assertStringContainsString('new LengthAwarePaginator',$service);
        self::assertStringContainsString('history.pushState',$view);
        self::assertStringContainsString('js-retry',$view);
    }

    #[Test]
    public function forecast_reuses_pos_targets_posted_sales_and_weighted_pipeline():void
    {
        $service=file_get_contents(base_path('app/Modules/Crm/Services/SalesForecastService.php'));
        self::assertStringContainsString('SUM(expected_value * probability / 100) forecast',$service);
        self::assertStringContainsString("DB::table('pos_employee_sales_targets')",$service);
        self::assertStringContainsString("DB::table('pos_branch_sales_targets')",$service);
        self::assertStringContainsString("where('sales.status','POSTED')",$service);
        self::assertStringContainsString('pos_sales_return_lines',$service);
        self::assertStringContainsString("whereIn('stage',self::OPEN_STAGES)",$service);
        self::assertStringContainsString("'coverage'",file_get_contents(base_path('app/Modules/Crm/Views/forecast/index.blade.php')));
    }

    #[Test]
    public function forecast_includes_conversion_win_rate_and_stage_duration():void
    {
        $service=file_get_contents(base_path('app/Modules/Crm/Services/SalesForecastService.php'));
        $view=file_get_contents(base_path('app/Modules/Crm/Views/forecast/index.blade.php'));
        self::assertStringContainsString('TIMESTAMPDIFF(HOUR,created_at,won_at)',$service);
        self::assertStringContainsString('crm.opportunity.won-from-pos',$service);
        self::assertStringContainsString("MIN(event_b.event_at) next_at",$service);
        self::assertStringContainsString("'win_rate'",$service);
        self::assertStringContainsString('Conversion และระยะเวลาขาย',$view);
        self::assertStringContainsString('Sales cycle เฉลี่ย',$view);
    }

    #[Test]
    public function forecast_analyzes_sources_products_and_lost_reasons_with_bounded_results():void
    {
        $service=file_get_contents(base_path('app/Modules/Crm/Services/SalesForecastService.php'));
        $view=file_get_contents(base_path('app/Modules/Crm/Views/forecast/index.blade.php'));
        self::assertStringContainsString("opportunity.source",$service);
        self::assertStringContainsString("crm_product_interests as interest",$service);
        self::assertStringContainsString("opportunity.lost_reason",$service);
        self::assertGreaterThanOrEqual(3,substr_count($service,'limit(8)'));
        self::assertStringContainsString('Sales Insights',$view);
        self::assertStringContainsString("\$('<strong class=\"d-block text-break\"></strong>').text(row.label)",$view);
    }

    #[Test]
    public function risky_opportunities_are_bounded_visible_and_scheduled_for_idempotent_notification():void
    {
        $risk=file_get_contents(base_path('app/Modules/Crm/Services/OpportunityRiskService.php'));
        $forecast=file_get_contents(base_path('app/Modules/Crm/Services/SalesForecastService.php'));
        $notifications=file_get_contents(base_path('app/Modules/Crm/Services/CrmNotificationService.php'));
        $console=file_get_contents(base_path('routes/console.php'));
        self::assertStringContainsString("whereNull('crm_opportunities.next_action_at')",$risk);
        self::assertStringContainsString("orWhereDate('crm_opportunities.expected_close_date','<',today())",$risk);
        self::assertStringContainsString('stale_opportunity_days',$risk);
        self::assertStringContainsString('limit(12)',$forecast);
        self::assertStringContainsString('sendRiskAlerts',$notifications);
        self::assertStringContainsString("'crm:risk:'.today()->toDateString()",$notifications);
        self::assertStringContainsString("crm:send-risk-alerts",$console);
        self::assertStringContainsString('dailyAt',$console);
    }

    #[Test]
    public function checklist_covers_mobile_calendar_recurring_work_and_reminders():void
    {
        $checklist=file_get_contents(base_path('CRM_SALES_FEATURE_CHECKLIST.md'));
        self::assertStringContainsString('### Calendar & Scheduling',$checklist);
        self::assertStringContainsString('Calendar รายวัน/สัปดาห์/เดือนแบบ mobile-first',$checklist);
        self::assertStringContainsString('Recurring activity',$checklist);
        self::assertStringContainsString('In-app/Web Push',$checklist);
    }
}
