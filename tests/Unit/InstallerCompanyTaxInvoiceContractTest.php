<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InstallerCompanyTaxInvoiceContractTest extends TestCase
{
    public function test_installer_seeds_the_head_office_tax_issuer_profile_on_the_default_branch(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Modules/Installer/Services/CustomerSetupService.php');

        self::assertStringContainsString("'tax_branch_code' => \$branch->tax_branch_code ?: '00000'", $service);
        self::assertStringContainsString("'tax_address' => \$branch->tax_address ?: \$company?->company_address", $service);
    }

    public function test_installer_company_form_collects_and_persists_required_global_settings(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Installer/Controllers/SetupController.php');
        $service = file_get_contents($root.'/app/Modules/Installer/Services/CustomerSetupService.php');
        $view = file_get_contents($root.'/app/Modules/Installer/Views/setup/index.blade.php');

        foreach (['posting_sla_minutes', 'recost_sla_minutes', 'audit_retention_days', 'file_retention_days'] as $key) {
            self::assertStringContainsString("'{$key}' => ['required', 'integer'", $controller);
            self::assertStringContainsString("name=\"{$key}\"", $view);
            self::assertStringContainsString("'{$key}' => \$values['{$key}']", $service);
        }

        self::assertStringContainsString('app(GlobalSettings::class)->forget($previousVersion)', $service);
        self::assertStringContainsString("'settings_version' => max(1, \$previousVersion + 1)", $service);
    }

    public function test_installer_can_opt_in_to_the_optional_production_capability(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Installer/Controllers/SetupController.php');
        $service = file_get_contents($root.'/app/Modules/Installer/Services/CustomerSetupService.php');
        $view = file_get_contents($root.'/app/Modules/Installer/Views/setup/index.blade.php');

        self::assertStringContainsString("'production_enabled' => ['required', 'boolean']", $controller);
        self::assertStringContainsString('name="production_enabled"', $view);
        self::assertStringContainsString('เปิดใช้ Production (Manufacturing)', $view);
        self::assertStringContainsString("'business_profile' => (\$values['production_enabled'] ?? false) ? 'MANUFACTURING' : 'TRADING'", $service);
        self::assertStringContainsString("'production_enabled' => (bool) (\$values['production_enabled'] ?? false)", $service);
    }
}
