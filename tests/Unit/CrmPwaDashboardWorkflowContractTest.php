<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmPwaDashboardWorkflowContractTest extends TestCase
{
    #[Test]
    public function crm_is_installable_without_caching_authenticated_data(): void
    {
        $manifest=json_decode(file_get_contents(public_path('crm-manifest.webmanifest')),true,512,JSON_THROW_ON_ERROR);
        $layout=file_get_contents(base_path('app/Modules/Crm/Views/layout.blade.php'));
        $worker=file_get_contents(public_path('crm-push-sw.js'));
        self::assertSame('/crm',$manifest['start_url']);
        self::assertSame('/crm',$manifest['scope']);
        self::assertSame('standalone',$manifest['display']);
        self::assertCount(2,$manifest['icons']);
        self::assertSame(['/images/mint-icon-192.png', '/images/mint-icon-512.png'], array_column($manifest['icons'], 'src'));
        self::assertFileExists(public_path('images/mint-icon-192.png'));
        self::assertFileExists(public_path('images/mint-icon-512.png'));
        self::assertSame([192,192],array_slice(getimagesize(public_path('images/mint-icon-192.png')),0,2));
        self::assertSame([512,512],array_slice(getimagesize(public_path('images/mint-icon-512.png')),0,2));
        self::assertStringContainsString('images/mint-icon-192.png', $layout);
        self::assertStringContainsString('beforeinstallprompt',$layout);
        self::assertStringContainsString('Add to Home Screen',$layout);
        self::assertStringContainsString("register('/crm-push-sw.js')",$layout);
        self::assertStringContainsString("addEventListener('fetch'",$worker);
        self::assertStringNotContainsString('caches.open',$worker);
    }

    #[Test]
    public function production_install_uses_the_shared_mint_icons(): void
    {
        $manifest = json_decode(file_get_contents(public_path('production-shop-floor.webmanifest')), true, 512, JSON_THROW_ON_ERROR);
        $layout = file_get_contents(base_path('app/Modules/Production/Views/layout.blade.php'));

        self::assertSame(['/images/mint-icon-192.png', '/images/mint-icon-512.png'], array_column($manifest['icons'], 'src'));
        self::assertStringContainsString('images/mint-icon-192.png', $layout);
        self::assertSame('any maskable', $manifest['icons'][0]['purpose']);
    }

    #[Test]
    public function dashboard_prioritizes_status_next_action_and_mobile_install(): void
    {
        $controller=file_get_contents(base_path('app/Modules/Crm/Controllers/DashboardController.php'));
        $view=file_get_contents(base_path('app/Modules/Crm/Views/dashboard.blade.php'));
        self::assertStringContainsString("'todayCount'",$controller);
        self::assertStringContainsString("'wonMonthCount'",$controller);
        self::assertStringContainsString("'noNextActionCount'",$controller);
        self::assertStringContainsString('กิจกรรมเกินกำหนด',$view);
        self::assertStringContainsString('งานถัดไป',$view);
        self::assertStringContainsString('data-crm-install',$view);
        self::assertStringContainsString('คู่มือการทำงาน',$view);
    }

    #[Test]
    public function sidebar_keeps_daily_work_visible_and_groups_occasional_tools(): void
    {
        $sidebar=file_get_contents(base_path('app/Modules/Crm/Views/partials/sidebar.blade.php'));
        self::assertStringContainsString('งานประจำวัน',$sidebar);
        foreach(['งานของฉัน','ปฏิทินงาน','ลูกค้า 360°','โอกาสการขาย','Kanban Pipeline','การแจ้งเตือน'] as $daily) self::assertStringContainsString($daily,$sidebar);
        self::assertStringNotContainsString('data-bs-target="#crm-customer-menu"',$sidebar);
        self::assertStringNotContainsString('data-bs-target="#crm-pipeline-menu"',$sidebar);
        self::assertStringContainsString('data-bs-target="#crm-analytics-menu"',$sidebar);
        self::assertStringContainsString('data-bs-target="#crm-team-menu"',$sidebar);
        self::assertStringContainsString("hasPermission('crm.forecast.view')",$sidebar);
        self::assertStringContainsString("hasPermission('crm.teams.manage')",$sidebar);
        self::assertStringContainsString("hasPermission('crm.customers.transfer-owner')",$sidebar);
        self::assertStringContainsString("request()->routeIs('crm.ownership-transfers.*')",$sidebar);
    }

    #[Test]
    public function crm_workflow_guide_reuses_the_shared_workflow_center(): void
    {
        $routes=file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $catalog=file_get_contents(base_path('app/Modules/Platform/Services/WorkflowCatalog.php'));
        $view=file_get_contents(base_path('app/Modules/Crm/Views/workflow/index.blade.php'));
        $sidebar=file_get_contents(base_path('app/Modules/Crm/Views/partials/sidebar.blade.php'));
        self::assertStringContainsString("[WorkflowController::class, 'index']",$routes);
        self::assertStringContainsString("if (\$program === 'crm')",$catalog);
        self::assertStringContainsString("Platform::workflow._workflow-card",$view);
        self::assertStringContainsString("Platform::workflow._process-diagram",$view);
        self::assertStringContainsString("route('crm.workflow.index')",$sidebar);
        self::assertStringContainsString('คู่มือการทำงาน',$sidebar);
    }
}
