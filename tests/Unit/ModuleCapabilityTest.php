<?php

namespace Tests\Unit;

use App\Modules\Platform\Middleware\EnsureModuleCapability;
use App\Modules\Platform\Services\ModuleCapability;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class ModuleCapabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_missing_profile_and_capability_values_default_to_trading_without_production(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('business_profile')->andReturn(null);
        $settings->shouldReceive('value')->with('production_enabled')->andReturn(null);

        $capability = new ModuleCapability($settings);

        $this->assertSame('TRADING', $capability->businessProfile());
        $this->assertFalse($capability->isEnabled(ModuleCapability::PRODUCTION));
    }

    public function test_production_requires_manufacturing_profile_and_explicit_enablement(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('business_profile')->andReturn('MANUFACTURING');
        $settings->shouldReceive('value')->with('production_enabled')->andReturn(true);

        $this->assertTrue((new ModuleCapability($settings))->isEnabled(ModuleCapability::PRODUCTION));
    }

    public function test_production_is_disabled_for_trading_even_when_flag_is_true(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('business_profile')->andReturn('TRADING');

        $this->assertFalse((new ModuleCapability($settings))->isEnabled(ModuleCapability::PRODUCTION));
    }

    public function test_only_production_program_is_filtered_by_capability(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('business_profile')->andReturn('TRADING');

        $capability = new ModuleCapability($settings);

        $this->assertTrue($capability->isProgramAvailable('wms'));
        $this->assertFalse($capability->isProgramAvailable('production'));
    }

    public function test_unknown_capability_is_rejected(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $capability = new ModuleCapability($settings);

        $this->expectException(InvalidArgumentException::class);
        $capability->isEnabled('unknown');
    }

    public function test_capability_middleware_returns_actionable_json_error_when_disabled(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('business_profile')->andReturn('TRADING');
        $capability = new ModuleCapability($settings);
        $request = Request::create('/production', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = (new EnsureModuleCapability($capability))->handle($request, fn () => new Response('ok'), ModuleCapability::PRODUCTION);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['status']);
        $this->assertStringContainsString('Production', $response->getData(true)['msg']);
    }

    public function test_capability_middleware_passes_enabled_request(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('business_profile')->andReturn('MANUFACTURING');
        $settings->shouldReceive('value')->with('production_enabled')->andReturn(true);
        $capability = new ModuleCapability($settings);
        $request = Request::create('/production', 'GET');

        $response = (new EnsureModuleCapability($capability))->handle($request, fn () => new Response('ok'), ModuleCapability::PRODUCTION);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }
}
