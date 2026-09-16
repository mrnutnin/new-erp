<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FilterResetUiContractTest extends TestCase
{
    public function test_asset_and_pos_filter_resets_share_the_same_header_pattern(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/';
        $paths = [
            'Asset/Views/assets/index.blade.php',
            'Asset/Views/capitalizations/index.blade.php',
            'Asset/Views/counts/index.blade.php',
            'Asset/Views/depreciation-policies/index.blade.php',
            'Asset/Views/depreciations/index.blade.php',
            'Asset/Views/disposals/index.blade.php',
            'Asset/Views/impairments/index.blade.php',
            'Asset/Views/maintenance/index.blade.php',
            'Asset/Views/reports/depreciation.blade.php',
            'Asset/Views/reports/reconciliation.blade.php',
            'Asset/Views/transfers/index.blade.php',
            'Pos/Views/price-lists/index.blade.php',
            'Pos/Views/promotions/index.blade.php',
            'Pos/Views/sales-orders/index.blade.php',
            'Pos/Views/sales-returns/index.blade.php',
        ];

        foreach ($paths as $path) {
            $view = file_get_contents($root.$path);

            self::assertStringContainsString('justify-content-between align-items-center', $view, $path);
            self::assertStringContainsString('btn btn-sm btn-app-soft', $view, $path);
            self::assertStringContainsString('bx bx-reset', $view, $path);
        }

        $posLayout = file_get_contents($root.'Pos/Views/layout.blade.php');
        self::assertStringContainsString("cardBody.insertBefore(header, filterArea)", $posLayout);
        self::assertStringContainsString("header.className = 'd-flex flex-wrap justify-content-between align-items-center gap-2 mb-3'", $posLayout);

        $sharedScript = file_get_contents(dirname(__DIR__, 2).'/public/js/app.js');
        self::assertStringContainsString('window.erpStandardizeFilterResets', $sharedScript);
        self::assertStringContainsString("button.className = 'btn btn-sm btn-app-soft'", $sharedScript);
        self::assertStringContainsString("button.insertAdjacentHTML('afterbegin', '<i class=\"bx bx-reset me-1\"", $sharedScript);
    }
}
