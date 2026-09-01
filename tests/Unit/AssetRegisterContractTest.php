<?php

namespace Tests\Unit;

use App\Modules\Asset\Controllers\AssetController;
use App\Modules\Asset\Requests\SaveAssetRequest;
use PHPUnit\Framework\TestCase;

final class AssetRegisterContractTest extends TestCase
{
    public function test_register_requires_business_identity_and_value_fields(): void
    {
        $rules = (new SaveAssetRequest)->rules();

        foreach (['registration_date', 'asset_category_id', 'name', 'acquisition_date', 'original_cost', 'currency_code', 'exchange_rate'] as $field) {
            self::assertContains('required', $rules[$field]);
        }
    }

    public function test_register_routes_and_history_are_branch_scoped(): void
    {
        $controller = file_get_contents((new \ReflectionClass(AssetController::class))->getFileName());
        $routes = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Asset/Routes/web.php');

        self::assertStringContainsString("where('branch_id', \$request->attributes->get('selectedBranch')->id)", $controller);
        self::assertStringContainsString('AssetHistory::query()->create', $controller);
        self::assertStringContainsString("'document_type', 'ASSET_REGISTER'", $controller);
        self::assertStringContainsString("Route::get('/assets/{asset}'", $routes);
        self::assertStringContainsString("Route::post('/assets/{asset}/attachments'", $routes);
        self::assertStringContainsString('permission:asset.attachments.manage', $routes);
    }
}
