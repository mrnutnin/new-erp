<?php

namespace Tests\Unit;

use App\Modules\Crm\Controllers\CalendarController;
use App\Modules\Crm\Models\Activity;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmCalendarContractTest extends TestCase
{
    #[Test]
    public function calendar_is_permissioned_branch_team_scoped_and_bounded():void
    {
        $routes=file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $controller=file_get_contents(base_path('app/Modules/Crm/Controllers/CalendarController.php'));
        self::assertStringContainsString('permission:crm.calendar.view',$routes);
        self::assertStringContainsString("[CalendarController::class, 'data']",$routes);
        self::assertStringContainsString("['DAY','WEEK','MONTH']",$controller);
        self::assertStringContainsString("where('branch_id',\$branchId)",$controller);
        self::assertStringContainsString('crm.team-work.view-all',$controller);
        self::assertGreaterThanOrEqual(2,substr_count($controller,'limit(501)'));
        self::assertStringContainsString("'truncated'=>\$truncated",$controller);
    }

    #[Test]
    public function calendar_is_mobile_first_ajax_and_supports_completion():void
    {
        $view=file_get_contents(base_path('app/Modules/Crm/Views/calendar/index.blade.php'));
        $css=file_get_contents(base_path('public/css/app.css'));
        self::assertStringContainsString('รายวัน',$view);
        self::assertStringContainsString('รายสัปดาห์',$view);
        self::assertStringContainsString('รายเดือน',$view);
        self::assertStringContainsString('history.pushState',$view);
        self::assertStringContainsString('js-calendar-retry',$view);
        self::assertStringContainsString('js-calendar-complete',$view);
        self::assertStringContainsString("route('crm.calendar.owner-options')",$view);
        self::assertStringContainsString('.crm-calendar.is-month',$css);
        self::assertStringContainsString('min-height:44px',$css);
        self::assertStringContainsString('crm-calendar-weekdays',$view);
        self::assertStringContainsString('crm-calendar-event-compact',$view);
        self::assertStringContainsString('ดูอีก ',$view);
        self::assertStringContainsString('.crm-calendar-more',$css);
    }

    #[Test]
    public function activities_can_be_created_edited_and_rescheduled_safely():void
    {
        $routes=file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $activity=file_get_contents(base_path('app/Modules/Crm/Controllers/ActivityController.php'));
        $view=file_get_contents(base_path('app/Modules/Crm/Views/calendar/index.blade.php'));
        self::assertStringContainsString("activities/{activity}/schedule",$routes);
        self::assertStringContainsString('permission:crm.activities.update',$routes);
        self::assertGreaterThanOrEqual(4,substr_count($activity,'lockForUpdate()'));
        self::assertStringContainsString('crm.activity.updated',$activity);
        self::assertStringContainsString('crm.activity.rescheduled',$activity);
        self::assertStringContainsString('validateAssignee',$activity);
        self::assertStringContainsString('datetime-local',$view);
        self::assertStringContainsString('js-calendar-day-add',$view);
        self::assertStringContainsString(".on('drop'",$view);
        self::assertStringContainsString('history.pushState',$view);
    }

    #[Test]
    public function filtered_activities_export_as_bounded_valid_ics():void
    {
        $routes=file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $controller=file_get_contents(base_path('app/Modules/Crm/Controllers/CalendarController.php'));
        $view=file_get_contents(base_path('app/Modules/Crm/Views/calendar/index.blade.php'));
        self::assertStringContainsString("[CalendarController::class, 'export']",$routes);
        self::assertStringContainsString('text/calendar; charset=UTF-8',$controller);
        self::assertStringContainsString("'BEGIN:VCALENDAR'",$controller);
        self::assertStringContainsString("'BEGIN:VEVENT'",$controller);
        self::assertStringContainsString('limit(1001)',$controller);
        self::assertStringContainsString('diffInDays($to)>90',$controller);
        self::assertStringContainsString('calendar-export',$view);

        $calendar=new CalendarController();
        $escape=new \ReflectionMethod($calendar,'icsEscape');
        self::assertSame('นัดหมาย\\, ทดสอบ\\;\\nครั้งถัดไป',$escape->invoke($calendar,"นัดหมาย, ทดสอบ;\nครั้งถัดไป"));
        $fold=new \ReflectionMethod($calendar,'icsLine');
        $lines=explode("\r\n",$fold->invoke($calendar,'SUMMARY:'.str_repeat('กิจกรรม',20)));
        self::assertGreaterThan(1,count($lines));
        self::assertTrue(collect($lines)->every(fn(string $line)=>strlen($line)<=75));
        self::assertStringStartsWith(' ',$lines[1]);
    }

    #[Test]
    public function activity_can_open_google_calendar_without_oauth():void
    {
        $controller=new CalendarController();
        $method=new \ReflectionMethod($controller,'googleCalendarUrl');
        $activity=new Activity(['subject'=>'ประชุม ลูกค้า']);
        $activity->id=10;$activity->opportunity_id=20;$activity->due_at='2026-09-20 09:00:00';
        $url=$method->invoke($controller,$activity);
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        self::assertStringStartsWith('https://calendar.google.com/calendar/render?',$url);
        self::assertSame('TEMPLATE',$query['action']);
        self::assertSame('ประชุม ลูกค้า',$query['text']);
        self::assertMatchesRegularExpression('/^\\d{8}T\\d{6}Z\\/\\d{8}T\\d{6}Z$/',$query['dates']);
        self::assertStringContainsString('/crm/opportunities/20',$query['details']);
        self::assertSame(config('app.timezone'),$query['ctz']);
        self::assertStringNotContainsString('oauth',$url);
        $view=file_get_contents(base_path('app/Modules/Crm/Views/calendar/index.blade.php'));
        self::assertStringContainsString('เพิ่มใน Google Calendar',$view);
        self::assertStringContainsString('rel="noopener"',$view);
    }

    #[Test]
    public function calendar_avoids_duplicate_next_actions_and_keeps_native_date_fallback():void
    {
        $controller=file_get_contents(base_path('app/Modules/Crm/Controllers/CalendarController.php'));
        $view=file_get_contents(base_path('app/Modules/Crm/Views/calendar/index.blade.php'));
        self::assertStringContainsString('$activityKeys',$controller);
        self::assertStringContainsString("'kind'=>'NEXT_ACTION'",$controller);
        self::assertStringContainsString('type="date"',$view);
        self::assertStringNotContainsString('FullCalendar',$view);
    }
}
