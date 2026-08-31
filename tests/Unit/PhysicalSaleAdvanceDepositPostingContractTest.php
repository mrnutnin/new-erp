<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleAdvanceDepositPostingContractTest extends TestCase
{
    public function test_direct_hs_ai_path_is_locked_idempotent_and_has_no_standalone_journal(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Modules/Finance/Services/AdvanceDepositApplicationService.php');
        $plan = file_get_contents($root.'/app/Modules/Pos/Support/PhysicalSaleRevenuePostingPlan.php');

        self::assertStringContainsString('applyToPhysicalSale', $service);
        self::assertStringContainsString('orderBy(\'id\')->lockForUpdate()', $service);
        self::assertStringContainsString('tax_treatment !== $sale->tax_treatment', $service);
        self::assertStringContainsString('prices_include_vat !== (bool) $sale->prices_include_vat', $service);
        self::assertStringContainsString('AdvanceDepositContract::assertApplicationAmount', $service);
        self::assertStringContainsString("'physical_sale_id' => \$sale->id", $service);
        self::assertStringContainsString('advanceApplications', $plan);
        self::assertStringContainsString('ตัดเงินล่วงหน้า', $plan);
        self::assertStringContainsString("->where('physical_sale_id', \$sale->id)", file_get_contents($root.'/app/Modules/Pos/Services/PhysicalSalePostingService.php'));
    }
}
