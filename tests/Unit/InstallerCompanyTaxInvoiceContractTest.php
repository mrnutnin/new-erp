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
}
