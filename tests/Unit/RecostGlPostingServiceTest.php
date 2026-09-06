<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\RecostGlPostingService;
use Tests\TestCase;

final class RecostGlPostingServiceTest extends TestCase
{
    public function test_service_is_not_exposed_by_a_route_until_release_gate_is_open(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Wms/Routes/web.php'));
        $this->assertStringNotContainsString('RecostGlPostingService', $routes);
        $this->assertStringContainsString("'inventory.recost' =>", file_get_contents(base_path('app/Modules/Wms/Services/InventoryCostPostingService.php')));
        $this->assertTrue(class_exists(RecostGlPostingService::class));
    }
}
