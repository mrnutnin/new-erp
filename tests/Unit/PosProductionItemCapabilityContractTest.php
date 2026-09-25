<?php

namespace Tests\Unit;

use App\Modules\Platform\Services\ModuleCapability;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Requests\SaveItemRequest;
use Illuminate\Container\Container;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Mockery;
use PHPUnit\Framework\TestCase;

class PosProductionItemCapabilityContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        Mockery::close();
        parent::tearDown();
    }

    public function test_capability_is_validated_only_when_production_is_enabled(): void
    {
        foreach ([true, false] as $enabled) {
            $settings = Mockery::mock(GlobalSettings::class);
            $settings->shouldReceive('value')->with('business_profile')->andReturn('MANUFACTURING');
            $settings->shouldReceive('value')->with('production_enabled')->andReturn($enabled);
            $container = new Container;
            Container::setInstance($container);
            $container->instance(ModuleCapability::class, new ModuleCapability($settings));

            $request = SaveItemRequest::create('/', 'POST', ['can_manufacture' => '1', 'can_receive_production_scrap' => '1', 'item_type' => 'SERVICE']);
            $request->setContainer($container);
            (new \ReflectionMethod(SaveItemRequest::class, 'prepareForValidation'))->invoke($request);
            $validator = (new Factory(new Translator(new ArrayLoader, 'th')))->make(
                ['can_manufacture' => $request->input('can_manufacture'), 'can_receive_production_scrap' => $request->input('can_receive_production_scrap')],
                ['can_manufacture' => $request->rules()['can_manufacture'], 'can_receive_production_scrap' => $request->rules()['can_receive_production_scrap']],
            );
            $request->withValidator($validator);

            self::assertSame($enabled, $validator->errors()->has('can_manufacture'));
            self::assertSame($enabled, $validator->errors()->has('can_receive_production_scrap'));
            if (! $enabled) {
                self::assertArrayNotHasKey('can_manufacture', $validator->validated());
            }
        }
    }

    public function test_legacy_cutover_and_product_flag_are_migrated_and_verified(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root.'/database/migrations/2026_09_23_010000_add_pos_production_item_capability.php');
        $scrapMigration = file_get_contents($root.'/database/migrations/2026_09_25_090000_add_production_scrap_item_capability.php');
        $installer = file_get_contents($root.'/app/Modules/Installer/Services/DatabasePreparationService.php');
        $item = file_get_contents($root.'/app/Modules/Wms/Models/Item.php');
        $order = file_get_contents($root.'/app/Modules/Pos/Models/SalesOrder.php');
        $form = file_get_contents($root.'/app/Modules/Wms/Views/items/form.blade.php');

        foreach (['can_manufacture', 'production_legacy_eligible'] as $column) {
            self::assertStringContainsString($column, $migration);
            self::assertStringContainsString($column, $installer);
        }
        self::assertStringContainsString("where('status', 'CONFIRMED')->update(['production_legacy_eligible' => true])", $migration);
        self::assertStringContainsString("'can_manufacture' => 'boolean'", $item);
        self::assertStringContainsString("'production_legacy_eligible' => 'boolean'", $order);
        self::assertStringNotContainsString("'production_legacy_eligible',", explode('protected function casts()', $order)[0]);
        self::assertStringContainsString('data-error-for="can_manufacture"', $form);
        self::assertStringContainsString('@if($productionEnabled)', $form);
        self::assertStringContainsString('can_receive_production_scrap', $scrapMigration);
        self::assertStringContainsString('can_receive_production_scrap', $installer);
        self::assertStringContainsString("'can_receive_production_scrap' => 'boolean'", $item);
        self::assertStringContainsString('can_receive_production_scrap', $form);
    }
}
