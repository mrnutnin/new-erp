<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\Models\AdvanceDepositApplication;
use App\Modules\Finance\Services\AdvanceDepositApplicationService;
use App\Modules\Finance\Services\AdvanceDepositSettlementService;
use App\Modules\Pos\Models\PhysicalSale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\AdvanceDepositMySqlIntegrationFixture;
use Tests\TestCase;

/** Dedicated, rollback-only MySQL proof for direct HS AI application. */
final class PhysicalSaleAdvanceDepositMySqlIntegrationTest extends TestCase
{
    public function test_direct_hs_ai_is_idempotent_partial_and_never_creates_ar_or_a_separate_journal(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        foreach (['finance_advance_deposits.tax_treatment', 'finance_advance_deposits.prices_include_vat', 'pos_physical_sales.tax_treatment', 'pos_physical_sales.prices_include_vat', 'finance_advance_deposit_applications.physical_sale_id'] as $required) {
            [$table, $column] = explode('.', $required);
            if (! Schema::hasColumn($table, $column)) {
                $this->markTestSkipped("ต้อง migrate {$required} ก่อนรัน E2E นี้");
            }
        }
        AdvanceDepositMySqlIntegrationFixture::assertReady();
        $actor = User::query()->first();
        if (! $actor) {
            $this->markTestSkipped('ต้องมี User fixture ใน dedicated MySQL DB');
        }

        DB::beginTransaction();
        try {
            $settlement = AdvanceDepositMySqlIntegrationFixture::createPostedSettlement($actor, 'CUSTOMER');
            $advance = app(AdvanceDepositSettlementService::class)->postFromPostedSettlement($settlement, $settlement->bankAccount->warehouse, 'ADVANCE', $actor);
            $advance->update(['tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false]);
            $sale = $this->draftHs($advance, $actor);
            $service = app(AdvanceDepositApplicationService::class);
            $arBefore = DB::table('finance_open_items')->count();
            $journalBefore = DB::table('journal_entries')->count();

            $first = $service->applyToPhysicalSale($sale, [['advance_deposit_id' => $advance->id, 'amount' => '40.00']], now()->toDateString(), $actor);
            $retry = $service->applyToPhysicalSale($sale->fresh(), [['advance_deposit_id' => $advance->id, 'amount' => '40.00']], now()->toDateString(), $actor);
            self::assertSame($first, $retry, 'retry is the concurrent-safe idempotent result');
            self::assertSame('40.00', (string) $advance->fresh()->applied_amount);
            self::assertSame('PARTIAL', $advance->fresh()->status);
            self::assertSame($arBefore, DB::table('finance_open_items')->count());
            self::assertSame($journalBefore, DB::table('journal_entries')->count(), 'allocation waits for the HS revenue journal');
            self::assertSame(1, AdvanceDepositApplication::query()->where('physical_sale_id', $sale->id)->count());

            $fullSettlement = AdvanceDepositMySqlIntegrationFixture::createPostedSettlement($actor, 'CUSTOMER');
            $fullAdvance = app(AdvanceDepositSettlementService::class)->postFromPostedSettlement($fullSettlement, $fullSettlement->bankAccount->warehouse, 'ADVANCE', $actor);
            $fullAdvance->update(['tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false]);
            $service->applyToPhysicalSale($sale->fresh(), [['advance_deposit_id' => $fullAdvance->id, 'amount' => '100.00']], now()->toDateString(), $actor);
            self::assertSame('APPLIED', $fullAdvance->fresh()->status);

            try {
                $service->applyToPhysicalSale($sale->fresh(), [['advance_deposit_id' => $fullAdvance->id, 'amount' => '0.01']], now()->toDateString(), $actor);
                self::fail('over-allocation must be rejected under the advance row lock');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
            $mismatch = $this->draftHs($advance, $actor, 'VAT_OUT');
            try {
                $service->applyToPhysicalSale($mismatch, [['advance_deposit_id' => $advance->id, 'amount' => '1.00']], now()->toDateString(), $actor);
                self::fail('tax-treatment mismatch must be rejected');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        } finally {
            DB::rollBack();
        }
    }

    private function draftHs($advance, User $actor, string $tax = 'NONE_VAT'): PhysicalSale
    {
        $suffix = strtoupper(str()->random(12));

        return PhysicalSale::query()->create([
            'warehouse_id' => $advance->warehouse_id, 'document_type' => 'HS', 'document_number' => "HS-AI-{$suffix}",
            'source_type' => 'SALES_ORDER', 'source_id' => random_int(1000000, 9999999), 'party_id' => $advance->party_id,
            'party_code' => 'AI', 'party_name' => 'AI integration customer', 'document_date' => now()->toDateString(),
            'tax_treatment' => $tax, 'prices_include_vat' => false, 'status' => 'DRAFT', 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }
}
