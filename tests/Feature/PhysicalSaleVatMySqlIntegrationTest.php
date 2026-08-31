<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\User;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\WithholdingRealization;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Services\PhysicalSaleCancellationService;
use App\Modules\Pos\Services\PhysicalSalePostingService;
use App\Modules\Pos\Services\PhysicalSaleReceiptService;
use App\Modules\Pos\Support\PhysicalSaleWithholdingSnapshot;
use App\Modules\Pos\Support\SalesDocumentCalculator;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Dedicated rollback-only proof for VAT-inclusive stock HS posting. */
final class PhysicalSaleVatMySqlIntegrationTest extends TestCase
{
    public function test_vat_inclusive_hs_posts_and_reverses_the_frozen_vat_snapshot(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        foreach (['pos_physical_sales.tax_base', 'pos_physical_sale_lines.tax_code_id', 'pos_physical_sale_lines.tax_base', 'pos_physical_sale_lines.tax_amount'] as $required) {
            [$table, $column] = explode('.', $required);
            if (! Schema::hasColumn($table, $column)) {
                $this->markTestSkipped("ต้อง migrate {$required} ก่อนรัน E2E นี้");
            }
        }
        $actor = User::query()->first();
        $vat = TaxCode::query()->where('kind', 'VAT_OUT')->where('is_active', true)->orderBy('id')->first();
        if (! $actor || ! $vat || ! FiscalPeriod::query()->where('status', 'OPEN')->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->exists()) {
            $this->markTestSkipped('ต้องมี User, VAT OUT และงวดบัญชีเปิดสำหรับ MySQL fixture');
        }

        DB::beginTransaction();
        try {
            [$bank, $party, $item, $balance] = $this->fixture();
            if (! $bank || ! $party || ! $item || ! $balance) {
                $this->markTestSkipped('ไม่พบบัญชีรับเงิน ลูกค้า หรือ Stock ที่พร้อมสำหรับ VAT HS rollback fixture');
            }
            $entered = '107.00';
            $calculation = SalesDocumentCalculator::calculate([[
                'quantity' => '1.0000', 'unit_price' => $entered, 'discount_amount' => '0.00',
                'tax_code_id' => $vat->id, 'tax_rate' => $vat->rate,
            ]], true);
            $sale = $this->draft($actor, $bank, $party, $item, $balance, $vat, $calculation);
            $posted = app(PhysicalSalePostingService::class)->post($sale, today()->toDateString(), $bank->warehouse, $actor, Request::create('/', 'POST'), [[
                'bank_account_id' => $bank->id, 'amount' => $calculation['total_amount'], 'reference' => 'VAT-E2E',
            ]]);
            $journal = $posted->journalEntry()->with('lines')->firstOrFail();
            $deferred = app(AccountMappingService::class)->resolve('DEFERRED_OUTPUT_VAT');

            self::assertSame('POSTED', $posted->status);
            self::assertSame($calculation['total_amount'], (string) $posted->total_amount);
            self::assertSame($calculation['tax_base'], (string) $posted->tax_base);
            self::assertSame($calculation['tax_amount'], (string) $posted->tax_amount);
            self::assertSame(JournalBalance::totals($journal->lines->map->only(['debit', 'credit'])->all())['debit'], JournalBalance::totals($journal->lines->map->only(['debit', 'credit'])->all())['credit']);
            self::assertSame($calculation['tax_base'], (string) $journal->lines->where('account_id', $item->sales_account_id)->sole()->credit);
            $vatLine = $journal->lines->where('account_id', $deferred->id)->sole();
            self::assertSame((int) $vat->id, (int) $vatLine->tax_code_id);
            self::assertSame($calculation['tax_amount'], (string) $vatLine->credit);

            if (DocumentSequence::query()->where(['warehouse_id' => $bank->warehouse_id, 'document_type' => 'SALES_RETURN', 'is_active' => true])->exists()) {
                $voided = app(PhysicalSaleCancellationService::class)->cancel($posted, $bank->warehouse, today()->toDateString(), 'VAT MySQL rollback cancellation proof', $actor, Request::create('/', 'POST'));
                $reversal = $journal->fresh()->reversal()->with('lines')->firstOrFail();
                self::assertSame('VOID', $voided->status);
                self::assertSame($calculation['tax_amount'], (string) $reversal->lines->where('account_id', $deferred->id)->sole()->debit);
                self::assertSame((int) $vat->id, (int) $reversal->lines->where('account_id', $deferred->id)->sole()->tax_code_id);
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_vat_invoice_creates_ar_and_receipt_realizes_the_frozen_wht_snapshot(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        $actor = User::query()->first();
        $vat = TaxCode::query()->where('kind', 'VAT_OUT')->where('is_active', true)->orderBy('id')->first();
        $wht = TaxCode::query()->where('kind', 'WHT')->where('is_active', true)->orderBy('id')->first();
        if (! $actor || ! $vat || ! $wht) {
            $this->markTestSkipped('ต้องมี User, VAT OUT และ WHT สำหรับ MySQL fixture');
        }

        DB::beginTransaction();
        try {
            [$bank, $party, $item, $balance] = $this->fixture();
            if (! $bank || ! $party || ! $item || ! $balance || ! DocumentSequence::query()->where(['warehouse_id' => $bank->warehouse_id, 'document_type' => 'RECEIPT', 'is_active' => true])->exists()) {
                $this->markTestSkipped('ไม่พบ fixture สำหรับ IV/AR/Receipt MySQL proof');
            }
            $calculation = SalesDocumentCalculator::calculate([[
                'quantity' => '1.0000', 'unit_price' => '107.00', 'discount_amount' => '0.00',
                'tax_code_id' => $vat->id, 'tax_rate' => $vat->rate,
            ]], true);
            $withholding = PhysicalSaleWithholdingSnapshot::build($wht, $calculation['tax_base'], $calculation['tax_base']);
            $sale = $this->draft($actor, $bank, $party, $item, $balance, $vat, $calculation, 'IV', $withholding);
            $posted = app(PhysicalSalePostingService::class)->post($sale, today()->toDateString(), $bank->warehouse, $actor, Request::create('/', 'POST'));
            $openItem = OpenItem::query()->where('document_number', $posted->document_number)->sole();
            $cash = JournalBalance::subtract($calculation['total_amount'], $withholding['withholding_amount']);
            $receipt = app(PhysicalSaleReceiptService::class)->receive($posted, [
                'settlement_date' => today()->toDateString(), 'allocation_amount' => $calculation['total_amount'],
                'tenders' => [['bank_account_id' => $bank->id, 'amount' => $cash, 'reference' => 'VAT-IV-E2E']],
            ], $bank->warehouse, $actor, Request::create('/', 'POST'));

            self::assertSame('POSTED', $posted->status);
            self::assertSame($calculation['total_amount'], (string) $openItem->original_amount);
            self::assertSame($withholding['withholding_amount'], (string) $openItem->withholding_amount);
            self::assertSame('POSTED', $receipt->status);
            self::assertSame($calculation['total_amount'], (string) $receipt->gross_amount);
            self::assertSame($withholding['withholding_amount'], (string) $receipt->withholding_amount);
            self::assertSame('0.00', app(OpenItemService::class)->remainingAt($openItem->fresh(), today()->toDateString()));
            self::assertSame($withholding['withholding_amount'], (string) WithholdingRealization::query()->where('open_item_id', $openItem->id)->sum('tax_amount'));
        } finally {
            DB::rollBack();
        }
    }

    private function fixture(): array
    {
        $bank = BankAccount::query()->with('warehouse')->where('is_active', true)->where('currency_code', 'THB')->orderBy('id')->first();
        $party = Party::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('role', 'CUSTOMER')->where('is_active', true))->orderBy('id')->first();
        $balance = $bank ? StockBalance::query()->where('warehouse_id', $bank->warehouse_id)->where('available', '>=', '1')->orderBy('id')->first() : null;
        $item = $balance ? Item::query()->whereKey($balance->item_id)->where('is_active', true)->where('is_stock_item', true)
            ->whereNotNull('sales_account_id')->whereNotNull('inventory_account_id')->whereNotNull('cogs_account_id')->first() : null;

        return [$bank, $party, $item, $balance];
    }

    private function draft(User $actor, BankAccount $bank, Party $party, Item $item, StockBalance $balance, TaxCode $vat, array $calculation, string $documentType = 'HS', array $withholding = []): PhysicalSale
    {
        $suffix = strtoupper(str()->random(12));
        $sale = PhysicalSale::query()->create([
            'warehouse_id' => $bank->warehouse_id, 'document_type' => $documentType, 'document_number' => "{$documentType}-VAT-E2E-{$suffix}",
            'source_type' => 'SALES_ORDER', 'source_id' => random_int(1000000, 9999999), 'party_id' => $party->id,
            'party_code' => $party->code, 'party_name' => $party->name, 'party_address' => $party->address,
            'document_date' => today()->toDateString(), 'due_date' => $documentType === 'IV' ? today()->toDateString() : null, 'tax_treatment' => 'VAT_OUT', 'prices_include_vat' => true,
            'subtotal' => $calculation['subtotal'], 'discount_amount' => $calculation['discount_amount'], 'tax_base' => $calculation['tax_base'], 'tax_amount' => $calculation['tax_amount'], 'total_amount' => $calculation['total_amount'],
            'withholding_tax_code_id' => $withholding['withholding_tax_code_id'] ?? null, 'withholding_rate' => $withholding['withholding_rate'] ?? '0.0000', 'withholding_base' => $withholding['withholding_base'] ?? '0.00', 'withholding_amount' => $withholding['withholding_amount'] ?? '0.00',
            'status' => 'DRAFT', 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $sale->lines()->create([
            'line_number' => 1, 'item_id' => $item->id, 'sale_uom_id' => $balance->uom_id, 'stock_uom_id' => $balance->uom_id,
            'quantity' => '1.00000000', 'uom_factor' => '1.00000000', 'stock_quantity' => '1.00000000', 'unit_price' => '107.0000',
            'discount_amount' => '0.00', 'tax_code_id' => $vat->id, 'tax_rate' => $vat->rate, 'tax_base' => $calculation['lines'][0]['tax_base'], 'tax_amount' => $calculation['lines'][0]['tax_amount'], 'line_total' => $calculation['lines'][0]['line_total'],
            'item_snapshot' => ['code' => $item->code, 'name' => $item->name], 'conversion_snapshot' => ['factor' => '1.00000000', 'source' => 'vat-e2e'],
        ]);

        return $sale->fresh();
    }
}
