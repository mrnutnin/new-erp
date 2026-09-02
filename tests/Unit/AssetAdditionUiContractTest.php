<?php

namespace Tests\Unit;

use App\Modules\Asset\Controllers\AssetCapitalizationController;
use PHPUnit\Framework\TestCase;

final class AssetAdditionUiContractTest extends TestCase
{
    public function test_addition_has_separate_routes_menu_and_sequence_but_reuses_the_capitalization_permissions(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Asset/Routes/web.php');
        $sidebar = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Asset/Views/partials/sidebar.blade.php');
        $controller = file_get_contents((new \ReflectionClass(AssetCapitalizationController::class))->getFileName());
        $seeder = file_get_contents(dirname(__DIR__, 2).'/database/seeders/DatabaseSeeder.php');

        self::assertStringContainsString("Route::get('/additions'", $routes);
        self::assertStringContainsString("name('additions.index')", $routes);
        self::assertStringContainsString("name('additions.post')", $routes);
        self::assertStringContainsString("route('asset.additions.index')", $sidebar);
        self::assertStringContainsString("'ASSET_ADDITION'", $controller);
        self::assertStringContainsString("'asset.addition'", file_get_contents(dirname(__DIR__, 2).'/app/Modules/Asset/Services/AssetCapitalizationService.php'));
        self::assertStringContainsString("['ASSET_ADDITION', 'ใบเพิ่มมูลค่าสินทรัพย์', 'AA']", $seeder);
    }

    public function test_capitalization_and_addition_lists_are_scoped_to_their_document_type(): void
    {
        $controller = file_get_contents((new \ReflectionClass(AssetCapitalizationController::class))->getFileName());

        self::assertStringContainsString('->where(\'transaction_type\', $this->transactionType($request))', $controller);
        self::assertStringContainsString('$this->isAddition($request) ? \'ACTIVE\' : \'DRAFT\'', $controller);
        self::assertStringContainsString('\'routePrefix\' => $isAddition ? \'asset.additions\' : \'asset.capitalizations\'', $controller);
    }
}
