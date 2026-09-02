<?php

namespace App\Modules\Pos\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\PhysicalSaleLine;
use App\Modules\Wms\Models\Item;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the current accounting masters required to post a frozen HS/IV
 * draft. It does not write a journal; callers must use the returned intent in
 * their outer stock/COGS/revenue transaction.
 */
final class PhysicalSaleRevenuePostingPlan
{
    public function __construct(private readonly AccountMappingService $mappings) {}

    /**
     * @return array{ar_account_id:int,revenue_account_ids:array<int,int>,journal:array<string,mixed>}
     */
    public function build(PhysicalSale $sale, array $tenders = [], array $advanceApplications = [], bool $lockForUpdate = true): array
    {
        $saleId = (int) $sale->id;
        if ($saleId < 1) {
            throw ValidationException::withMessages(['sale' => 'ต้องบันทึกร่าง HS/IV ก่อนเตรียม Journal รายได้']);
        }

        $lines = PhysicalSaleLine::query()
            ->where('physical_sale_id', $saleId)
            ->orderBy('line_number')
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'HS/IV ต้องมีรายการสินค้าเพื่อเตรียม Journal รายได้']);
        }
        $this->assertTaxSnapshot($sale, $lines);

        $items = Item::query()
            ->whereKey($lines->pluck('item_id')->unique())
            ->where('is_active', true)
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'sales_account_id'])
            ->keyBy('id');
        if ($items->count() !== $lines->pluck('item_id')->unique()->count()) {
            throw ValidationException::withMessages(['lines' => 'สินค้าใน HS/IV ต้องยังเปิดใช้งานก่อนลงบัญชี']);
        }

        $revenueAccountIds = $lines->mapWithKeys(function (PhysicalSaleLine $line) use ($items): array {
            $item = $items->get((int) $line->item_id);
            $accountId = (int) ($item?->sales_account_id ?? 0);
            if ($accountId < 1) {
                throw ValidationException::withMessages(["lines.{$line->line_number}" => 'สินค้าต้องกำหนดบัญชีรายได้ก่อนลงบัญชี']);
            }

            return [(int) $line->id => $accountId];
        });

        $accounts = Account::query()
            ->with('type')
            ->whereKey($revenueAccountIds->unique()->values())
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->get()
            ->keyBy('id');
        foreach ($revenueAccountIds as $lineId => $accountId) {
            $account = $accounts->get($accountId);
            if (! $account || $account->trashed() || ! $account->is_active || ! $account->is_postable
                || $account->control_account_type !== null || $account->type?->code !== 'REVENUE') {
                throw ValidationException::withMessages(["lines.{$lineId}" => 'บัญชีรายได้ของสินค้าต้องเปิดใช้งาน ลงรายการได้ และเป็นบัญชีรายได้']);
            }
        }

        $event = 'sales_invoice';
        $arResolution = $this->mappings->resolveForEvent($event, 'ACCOUNTS_RECEIVABLE');
        $ar = $arResolution['account'];
        $taxResolution = JournalBalance::decimal($sale->tax_amount) !== '0.00'
            ? $this->mappings->resolveForEvent($event, 'DEFERRED_OUTPUT_VAT')
            : null;
        $provenance = $lines->map(function (PhysicalSaleLine $line) use ($revenueAccountIds, $event): array {
            return [
                'event_code' => $event,
                'account_role' => 'SALES_REVENUE_ITEM_'.(int) $line->item_id,
                'account_id' => (int) $revenueAccountIds->get((int) $line->id),
                'source' => 'MASTER',
                'source_type' => 'ITEM',
                'source_id' => (string) $line->item_id,
                'mapping_id' => null,
                'mapping_version' => null,
            ];
        })->unique('account_role')->values()->all();
        if ($sale->document_type !== 'HS') {
            array_unshift($provenance, $arResolution['provenance']);
        }
        if ($taxResolution) {
            $provenance[] = $taxResolution['provenance'];
        }
        $salePayload = [
            'id' => $saleId,
            'document_type' => $sale->document_type,
            'warehouse_id' => $sale->warehouse_id,
            'party_id' => $sale->party_id,
            'ar_account_id' => $ar->id,
            'document_number' => $sale->document_number,
            'document_date' => $sale->document_date?->format('Y-m-d'),
            'posting_date' => $sale->posting_date?->format('Y-m-d') ?? $sale->document_date?->format('Y-m-d'),
            'tax_amount' => $sale->tax_amount,
            'lines' => $lines->map(function (PhysicalSaleLine $line) use ($revenueAccountIds): array {
                return [
                    'line_number' => $line->line_number,
                    'tax_code_id' => $line->tax_code_id, 'tax_rate' => $line->tax_rate,
                    'tax_base' => $line->tax_base, 'tax_amount' => $line->tax_amount, 'line_total' => $line->line_total,
                    'revenue_account_id' => $revenueAccountIds->get((int) $line->id),
                ];
            })->all(),
        ];
        if ($sale->document_type !== 'HS') {
            $journal = $this->invoiceJournal($sale, $salePayload, $ar->id, $taxResolution['account'] ?? null);
        } else {
            $journal = $this->cashJournal($sale, $salePayload, $tenders, $advanceApplications, $taxResolution['account'] ?? null, $provenance);
        }
        $journal['posting_metadata'] = ['contract_version' => 1, 'event_code' => $event, 'accounts' => collect($provenance)->unique('account_role')->values()->all()];
        $totals = JournalBalance::totals($journal['lines']);
        $saleTotal = JournalBalance::totals([['debit' => $sale->total_amount, 'credit' => '0.00']])['debit'];
        if ($totals['debit'] !== $totals['credit'] || $totals['debit'] !== $saleTotal) {
            throw ValidationException::withMessages(['total_amount' => 'Journal รายได้ไม่ตรงกับยอดรวม HS/IV']);
        }

        return [
            'ar_account_id' => (int) $ar->id,
            'revenue_account_ids' => $revenueAccountIds->all(),
            'journal' => $journal,
        ];
    }

    private function cashJournal(PhysicalSale $sale, array $payload, array $tenders, array $advanceApplications, ?Account $taxAccount, array &$provenance): array
    {
        $withholding = JournalBalance::decimal($sale->withholding_amount);
        $advanceApplied = collect($advanceApplications)->reduce(fn (string $sum, array $row): string => JournalBalance::add($sum, $row['amount'] ?? '0'), '0.00');
        $cashDue = JournalBalance::subtract(JournalBalance::subtract($sale->total_amount, $withholding), $advanceApplied);
        if ($cashDue < '0.00') {
            throw ValidationException::withMessages(['advance_allocations' => 'ยอด AI เกินยอดขายสุทธิ']);
        }
        $received = collect($tenders)->reduce(fn (string $sum, array $tender): string => JournalBalance::add($sum, JournalBalance::decimal($tender['amount'] ?? '0')), '0.00');
        if ($received < $cashDue || ($cashDue !== '0.00' && $received === '0.00')) {
            throw ValidationException::withMessages(['tenders' => 'ยอดรับชำระไม่ครบ กรุณาระบุช่องทางรับเงินให้ครบยอดสุทธิ']);
        }

        $accounts = BankAccount::query()->with('account')->where('warehouse_id', $sale->warehouse_id)
            ->where('is_active', true)->whereKey(collect($tenders)->pluck('bank_account_id')->filter()->unique())->lockForUpdate()->get()->keyBy('id');
        $lines = [];
        foreach ($tenders as $index => $tender) {
            $bank = $accounts->get((int) ($tender['bank_account_id'] ?? 0));
            if (! $bank || ! $bank->account || ! $bank->account->is_active || ! $bank->account->is_postable || $bank->currency_code !== 'THB' || $bank->account->control_account_type !== $bank->type) {
                throw ValidationException::withMessages(["tenders.$index.bank_account_id" => 'บัญชีรับเงินต้องเปิดใช้งาน เป็น THB และผูกบัญชีคุมให้ถูกประเภท']);
            }
            $lines[] = ['account_id' => $bank->account_id, 'subledger_type' => strtoupper($bank->type), 'subledger_id' => (string) $bank->id,
                'description' => trim($payload['document_number'].' '.($tender['reference'] ?? '')), 'debit' => JournalBalance::decimal($tender['amount']), 'credit' => '0.00', 'tax_base' => '0.00', 'tax_amount' => '0.00'];
            $provenance[] = ['event_code' => 'sales_invoice', 'account_role' => 'BANK_ACCOUNT_'.(int) $bank->id, 'account_id' => (int) $bank->account_id,
                'source' => 'DOCUMENT', 'source_type' => 'BANK_ACCOUNT', 'source_id' => (string) $bank->id, 'mapping_id' => null, 'mapping_version' => null];
        }
        if ($withholding !== '0.00') {
            $whtResolution = $this->mappings->resolveForEvent('sales_invoice', 'WHT_RECEIVABLE');
            $wht = $whtResolution['account'];
            $provenance[] = $whtResolution['provenance'];
            $lines[] = ['account_id' => $wht->id, 'subledger_type' => 'TAX', 'subledger_id' => (string) $wht->id, 'description' => "WHT {$payload['document_number']}", 'debit' => $withholding, 'credit' => '0.00', 'tax_base' => '0.00', 'tax_amount' => '0.00'];
        }
        foreach ($advanceApplications as $row) {
            $lines[] = ['account_id' => (int) $row['account_id'], 'description' => "ตัดเงินล่วงหน้า {$payload['document_number']}", 'debit' => JournalBalance::decimal($row['amount']), 'credit' => '0.00', 'tax_base' => '0.00', 'tax_amount' => '0.00'];
            $provenance[] = $row['provenance'];
        }
        $lines = [...$lines, ...$this->revenueLines($payload, true, $taxAccount)];
        $advance = JournalBalance::subtract($received, $cashDue);
        if ($advance !== '0.00') {
            $advanceResolution = $this->mappings->resolveForEvent('sales_invoice', 'CUSTOMER_ADVANCE');
            $account = $advanceResolution['account'];
            $provenance[] = $advanceResolution['provenance'];
            $lines[] = ['account_id' => $account->id, 'description' => "เงินรับล่วงหน้า {$payload['document_number']}", 'debit' => '0.00', 'credit' => $advance, 'tax_base' => '0.00', 'tax_amount' => '0.00'];
        }

        return ['source_type' => 'POS', 'source_id' => (string) $sale->id, 'source_reference' => $sale->document_number,
            'event_code' => 'sales_invoice', 'entry_date' => $payload['posting_date'], 'document_date' => $payload['document_date'],
            'description' => "Post HS {$sale->document_number}", 'lines' => $lines];
    }

    private function invoiceJournal(PhysicalSale $sale, array $payload, int $arAccountId, ?Account $taxAccount): array
    {
        $taxCodeIds = collect($payload['lines'])->pluck('tax_code_id')->filter()->unique()->values();
        if ($taxCodeIds->count() > 1) {
            throw ValidationException::withMessages(['tax_code_id' => 'HS/IV หนึ่งใบต้องใช้ VAT Tax Code เดียวเพื่อสร้าง AR tax snapshot']);
        }
        $lines = [[
            'account_id' => $arAccountId, 'subledger_type' => 'CUSTOMER', 'subledger_id' => (string) $sale->party_id,
            'description' => $payload['document_number'], 'debit' => $sale->total_amount, 'credit' => '0.00',
            'tax_code_id' => $taxCodeIds->first(), 'tax_base' => $sale->tax_base, 'tax_amount' => $sale->tax_amount,
            'tax_point_date' => $payload['document_date'],
        ], ...$this->revenueLines($payload, true, $taxAccount)];

        return ['source_type' => 'POS', 'source_id' => (string) $sale->id, 'source_reference' => $sale->document_number,
            'event_code' => 'sales_invoice', 'entry_date' => $payload['posting_date'], 'document_date' => $payload['document_date'],
            'description' => "Post IV {$sale->document_number}", 'lines' => $lines];
    }

    private function revenueLines(array $payload, bool $invoice, ?Account $taxAccount): array
    {
        $lines = [];
        foreach (collect($payload['lines'])->groupBy(fn (array $line) => $line['revenue_account_id'].':'.($line['tax_code_id'] ?? 0))->sortKeys() as $items) {
            $line = $items->first();
            $base = $items->reduce(fn (string $sum, array $row): string => JournalBalance::add($sum, $row['tax_base']), '0.00');
            $tax = $items->reduce(fn (string $sum, array $row): string => JournalBalance::add($sum, $row['tax_amount']), '0.00');
            $lines[] = ['account_id' => $line['revenue_account_id'], 'tax_code_id' => $line['tax_code_id'], 'description' => $payload['document_number'],
                'debit' => $invoice ? '0.00' : $base, 'credit' => $invoice ? $base : '0.00', 'tax_base' => $base, 'tax_amount' => $tax,
                'tax_point_date' => $payload['document_date']];
        }
        foreach (collect($payload['lines'])->filter(fn (array $line) => JournalBalance::decimal($line['tax_amount']) !== '0.00')->groupBy('tax_code_id') as $taxCodeId => $items) {
            $base = $items->reduce(fn (string $sum, array $row): string => JournalBalance::add($sum, $row['tax_base']), '0.00');
            $tax = $items->reduce(fn (string $sum, array $row): string => JournalBalance::add($sum, $row['tax_amount']), '0.00');
            if (! $taxAccount) {
                throw ValidationException::withMessages(['tax_amount' => 'ไม่พบการตั้งค่าบัญชีภาษีขายพักรอรับรู้']);
            }
            $lines[] = ['account_id' => $taxAccount->id, 'subledger_type' => 'TAX', 'subledger_id' => (string) $taxCodeId,
                'tax_code_id' => (int) $taxCodeId, 'description' => "ภาษีขายพักรอรับรู้ {$payload['document_number']}",
                'debit' => $invoice ? '0.00' : $tax, 'credit' => $invoice ? $tax : '0.00', 'tax_base' => $base, 'tax_amount' => $tax,
                'tax_point_date' => $payload['document_date']];
        }

        return $lines;
    }

    private function assertTaxSnapshot(PhysicalSale $sale, $lines): void
    {
        $calculation = SalesDocumentCalculator::calculate($lines->map(fn (PhysicalSaleLine $line): array => [
            'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount,
            'tax_code_id' => $line->tax_code_id, 'tax_rate' => $line->tax_rate,
        ])->all(), (bool) $sale->prices_include_vat);
        foreach (['subtotal', 'discount_amount', 'tax_base', 'tax_amount', 'total_amount'] as $field) {
            if (JournalBalance::decimal($sale->{$field}) !== $calculation[$field]) {
                throw ValidationException::withMessages(['tax_amount' => 'VAT snapshot ของ HS/IV ไม่ตรงกับรายการสินค้า']);
            }
        }
        if ($sale->tax_treatment === 'NONE_VAT') {
            if ($lines->contains(fn (PhysicalSaleLine $line) => $line->tax_code_id || JournalBalance::decimal($line->tax_amount) !== '0.00')) {
                throw ValidationException::withMessages(['tax_code_id' => 'เอกสารไม่มี VAT ต้องไม่มี VAT Tax Code']);
            }

            return;
        }
        $codes = TaxCode::query()->whereKey($lines->pluck('tax_code_id')->filter()->unique())->where('kind', 'VAT_OUT')->where('is_active', true)->lockForUpdate()->get()->keyBy('id');
        foreach ($lines as $line) {
            $code = $codes->get((int) $line->tax_code_id);
            if (! $code || (string) $code->rate !== (string) $line->tax_rate) {
                throw ValidationException::withMessages(['tax_code_id' => 'VAT Tax Code ของ HS/IV ต้องเปิดใช้งานและตรงกับ snapshot']);
            }
        }
    }
}
