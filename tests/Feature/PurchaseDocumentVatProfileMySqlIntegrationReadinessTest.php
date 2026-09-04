<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Purchasing\Support\PurchaseDocumentCalculator;
use Tests\TestCase;

final class PurchaseDocumentVatProfileMySqlIntegrationReadinessTest extends TestCase
{
    public function test_real_supplier_profile_and_active_vat_in_rate_are_usable(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $supplier = Party::query()
            ->whereNotNull('tax_id')
            ->where('tax_id', '!=', '')
            ->whereHas('supplierRole', fn ($query) => $query->where('is_active', true))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
        $vat = TaxCode::query()->where('kind', 'VAT_IN')->where('is_active', true)->orderBy('id')->first();

        if (! $supplier || ! $vat) {
            $this->markTestSkipped('ต้องมี Supplier ที่มี tax_id และ active VAT IN Tax Code ใน MySQL fixture');
        }

        self::assertNotSame('', trim((string) $supplier->tax_id));
        self::assertNotSame('', trim((string) $supplier->branch_code));
        self::assertSame('VAT_IN', $vat->kind);
        self::assertGreaterThan(0, (float) $vat->rate);

        $result = PurchaseDocumentCalculator::calculate([
            ['description' => 'Real supplier VAT profile QA', 'quantity' => '1', 'unit_price' => '100', 'discount_amount' => '0', 'tax_rate' => (string) $vat->rate],
        ], 'VAT_IN');

        self::assertSame('100.00', $result['subtotal']);
        self::assertSame((string) $vat->rate, $result['lines'][0]['tax_rate']);
        self::assertSame('7.00', $result['tax_amount']);
        self::assertSame('107.00', $result['gross_amount']);
    }
}
