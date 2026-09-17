<?php

namespace Tests\Unit;

use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Support\WmsDecimal;
use Mockery;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class WmsDecimalInputStepContractTest extends TestCase
{
    public function test_wms_numeric_inputs_use_the_global_decimal_step(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('tax_decimal_places')->andReturn(3);
        $this->app->instance(GlobalSettings::class, $settings);
        self::assertSame('0.001', WmsDecimal::step());

        $root = dirname(__DIR__, 2);
        $violations = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Modules/Wms/Views')) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if (str_contains($contents, 'step="any"') || str_contains($contents, 'step="0.00000001"')) {
                $violations[] = $file->getPathname();
            }
        }

        self::assertSame([], $violations, 'พบ input step ที่ไม่อ้างอิง Global Setting: '.implode(', ', $violations));
        $transferForm = file_get_contents($root.'/app/Modules/Wms/Views/transfers/form.blade.php');
        $issueForm = file_get_contents($root.'/app/Modules/Wms/Views/issues/create.blade.php');
        self::assertStringContainsString('WmsDecimal::step()', $transferForm);
        self::assertStringContainsString('WmsDecimal::input($line?->planned_quantity', $transferForm);
        self::assertStringContainsString('WmsDecimal::step()', $issueForm);
        self::assertStringContainsString('WmsDecimal::input($line?->quantity', $issueForm);
    }
}
