<?php

namespace Tests\Unit;

use Database\Seeders\SystemTaxCodeSeeder;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderVatInstallerContractTest extends TestCase
{
    public function test_installer_provisions_purchase_order_vat_schema_and_tax_code(): void
    {
        $root = dirname(__DIR__, 2);
        $database = file_get_contents($root.'/app/Modules/Installer/Services/DatabasePreparationService.php');
        $defaults = file_get_contents($root.'/app/Modules/Installer/Services/SystemDefaultOrchestrator.php');
        $validation = file_get_contents($root.'/app/Modules/Installer/Services/InstallationValidationService.php');

        self::assertStringContainsString("'purchase_orders' => ['tax_treatment', 'prices_include_vat', 'tax_decimal_places', 'tax_amount', 'deleted_at']", $database);
        self::assertStringContainsString("'purchase_order_lines' => ['tax_code_id', 'tax_rate', 'tax_base', 'tax_amount', 'gross_amount']", $database);
        self::assertStringContainsString("'accounting.tax_codes' => '1.0'", $defaults);
        self::assertStringContainsString('SystemTaxCodeSeeder::class', $defaults);
        self::assertStringContainsString("where('code', 'VAT7-IN')", $validation);
        self::assertSame('VAT_IN', SystemTaxCodeSeeder::definitions()['VAT7-IN']['kind']);
        self::assertSame(7, SystemTaxCodeSeeder::definitions()['VAT7-IN']['rate']);
    }
}
