<?php

namespace App\Modules\Pos\Services;

use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Finance\Support\PaymentDueDate;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\SalesDocument;
use App\Modules\Pos\Models\SalesDocumentLine;
use App\Modules\Pos\Support\SalesDocumentCalculator;
use App\Modules\Pos\Support\SalesDocumentState;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SalesDocumentPostingService
{
    public function __construct(
        private readonly AccountMappingService $mappings,
        private readonly JournalPostingService $journals,
        private readonly OpenItemService $openItems,
        private readonly AuditLogger $audit,
        private readonly CreditLimitService $creditLimits,
    ) {}

    public function post(SalesDocument $salesDocument, string $postingDate, Warehouse $warehouse, User $actor, Request $request): SalesDocument
    {
        return DB::transaction(function () use ($salesDocument, $postingDate, $warehouse, $actor, $request): SalesDocument {
            $document = SalesDocument::query()->whereKey($salesDocument->id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
            if ($document->status === 'POSTED') {
                if ($document->posting_date?->format('Y-m-d') !== $postingDate) {
                    throw ValidationException::withMessages(['posting_date' => 'เอกสารนี้ Post ด้วยวันที่อื่นแล้ว']);
                }
                $this->assertPostedIntegrity($document);

                return $document;
            }
            try {
                $status = SalesDocumentState::post($document->status);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['status' => $exception->getMessage()]);
            }
            if ($postingDate < $document->document_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['posting_date' => 'วันที่ Post ต้องไม่ก่อนวันที่เอกสาร']);
            }

            $source = $document->document_type === 'CREDIT_NOTE'
                ? SalesDocument::query()->whereKey($document->source_invoice_id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->first()
                : null;
            if ($document->document_type === 'CREDIT_NOTE' && (! $source || $source->status !== 'POSTED' || (int) $source->party_id !== (int) $document->party_id)) {
                throw ValidationException::withMessages(['source_invoice_id' => 'ใบลดหนี้ต้องอ้าง Invoice ที่ Post แล้วของลูกค้าและคลังเดียวกัน']);
            }
            if ($source && (! $source->posting_date || $postingDate < $source->posting_date->format('Y-m-d'))) {
                throw ValidationException::withMessages(['posting_date' => 'วันที่ Post ใบลดหนี้ต้องไม่ก่อนวันที่ Post Invoice ต้นทาง']);
            }

            $party = Party::query()->whereKey($document->party_id)->where('is_active', true)->lockForUpdate()->first();
            $role = $party ? PartyRole::query()->where('party_id', $party->id)->where('role', 'CUSTOMER')->where('is_active', true)->lockForUpdate()->first() : null;
            if (! $role) {
                throw ValidationException::withMessages(['party_id' => 'ลูกค้าและบทบาทต้องเปิดใช้งาน']);
            }
            if ($document->document_type === 'INVOICE') {
                $this->creditLimits->assertInvoiceWithinLimit((int) $document->party_id, (string) $document->total_amount);
            }
            if ($document->document_type === 'INVOICE') {
                $term = PaymentTerm::query()->whereKey($document->payment_term_id)->where('is_active', true)->lockForUpdate()->first();
                if (! $term || $document->due_date?->format('Y-m-d') !== PaymentDueDate::calculate($document->document_date->format('Y-m-d'), $term->due_rule, $term->credit_days)) {
                    throw ValidationException::withMessages(['payment_term_id' => 'เงื่อนไขชำระเงินต้องเปิดใช้งานและตรงกับวันครบกำหนด']);
                }
            }

            $lines = SalesDocumentLine::query()->with('taxCode')->where('sales_document_id', $document->id)->orderBy('line_number')->lockForUpdate()->get();
            $this->assertDomain($document, $lines, $source);
            [$arAccount, $sourceOpenItem] = $source ? $this->creditAr($source) : [$this->mappings->resolve('SALES_AR'), null];
            $journal = $this->journals->post([
                'source_type' => 'POS',
                'source_id' => (string) $document->id,
                'source_reference' => $document->document_number,
                'event_code' => $document->document_type === 'INVOICE' ? 'sales_invoice' : 'sales_credit_note',
                'entry_date' => $postingDate,
                'document_date' => $document->document_date->format('Y-m-d'),
                'description' => ($document->document_type === 'INVOICE' ? 'ใบแจ้งหนี้ ' : 'ใบลดหนี้ ').$document->document_number,
                'lines' => $this->journalLines($document, $lines, $arAccount),
            ], $warehouse, $actor);

            $controlLines = $journal->lines()->where('account_id', $arAccount->id)->where('subledger_type', 'CUSTOMER')->where('subledger_id', (string) $document->party_id)->get()
                ->filter(fn ($line) => $document->document_type === 'INVOICE'
                    ? $line->debit === $document->total_amount && $line->credit === '0.00'
                    : $line->credit === $document->total_amount && $line->debit === '0.00');
            if ($controlLines->count() !== 1) {
                throw ValidationException::withMessages(['journal_entry_id' => 'Journal ต้องมีบรรทัด AR ของลูกค้าและยอดเอกสารเพียงหนึ่งบรรทัด']);
            }
            $controlLine = $controlLines->first();
            $openItem = $this->openItems->recordFromJournalLine($controlLine, [
                'document_type' => $document->document_type,
                'document_number' => $document->document_number,
                'due_date' => $document->document_type === 'INVOICE' ? $document->due_date?->format('Y-m-d') : null,
                'withholding_tax_code_id' => $document->withholding_tax_code_id, 'withholding_rate' => $document->withholding_rate,
                'withholding_base' => $document->withholding_base, 'withholding_amount' => $document->withholding_amount,
            ]);
            if ($sourceOpenItem) {
                $this->openItems->allocate([
                    'debit_open_item_id' => $sourceOpenItem->id,
                    'credit_open_item_id' => $openItem->id,
                    'allocation_date' => $postingDate,
                    'amount' => $document->total_amount,
                    'source_type' => 'POS',
                    'source_id' => "sales-credit-note:{$document->id}",
                ], $actor);
            }

            $before = $document->only(['status', 'journal_entry_id', 'posting_date', 'posted_by', 'posted_at']);
            $document->update(['status' => $status, 'journal_entry_id' => $journal->id, 'posting_date' => $postingDate, 'posted_by' => $actor->id, 'posted_at' => now(), 'updated_by' => $actor->id]);
            $this->audit->record('pos.sales_document.posted', $document, $before, $document->only(array_keys($before)), $actor, $request);

            return $document;
        }, 3);
    }

    private function assertDomain(SalesDocument $document, $lines, ?SalesDocument $source): void
    {
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'เอกสารต้องมีรายการ']);
        }
        if ($lines->contains(fn (SalesDocumentLine $line): bool => $line->item_id !== null)) {
            throw ValidationException::withMessages(['lines' => 'รายการสินค้าคงคลังยังรอเปิด Stock Issue/COGS จึงยังลงบัญชีไม่ได้']);
        }
        $this->assertTaxCodes($lines);
        $this->assertWithholding($document, $calculation = SalesDocumentCalculator::calculate($lines->map(fn ($line) => [
            'description' => $line->description, 'quantity' => $line->quantity, 'unit' => $line->unit,
            'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount,
            'revenue_account_id' => $line->revenue_account_id, 'tax_code_id' => $line->tax_code_id, 'tax_rate' => $line->tax_rate,
        ])->all(), (bool) $document->price_includes_vat, (int) $document->tax_decimal_places));
        foreach (['subtotal', 'discount_amount', 'tax_base', 'tax_amount', 'total_amount'] as $field) {
            if ($document->{$field} !== $calculation[$field]) {
                throw ValidationException::withMessages(['lines' => 'ยอดเอกสารไม่ตรงกับบรรทัด กรุณากลับไปบันทึกร่างใหม่']);
            }
        }

        $accountIds = $lines->pluck('revenue_account_id')->map(fn ($id) => (int) $id)->unique();
        $accounts = Account::query()->with('type')->whereKey($accountIds)->lockForUpdate()->get()->keyBy('id');
        foreach ($accountIds as $id) {
            $account = $accounts->get($id);
            if (! $account || ! $account->is_active || ! $account->is_postable || $account->control_account_type !== null || $account->type?->code !== 'REVENUE') {
                throw ValidationException::withMessages(['lines' => 'บัญชีรายได้ต้องเปิดใช้งาน ลงรายการได้ และไม่ใช่บัญชีคุม']);
            }
        }
        if ($source) {
            $sourceAccounts = SalesDocumentLine::query()->where('sales_document_id', $source->id)->pluck('revenue_account_id')->map(fn ($id) => (int) $id)->unique();
            if ($accountIds->diff($sourceAccounts)->isNotEmpty()) {
                throw ValidationException::withMessages(['lines' => 'บัญชีรายได้ของใบลดหนี้ต้องมีอยู่ใน Invoice ต้นทาง']);
            }
            $this->assertCreditRevenueCeiling($document, $lines, $source);
        }
    }

    private function assertTaxCodes($lines): void
    {
        foreach ($lines as $index => $line) {
            if (! $line->taxCode || ! $line->taxCode->is_active || ! in_array($line->taxCode->kind, ['NONE_VAT', 'VAT_OUT'], true)) {
                throw ValidationException::withMessages(["lines.{$index}.tax_code_id" => 'Tax Code ต้องเป็น NONE VAT หรือ VAT OUT และเปิดใช้งาน']);
            }
        }
    }

    private function assertWithholding(SalesDocument $document, array $calculation): void
    {
        if ($document->document_type !== 'INVOICE') {
            if ($document->withholding_tax_code_id || $document->withholding_amount !== '0.00') {
                throw ValidationException::withMessages(['withholding_tax_code_id' => 'ใบลดหนี้ยังไม่รองรับ WHT']);
            }

            return;
        }
        if (! $document->withholding_tax_code_id) {
            if ($document->withholding_amount !== '0.00' || $document->withholding_base !== '0.00') {
                throw ValidationException::withMessages(['withholding_amount' => 'ข้อมูล WHT ไม่สมบูรณ์']);
            }

            return;
        }
        $tax = TaxCode::query()->whereKey($document->withholding_tax_code_id)->where('kind', 'WHT')->where('is_active', true)->lockForUpdate()->first();
        $base = BigDecimal::of((string) $document->withholding_base);
        if (! $tax || $base->isGreaterThan(BigDecimal::of((string) $calculation['tax_base'])) || (string) $tax->rate !== (string) $document->withholding_rate) {
            throw ValidationException::withMessages(['withholding_tax_code_id' => 'ข้อมูล WHT ต้องเป็น Tax Code ที่เปิดใช้งานและตรงกับเอกสาร']);
        }
        $amount = $base->multipliedBy(BigDecimal::of((string) $tax->rate))->dividedBy(100, 2, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP)->__toString();
        if ($amount !== $document->withholding_amount) {
            throw ValidationException::withMessages(['withholding_amount' => 'ยอด WHT ไม่ตรงกับฐานและอัตรา']);
        }
    }

    private function creditAr(SalesDocument $source): array
    {
        if (! $source->journal_entry_id) {
            throw ValidationException::withMessages(['source_invoice_id' => 'Invoice ต้นทางไม่มี Journal']);
        }
        $openItem = $this->sourceOpenItem($source);
        $account = Account::query()->withTrashed()->with('type')->whereKey($openItem->account_id)->lockForUpdate()->firstOrFail();
        $this->mappings->assertCompatible('SALES_AR', $account);

        return [$account, $openItem];
    }

    private function sourceOpenItem(SalesDocument $source): OpenItem
    {
        $openItems = OpenItem::query()->where('warehouse_id', $source->warehouse_id)->where('party_id', $source->party_id)
            ->where('party_type', 'CUSTOMER')->where('ledger_type', 'AR')->where('balance_side', 'DEBIT')->where('document_type', 'INVOICE')
            ->where('document_number', $source->document_number)->whereHas('journalEntryLine', fn ($query) => $query->where('journal_entry_id', $source->journal_entry_id))->limit(2)->get();
        if ($openItems->count() !== 1) {
            throw ValidationException::withMessages(['source_invoice_id' => 'ต้องพบ AR Open Item ของ Invoice ต้นทางเพียงหนึ่งรายการ']);
        }

        return $openItems->first();
    }

    private function assertCreditRevenueCeiling(SalesDocument $document, $lines, SalesDocument $source): void
    {
        $sourceTotals = SalesDocumentLine::query()->where('sales_document_id', $source->id)->get(['revenue_account_id', 'line_total'])
            ->groupBy('revenue_account_id')->map(fn ($group) => $group->reduce(fn (string $sum, $line) => JournalBalance::add($sum, $line->line_total), '0.00'));
        $usedTotals = SalesDocumentLine::query()->join('sales_documents', 'sales_documents.id', '=', 'sales_document_lines.sales_document_id')
            ->where('sales_documents.source_invoice_id', $source->id)->where('sales_documents.document_type', 'CREDIT_NOTE')
            ->where('sales_documents.status', '!=', 'VOID')->where('sales_documents.id', '!=', $document->id)
            ->get(['sales_document_lines.revenue_account_id', 'sales_document_lines.line_total'])
            ->groupBy('revenue_account_id')->map(fn ($group) => $group->reduce(fn (string $sum, $line) => JournalBalance::add($sum, $line->line_total), '0.00'));
        $candidateTotals = $lines->groupBy('revenue_account_id')->map(fn ($group) => $group->reduce(fn (string $sum, $line) => JournalBalance::add($sum, $line->line_total), '0.00'));

        foreach ($candidateTotals as $accountId => $candidate) {
            $used = JournalBalance::add($usedTotals->get($accountId, '0.00'), $candidate);
            if (JournalBalance::totals([['debit' => $used, 'credit' => 0]])['debit'] > JournalBalance::totals([['debit' => $sourceTotals->get($accountId, '0.00'), 'credit' => 0]])['debit']) {
                throw ValidationException::withMessages(['lines' => 'ยอดใบลดหนี้สะสมแยกตามบัญชีรายได้เกิน Invoice ต้นทาง']);
            }
        }
    }

    private function assertPostedIntegrity(SalesDocument $document): void
    {
        if (! $document->journal_entry_id) {
            throw ValidationException::withMessages(['status' => 'เอกสารระบุว่า Post แล้วแต่ไม่มี Journal']);
        }
        $event = $document->document_type === 'INVOICE' ? 'sales_invoice' : 'sales_credit_note';
        $journal = JournalEntry::query()->whereKey($document->journal_entry_id)->where('status', 'POSTED')->where('source_type', 'POS')
            ->where('source_event', $event)->where('source_id', (string) $document->id)->where('source_reference', $document->document_number)->first();
        if (! $journal || $journal->entry_date->format('Y-m-d') !== $document->posting_date?->format('Y-m-d')) {
            throw ValidationException::withMessages(['status' => 'ข้อมูล Journal ของเอกสารที่ Post แล้วไม่สมบูรณ์']);
        }
        $openItem = OpenItem::query()->where('warehouse_id', $document->warehouse_id)->where('party_id', $document->party_id)
            ->where('document_type', $document->document_type)->where('document_number', $document->document_number)
            ->whereHas('journalEntryLine', fn ($query) => $query->where('journal_entry_id', $journal->id))->first();
        if (! $openItem || $openItem->ledger_type !== 'AR' || $openItem->balance_side !== ($document->document_type === 'INVOICE' ? 'DEBIT' : 'CREDIT')
            || $openItem->original_amount !== $document->total_amount) {
            throw ValidationException::withMessages(['status' => 'ข้อมูล AR Open Item ของเอกสารที่ Post แล้วไม่สมบูรณ์']);
        }
        if ($document->document_type === 'CREDIT_NOTE') {
            $source = SalesDocument::query()->whereKey($document->source_invoice_id)->where('status', 'POSTED')->first();
            $sourceOpenItem = $source ? $this->sourceOpenItem($source) : null;
            $allocation = $sourceOpenItem ? Allocation::query()->where('debit_open_item_id', $sourceOpenItem->id)->where('credit_open_item_id', $openItem->id)
                ->where('source_type', 'POS')->where('source_id', "sales-credit-note:{$document->id}")->whereNull('reversed_at')->whereNull('reversal_date')->first() : null;
            if (! $allocation || $allocation->allocation_date->format('Y-m-d') !== $document->posting_date->format('Y-m-d') || $allocation->amount !== $document->total_amount) {
                throw ValidationException::withMessages(['status' => 'ข้อมูลจัดสรรใบลดหนี้ของเอกสารที่ Post แล้วไม่สมบูรณ์']);
            }
        }
    }

    private function journalLines(SalesDocument $document, $lines, Account $arAccount): array
    {
        $invoice = $document->document_type === 'INVOICE';
        $result = [[
            'account_id' => $arAccount->id, 'subledger_type' => 'CUSTOMER', 'subledger_id' => (string) $document->party_id,
            'description' => $document->document_number, 'debit' => $invoice ? $document->total_amount : '0.00', 'credit' => $invoice ? '0.00' : $document->total_amount,
        ]];
        foreach ($lines->groupBy(fn (SalesDocumentLine $line) => $line->revenue_account_id.':'.$line->tax_code_id) as $group) {
            $amount = $group->reduce(fn (string $sum, SalesDocumentLine $line) => JournalBalance::add($sum, $line->tax_base), '0.00');
            $tax = $group->reduce(fn (string $sum, SalesDocumentLine $line) => JournalBalance::add($sum, $line->tax_amount), '0.00');
            $line = $group->first();
            $result[] = [
                'account_id' => $line->revenue_account_id, 'tax_code_id' => $line->tax_code_id, 'description' => $document->document_number,
                'debit' => $invoice ? '0.00' : $amount, 'credit' => $invoice ? $amount : '0.00', 'tax_base' => $amount, 'tax_amount' => $tax,
                'tax_point_date' => $document->document_date->format('Y-m-d'),
            ];
        }
        $taxGroups = $lines->filter(fn (SalesDocumentLine $line) => $line->tax_amount !== '0.00')->groupBy('tax_code_id');
        if ($taxGroups->isNotEmpty()) {
            $taxAccount = $this->mappings->resolve('DEFERRED_OUTPUT_VAT');
            foreach ($taxGroups as $taxCodeId => $group) {
                $base = $group->reduce(fn (string $sum, SalesDocumentLine $line) => JournalBalance::add($sum, $line->tax_base), '0.00');
                $tax = $group->reduce(fn (string $sum, SalesDocumentLine $line) => JournalBalance::add($sum, $line->tax_amount), '0.00');
                $result[] = [
                    'account_id' => $taxAccount->id, 'subledger_type' => 'TAX', 'subledger_id' => (string) $taxCodeId,
                    'tax_code_id' => (int) $taxCodeId, 'description' => 'ภาษีขายพักรอรับรู้ '.$document->document_number,
                    'debit' => $invoice ? '0.00' : $tax, 'credit' => $invoice ? $tax : '0.00',
                    'tax_base' => $base, 'tax_amount' => $tax, 'tax_point_date' => $document->document_date->format('Y-m-d'),
                ];
            }
        }

        return $result;
    }
}
