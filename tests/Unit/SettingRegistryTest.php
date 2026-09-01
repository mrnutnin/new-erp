<?php

namespace Tests\Unit;

use App\Modules\Settings\Support\SettingRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SettingRegistryTest extends TestCase
{
    public function test_every_required_setting_has_complete_metadata(): void
    {
        $registry = new SettingRegistry;

        foreach (SettingRegistry::REQUIRED as $keys) {
            foreach ($keys as $key) {
                $definition = $registry->definition($key);

                $this->assertNotSame('', $definition['name']);
                $this->assertNotSame('', $definition['description']);
                $this->assertArrayHasKey('type', $definition);
                $this->assertArrayHasKey('allowed', $definition);
                $this->assertArrayHasKey('default', $definition);
                $this->assertArrayHasKey('owner', $definition);
                $this->assertArrayHasKey('retroactive', $definition);
            }
        }
    }

    public function test_negative_cost_method_is_required_only_when_negative_stock_is_allowed(): void
    {
        $registry = new SettingRegistry;
        $values = [
            'inventory_costing_method' => 'AVG',
            'allow_negative_stock' => false,
            'recost_sla_minutes' => 60,
        ];

        $this->assertSame([], $registry->missing('inventory', $values));

        $values['allow_negative_stock'] = true;
        $this->assertSame(['negative_stock_cost_method'], $registry->missing('inventory', $values));

        $values['negative_stock_cost_method'] = 'LAST_KNOWN';
        $this->assertSame([], $registry->missing('inventory', $values));
    }

    public function test_unknown_setting_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SettingRegistry)->definition('unknown');
    }

    public function test_asset_depreciation_defaults_to_daily_proration(): void
    {
        self::assertSame('DAILY', (new SettingRegistry)->definition('asset_depreciation_proration')['default']);
    }
}
