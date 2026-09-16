<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AssetUiConsistencyContractTest extends TestCase
{
    public function test_asset_views_use_shared_button_classes(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Asset/Views';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $view = file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/btn-(?:dark|primary|secondary|outline-(?:dark|primary|secondary|danger|success|warning)|success|danger)(?:\\s|")/', $view, $file->getFilename());
        }
    }

    public function test_high_risk_document_details_have_consistent_navigation_and_cancellation(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Asset/Views/';

        foreach (['capitalizations/show.blade.php', 'depreciations/show.blade.php', 'depreciation-policies/show.blade.php', 'disposals/show.blade.php', 'impairments/show.blade.php', 'counts/show.blade.php', 'transfers/show.blade.php', 'maintenance/show.blade.php'] as $path) {
            $view = file_get_contents($root.$path);

            $this->assertStringContainsString('กลับหน้ารายการ', $view, $path);
            $this->assertStringContainsString('ยกเลิกเอกสาร', $view, $path);
        }
    }

    public function test_capitalization_keeps_destructive_actions_last_with_standard_labels(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Asset/Views/capitalizations/show.blade.php');

        $this->assertStringContainsString('>ลบร่าง</button>', $view);
        $this->assertStringContainsString('>ยกเลิกเอกสาร</button>', $view);
        $this->assertLessThan(strpos($view, '>ยกเลิกเอกสาร</button>'), strpos($view, 'กลับหน้ารายการ'));
    }

    public function test_asset_list_actions_are_icon_buttons_with_accessible_labels(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Asset/Views/';

        foreach (['assets/index.blade.php', 'categories/index.blade.php', 'locations/index.blade.php', 'counts/index.blade.php', 'capitalizations/index.blade.php', 'depreciations/index.blade.php', 'disposals/index.blade.php', 'impairments/index.blade.php', 'maintenance/index.blade.php', 'transfers/index.blade.php', 'imports/index.blade.php', 'maintenance/schedules/index.blade.php'] as $path) {
            $view = file_get_contents($root.$path);

            $this->assertStringContainsString('aria-label=', $view, $path);
            $this->assertStringContainsString('bx-', $view, $path);
        }
    }

    public function test_asset_master_filter_resets_are_in_the_filter_card_header(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Asset/Views/';

        foreach (['categories/index.blade.php' => 'category-filter-reset', 'locations/index.blade.php' => 'location-filter-reset'] as $path => $resetId) {
            $view = file_get_contents($root.$path);

            $this->assertStringContainsString('justify-content-between align-items-center', $view, $path);
            $this->assertStringContainsString('id="'.$resetId.'"', $view, $path);
            $this->assertStringContainsString('bx bx-reset', $view, $path);
        }
    }

    public function test_asset_reports_keep_filters_in_markup_and_use_the_shared_gl_action(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Asset/Views/reports/';
        $maintenance = file_get_contents($root.'maintenance.blade.php');
        $reconciliation = file_get_contents($root.'reconciliation.blade.php');

        $this->assertStringContainsString('id="maintenance-report-filter-reset"', $maintenance);
        $this->assertStringNotContainsString("$('<div", $maintenance);
        $this->assertStringContainsString('aria-label="ดู GL"', $reconciliation);
        $this->assertStringContainsString('bx-book-open', $reconciliation);
    }
}
