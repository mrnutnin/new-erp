<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\User;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesReturn;
use App\Modules\Pos\Models\SalesReturnInventoryLink;
use App\Modules\Pos\Services\PhysicalSalePostingService;
use App\Modules\Pos\Services\SalesReturnPostingService;
use App\Modules\Pos\Support\PhysicalSaleWithholdingSnapshot;
use App\Modules\Pos\Support\SalesDocumentCalculator;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Dedicated, rollback-only proof for partial HS cash refunds and IV credit notes. */
final class SalesReturnMySqlIntegrationTest extends TestCase
{
    public function test_partial_hs_return_refunds_selected_bank_and_reverses_stock_cogs_with_vat_wht(): void
    {
        $this->assertReady();
        $actor = User::query()->first();
        if (! $actor) {
            $this->markTestSkipped('ต้องมี User fixture');
        }

        DB::beginTransaction();
        try {
            [$bank, $party, $item, $balance, $vat, $wht] = $this->fixture();
            if (! $bank || ! $party || ! $item || ! $balance || ! $vat || ! $wht) {
                $this->markTestSkipped('ต้องมีบัญชีรับเงิน ลูกค้า Stock, VAT OUT และ WHT สำหรับ HS return fixture');
            }
            [$sale, $calculation, $withholding] = $this->postedSale('HS', $actor, $bank, $party, $item, $balance, $vat, $wht);
            $return = $this->draftReturn($sale, '0.50000000', $actor);
            $posted = app(SalesReturnPostingService::class)->post($return, today()->toDateString(), $bank->warehouse, $actor, Request::create('/', 'POST'), $bank->id);
            $refund = $posted->journalEntry()->with('lines')->firstOrFail();
            $cogs = $posted->cogsJournalEntry()->with('lines')->firstOrFail();
            $cashReceived = JournalBalance::subtract($calculation['total_amount'], $withholding['withholding_amount']);
            $expectedRefund = BigDecimal::of($cashReceived)->multipliedBy('0.5')->toScale(2, RoundingMode::HALF_UP)->__toString();

            self::assertSame('POSTED', $posted->status);
            self::assertSame($bank->id, $posted->refund_bank_account_id);
            self::assertSame($expectedRefund, (string) $posted->refund_amount);
            self::assertBalanced($refund->lines);
            self::assertBalanced($cogs->lines);
            self::assertSame($expectedRefund, (string) $refund->lines->where('account_id', $bank->account_id)->sole()->credit);

            $movement = StockMovement::query()->where('source_id', "sales-return:{$posted->id}:line:{$posted->lines()->sole()->id}")->sole();
            self::assertSame('IN', $movement->direction);
            self::assertSame('0.50000000', (string) $movement->base_quantity);
            self::assertSame(1, SalesReturnInventoryLink::query()->where('sales_return_line_id', $posted->lines()->sole()->id)->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_partial_iv_return_creates_credit_note_reduces_ar_and_reverses_stock_cogs_with_vat_wht(): void
    {
        $this->assertReady();
        $actor = User::query()->first();
        if (! $actor) {
            $this->markTestSkipped('ต้องมี User fixture');
        }

        DB::beginTransaction();
        try {
            [$bank, $party, $item, $balance, $vat, $wht] = $this->fixture();
            if (! $bank || ! $party || ! $item || ! $balance || ! $vat || ! $wht) {
                $this->markTestSkipped('ต้องมีบัญชีรับเงิน ลูกค้า Stock, VAT OUT และ WHT สำหรับ IV return fixture');
            }
            [$sale, $calculation] = $this->postedSale('IV', $actor, $bank, $party, $item, $balance, $vat, $wht);
            $invoice = OpenItem::query()->where('document_number', $sale->document_number)->sole();
            $return = $this->draftReturn($sale, '0.50000000', $actor);
            $posted = app(SalesReturnPostingService::class)->post($return, today()->toDateString(), $bank->warehouse, $actor, Request::create('/', 'POST'));
            $creditNote = $posted->journalEntry()->with('lines')->firstOrFail();
            $cogs = $posted->cogsJournalEntry()->with('lines')->firstOrFail();
            $expectedCredit = BigDecimal::of($calculation['total_amount'])->multipliedBy('0.5')->toScale(2, RoundingMode::HALF_UP)->__toString();

            self::assertSame('POSTED', $posted->status);
            self::assertNull($posted->refund_bank_account_id);
            self::assertSame('0.00', (string) $posted->refund_amount);
            self::assertBalanced($creditNote->lines);
            self::assertBalanced($cogs->lines);
            self::assertSame($expectedCredit, (string) $creditNote->lines->where('subledger_type', 'CUSTOMER')->where('credit', $expectedCredit)->sole()->credit);
            self::assertSame($expectedCredit, app(OpenItemService::class)->remainingAt($invoice->fresh(), today()->toDateString()));

            $movement = StockMovement::query()->where('source_id', "sales-return:{$posted->id}:line:{$posted->lines()->sole()->id}")->sole();
            self::assertSame('IN', $movement->direction);
            self::assertSame('0.50000000', (string) $movement->base_quantity);
            self::assertSame(1, SalesReturnInventoryLink::query()->where('sales_return_line_id', $posted->lines()->sole()->id)->count());
        } finally {
            DB::rollBack();
        }
    }

    private function assertReady(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        foreach (['pos_sales_returns.refund_bank_account_id', 'pos_sales_returns.refund_amount', 'pos_sales_return_inventory_links.sales_return_line_id'] as $required) {
            [$table, $column] = explode('.', $required);
            if (! Schema::hasColumn($table, $column)) {
                $this->markTestSkipped("ต้อง migrate {$required} ก่อนรัน E2E นี้");
            }
        }
        if (! FiscalPeriod::query()->where('status', 'OPEN')->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->exists()) {
            $this->markTestSkipped('ต้องมีงวดบัญชีเปิดสำหรับ MySQL return fixture');
        }
    }

    private function fixture(): array
    {
        $bank = BankAccount::query()->with('warehouse')->where('is_active', true)->where('currency_code', 'THB')->orderBy('id')->first();
        $party = Party::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('role', 'CUSTOMER')->where('is_active', true))->orderBy('id')->first();
        $balance = $bank ? StockBalance::query()->where('warehouse_id', $bank->warehouse_id)->where('available', '>=', '1')->orderBy('id')->first() : null;
        $item = $balance ? Item::query()->whereKey($balance->item_id)->where('is_active', true)->where('is_stock_item', true)
            ->whereNotNull('sales_account_id')->whereNotNull('inventory_account_id')->whereNotNull('cogs_account_id')->first() : null;

        return [$bank, $party, $item, $balance, TaxCode::query()->where('kind', 'VAT_OUT')->where('is_active', true)->orderBy('id')->first(), TaxCode::query()->where('kind', 'WHT')->where('is_active', true)->orderBy('id')->first()];
    }

    private function postedSale(string $documentType, User $actor, BankAccount $bank, Party $party, Item $item, StockBalance $balance, TaxCode $vat, TaxCode $wht): array
    {
        $calculation = SalesDocumentCalculator::calculate([[
            'quantity' => '1.0000', 'unit_price' => '107.00', 'discount_amount' => '0.00', 'tax_code_id' => $vat->id, 'tax_rate' => $vat->rate,
        ]], true);
        $withholding = PhysicalSaleWithholdingSnapshot::build($wht, $calculation['tax_base'], $calculation['tax_base']);
        $suffix = strtoupper(str()->random(12));
        $sale = PhysicalSale::query()->create([
            'warehouse_id' => $bank->warehouse_id, 'document_type' => $documentType, 'document_number' => "{$documentType}-RETURN-E2E-{$suffix}",
            'source_type' => 'SALES_ORDER', 'source_id' => random_int(1000000, 9999999), 'party_id' => $party->id,
            'party_code' => $party->code, 'party_name' => $party->name, 'party_address' => $party->address,
            'document_date' => today()->toDateString(), 'due_date' => $documentType === 'IV' ? today()->toDateString() : null,
            'tax_treatment' => 'VAT_OUT', 'prices_include_vat' => true,
            'subtotal' => $calculation['subtotal'], 'discount_amount' => $calculation['discount_amount'], 'tax_base' => $calculation['tax_base'], 'tax_amount' => $calculation['tax_amount'], 'total_amount' => $calculation['total_amount'],
            ...$withholding, 'status' => 'DRAFT', 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $sale->lines()->create([
            'line_number' => 1, 'item_id' => $item->id, 'sale_uom_id' => $balance->uom_id, 'stock_uom_id' => $balance->uom_id,
            'quantity' => '1.00000000', 'uom_factor' => '1.00000000', 'stock_quantity' => '1.00000000', 'unit_price' => '107.0000', 'discount_amount' => '0.00',
            'tax_code_id' => $vat->id, 'tax_rate' => $vat->rate, 'tax_base' => $calculation['lines'][0]['tax_base'], 'tax_amount' => $calculation['lines'][0]['tax_amount'], 'line_total' => $calculation['lines'][0]['line_total'],
            'item_snapshot' => ['code' => $item->code, 'name' => $item->name], 'conversion_snapshot' => ['factor' => '1.00000000', 'source' => 'sales-return-e2e'],
        ]);
        $sale = app(PhysicalSalePostingService::class)->post($sale, today()->toDateString(), $bank->warehouse, $actor, Request::create('/', 'POST'), $documentType === 'HS' ? [[
            'bank_account_id' => $bank->id, 'amount' => JournalBalance::subtract($calculation['total_amount'], $withholding['withholding_amount']), 'reference' => 'RETURN-E2E',
        ]] : []);

        return [$sale, $calculation, $withholding];
    }

    private function draftReturn(PhysicalSale $sale, string $quantity, User $actor): SalesReturn
    {
        $line = $sale->lines()->sole();
        $total = BigDecimal::of($quantity)->multipliedBy((string) $line->unit_price)->toScale(2, RoundingMode::HALF_UP)->__toString();
        $return = SalesReturn::query()->create([
            'warehouse_id' => $sale->warehouse_id, 'physical_sale_id' => $sale->id, 'document_number' => 'SR-RETURN-E2E-'.strtoupper(str()->random(12)),
            'document_date' => today()->toDateString(), 'reason' => 'MySQL partial sales return rollback proof', 'party_code' => $sale->party_code,
            'party_name' => $sale->party_name, 'party_address' => $sale->party_address, 'total_amount' => $total, 'status' => 'DRAFT', 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $return->lines()->create([
            'physical_sale_line_id' => $line->id, 'line_number' => $line->line_number, 'item_id' => $line->item_id, 'uom_id' => $line->sale_uom_id, 'stock_uom_id' => $line->stock_uom_id,
            'quantity' => $quantity, 'stock_quantity' => $quantity, 'uom_factor' => '1.00000000', 'unit_price' => $line->unit_price, 'line_total' => $total,
            'conversion_snapshot' => $line->conversion_snapshot, 'item_snapshot' => $line->item_snapshot,
        ]);

        return $return;
    }

    private static function assertBalanced($lines): void
    {
        $totals = JournalBalance::totals($lines->map(fn ($line) => ['debit' => $line->debit, 'credit' => $line->credit])->all());
        self::assertSame($totals['debit'], $totals['credit']);
    }
}
