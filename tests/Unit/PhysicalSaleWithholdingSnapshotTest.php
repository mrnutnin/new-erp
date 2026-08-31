<?php

namespace Tests\Unit;

use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Pos\Support\PhysicalSaleWithholdingSnapshot;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PhysicalSaleWithholdingSnapshotTest extends TestCase
{
    public function test_builds_a_frozen_wht_snapshot_from_an_active_wht_code(): void
    {
        $tax = new TaxCode(['kind' => 'WHT', 'rate' => '3.0000', 'is_active' => true]);
        $tax->id = 7;

        $snapshot = PhysicalSaleWithholdingSnapshot::build($tax, '100.00', '250.00');

        $this->assertSame(['withholding_tax_code_id' => 7, 'withholding_rate' => '3.0000', 'withholding_base' => '100.00', 'withholding_amount' => '3.00'], $snapshot);
    }

    public function test_rejects_a_base_above_the_document_base(): void
    {
        $tax = new TaxCode(['kind' => 'WHT', 'rate' => '3.0000', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        PhysicalSaleWithholdingSnapshot::build($tax, '250.01', '250.00');
    }

    public function test_post_uses_the_stored_snapshot_without_reloading_the_tax_code(): void
    {
        $snapshot = PhysicalSaleWithholdingSnapshot::assertStored(7, '3.0000', '100.00', '3.00', '250.00');

        $this->assertSame(['withholding_tax_code_id' => 7, 'withholding_rate' => '3.0000', 'withholding_base' => '100.00', 'withholding_amount' => '3.00'], $snapshot);
    }

    public function test_post_rejects_a_tampered_snapshot(): void
    {
        $this->expectException(ValidationException::class);
        PhysicalSaleWithholdingSnapshot::assertStored(7, '3.0000', '100.00', '2.99', '250.00');
    }

    public function test_post_rejects_any_wht_value_without_a_snapshot_tax_code(): void
    {
        $this->expectException(ValidationException::class);
        PhysicalSaleWithholdingSnapshot::assertStored(null, '-3.0000', '0.00', '0.00', '250.00');
    }
}
