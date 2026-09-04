<?php

namespace App\Modules\Wms\Services;

use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
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
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Support\PurchaseDocumentCalculator;
use App\Modules\Purchasing\Support\PurchaseDocumentState;
use App\Modules\Purchasing\Support\PurchaseThreeWayMatchGate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseDocumentPostingService
{
    public function __construct(
        private readonly AccountMappingService $mappings,
        private readonly JournalPostingService $journals,
        private readonly OpenItemService $openItems,
        private readonly AuditLogger $audit,
        private readonly ?PurchaseThreeWayMatchGate $matchGate = null,
    ) {}

    /**
     * Read-only preview for the document page. The post() method remains the
     * authority and repeats every validation inside its transaction.
     */
    public function postReadiness(PurchaseDocument $document): array
    {
        if ($document->status !== 'APPROVED') {
            return ['ready' => false, 'blockers' => ['อนุมัติเอกสารก่อน Post']];
        }

        if ($document->document_type !== 'INVOICE') {
            return ['ready' => true, 'blockers' => []];
        }

        try {
            $event = 'supplier_invoice.expense';
            $this->mappings->resolveForEvent($event, 'ACCOUNTS_PAYABLE');
            if ($document->tax_treatment === 'VAT_IN' && $document->tax_amount !== '0.00') {
                $this->mappings->resolveForEvent($event, 'DEFERRED_INPUT_VAT');
            }

            return ['ready' => true, 'blockers' => []];
        } catch (ValidationException $exception) {
            return ['ready' => false, 'blockers' => collect($exception->errors())->flatten()->values()->all()];
        }
    }

    public function post(PurchaseDocument $purchaseDocument, string $postingDate, User $actor, Request $request): PurchaseDocument
    {
        return DB::transaction(function () use ($purchaseDocument, $postingDate, $actor, $request): PurchaseDocument {
            $document = PurchaseDocument::query()->with('lines.taxCode')->lockForUpdate()->findOrFail($purchaseDocument->id);
            if ($document->status === 'POSTED') {
                if ($document->journal_entry_id && $document->posting_date?->format('Y-m-d') === $postingDate) {
                    return $this->assertPostedIntegrity($document, $postingDate);
                }

                throw ValidationException::withMessages(['posting_date' => 'เอกสารนี้ Post แล้วด้วยวันที่อื่น']);
            }
            try {
                $status = PurchaseDocumentState::post($document->status);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['status' => $exception->getMessage()]);
            }
            if ($postingDate < $document->document_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['posting_date' => 'วันที่ Post ต้องไม่ก่อนวันที่เอกสาร']);
            }
            if (! in_array($document->tax_treatment, ['NONE_VAT', 'VAT_IN'], true)
                || $document->rounding_amount !== '0.00') {
                throw ValidationException::withMessages(['tax_treatment' => 'ไม่รองรับรูปแบบภาษีของเอกสารนี้']);
            }
            if ($document->document_type === 'CREDIT_NOTE' && ! in_array($document->credit_note_mode, ['RETURN', 'NON_RETURN'], true)) {
                throw ValidationException::withMessages(['credit_note_mode' => 'ต้องระบุรูปแบบ Credit Note เป็น RETURN หรือ NON_RETURN']);
            }

            $warehouse = Warehouse::query()->whereKey($document->warehouse_id)->sharedLock()->firstOrFail();
            $apAccount = null;
            $originalOpenItem = null;
            if ($document->document_type === 'CREDIT_NOTE') {
                [$apAccount, $originalOpenItem, $taxAccount] = $this->creditNoteControl($document, $postingDate);
            }
            $party = Party::query()->whereKey($document->supplier_id)->where('is_active', true)->sharedLock()->first();
            $role = $party ? PartyRole::query()->where('party_id', $party->id)->where('role', 'SUPPLIER')->where('is_active', true)->sharedLock()->first() : null;
            if (! $party || ! $role) {
                throw ValidationException::withMessages(['supplier_id' => 'Supplier และบทบาทต้องเปิดใช้งานก่อน Post']);
            }
            if ($document->document_type === 'INVOICE') {
                $term = PaymentTerm::query()->whereKey($document->payment_term_id)->where('is_active', true)->sharedLock()->first();
                if (! $term || ! $document->due_date) {
                    throw ValidationException::withMessages(['payment_term_id' => 'เงื่อนไขการชำระเงินของ Invoice ต้องเปิดใช้งานก่อน Post']);
                }
                $expectedDueDate = PaymentDueDate::calculate($document->document_date->format('Y-m-d'), $term->due_rule, $term->credit_days);
                if ($document->due_date->format('Y-m-d') !== $expectedDueDate) {
                    throw ValidationException::withMessages(['payment_term_id' => 'วันครบกำหนดไม่ตรงกับเงื่อนไขการชำระเงิน กรุณาบันทึกร่างใหม่']);
                }
            }

            $this->assertTaxCodes($document);
            $this->assertWithholding($document);
            if ($document->document_type === 'INVOICE') {
                ($this->matchGate ?? new PurchaseThreeWayMatchGate)->assertReady($document);
            }
            $calculation = PurchaseDocumentCalculator::calculate($document->lines->map(fn ($line) => [
                'description' => $line->description, 'account_id' => $line->account_id,
                'quantity' => $line->quantity, 'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount, 'tax_code_id' => $line->tax_code_id,
                'tax_rate' => $line->tax_rate,
            ])->all(), $document->tax_treatment, (bool) $document->prices_include_vat, (int) $document->tax_decimal_places, (int) $document->tax_decimal_places);
            if ($document->subtotal !== $calculation['subtotal'] || $document->tax_amount !== $calculation['tax_amount'] || $document->gross_amount !== $calculation['gross_amount']) {
                throw ValidationException::withMessages(['lines' => 'ยอดเอกสารไม่ตรงกับบรรทัด กรุณาตรวจสอบเอกสาร']);
            }
            $this->assertLineAccounts($document);

            $event = $document->document_type === 'INVOICE' ? 'supplier_invoice.expense' : 'purchase_credit_note';
            $postingMetadata = null;
            if ($document->document_type === 'INVOICE') {
                $apResolution = $this->mappings->resolveForEvent($event, 'ACCOUNTS_PAYABLE');
                $apAccount = $apResolution['account'];
                $provenance = [$apResolution['provenance']];
                $taxAccount = null;
                if ($document->tax_treatment === 'VAT_IN') {
                    $taxResolution = $this->mappings->resolveForEvent($event, 'DEFERRED_INPUT_VAT');
                    $taxAccount = $taxResolution['account'];
                    $provenance[] = $taxResolution['provenance'];
                }
                $postingMetadata = ['contract_version' => 1, 'event_code' => $event, 'accounts' => $provenance];
            }
            $journal = $this->journals->post([
                'source_type' => 'PURCHASING',
                'source_id' => (string) $document->id,
                'source_reference' => $document->document_number,
                'event_code' => $event,
                'entry_date' => $postingDate,
                'document_date' => $document->document_date->format('Y-m-d'),
                'description' => ($document->document_type === 'INVOICE' ? 'ใบตั้งหนี้ ' : 'ใบลดหนี้ ').$document->document_number,
                'posting_metadata' => $postingMetadata,
                'lines' => $this->journalLines($document, $apAccount, $taxAccount),
            ], $warehouse, $actor);

            $controlLine = $this->controlLine($journal, $document, $apAccount);
            $openItem = $this->openItems->recordFromJournalLine($controlLine, [
                'document_type' => $document->document_type,
                'document_number' => $document->document_number,
                'due_date' => $document->document_type === 'INVOICE' ? $document->due_date?->format('Y-m-d') : null,
                'withholding_tax_code_id' => $document->withholding_tax_code_id, 'withholding_rate' => $document->withholding_rate,
                'withholding_base' => $document->withholding_base, 'withholding_amount' => $document->withholding_amount,
            ]);

            if ($originalOpenItem) {
                $this->openItems->allocate([
                    'debit_open_item_id' => $openItem->id,
                    'credit_open_item_id' => $originalOpenItem->id,
                    'allocation_date' => $postingDate,
                    'amount' => $document->gross_amount,
                    'source_type' => 'PURCHASING',
                    'source_id' => "credit-note:{$document->id}:invoice:{$document->original_document_id}",
                ], $actor);
            }

            $before = $document->only(['status', 'posting_date', 'journal_entry_id', 'posted_by', 'posted_at']);
            $document->update([
                'status' => $status,
                'posting_date' => $postingDate,
                'journal_entry_id' => $journal->id,
                'posted_by' => $actor->id,
                'posted_at' => now(),
                'updated_by' => $actor->id,
            ]);
            $this->audit->record('wms.purchase_document.posted', $document, $before, $document->only(array_keys($before)), $actor, $request);

            return $document->fresh(['journalEntry']);
        }, 3);
    }

    private function creditNoteControl(PurchaseDocument $document, string $postingDate): array
    {
        $original = PurchaseDocument::query()->with('lines')
            ->whereKey($document->original_document_id)
            ->where('warehouse_id', $document->warehouse_id)
            ->where('supplier_id', $document->supplier_id)
            ->where('document_type', 'INVOICE')
            ->where('status', 'POSTED')
            ->lockForUpdate()
            ->first();
        if (! $original || ! $original->journal_entry_id) {
            throw ValidationException::withMessages(['original_document_id' => 'ใบลดหนี้ต้องอ้างใบตั้งหนี้ที่ Post แล้วของ Supplier และ Warehouse เดียวกัน']);
        }
        if ($document->document_date->lt($original->document_date)) {
            throw ValidationException::withMessages(['original_document_id' => 'วันที่ใบลดหนี้ต้องไม่ก่อนใบตั้งหนี้ต้นทาง']);
        }
        $unknownAccounts = $document->lines->pluck('account_id')->unique()->diff($original->lines->pluck('account_id')->unique());
        if ($unknownAccounts->isNotEmpty()) {
            throw ValidationException::withMessages(['lines' => 'บัญชีของใบลดหนี้ต้องเป็นบัญชีที่มีอยู่ในใบตั้งหนี้ต้นทาง']);
        }
        $this->assertCreditAccountCeilings($document, $original);

        $openItems = OpenItem::query()
            ->join('journal_entry_lines as original_control_lines', 'original_control_lines.id', '=', 'finance_open_items.journal_entry_line_id')
            ->where('warehouse_id', $document->warehouse_id)
            ->where('finance_open_items.ledger_type', 'AP')
            ->where('finance_open_items.party_type', 'SUPPLIER')
            ->where('finance_open_items.party_id', (string) $document->supplier_id)
            ->where('finance_open_items.document_type', 'INVOICE')
            ->where('finance_open_items.balance_side', 'CREDIT')
            ->where('original_control_lines.journal_entry_id', $original->journal_entry_id)
            ->where('original_control_lines.subledger_type', 'SUPPLIER')
            ->where('original_control_lines.subledger_id', (string) $document->supplier_id)
            ->where('original_control_lines.debit', '0.00')
            ->where('original_control_lines.credit', $original->gross_amount)
            ->where('finance_open_items.document_number', $original->document_number)
            ->where('finance_open_items.original_amount', $original->gross_amount)
            ->get(['finance_open_items.*']);
        if ($openItems->count() !== 1) {
            throw ValidationException::withMessages(['original_document_id' => 'ไม่พบ AP Open Item ของใบตั้งหนี้ต้นทาง']);
        }
        $openItem = $openItems->first();

        $account = Account::query()->withTrashed()->whereKey($openItem->account_id)->sharedLock()->firstOrFail();
        if ($account->trashed() || ! $account->is_active || ! $account->is_postable || $account->control_account_type !== 'AP') {
            throw ValidationException::withMessages(['original_document_id' => 'บัญชีคุม AP ของใบตั้งหนี้ต้นทางต้องเปิดใช้งาน']);
        }
        $this->openItems->assertAmountAvailable($openItem, $postingDate, $document->gross_amount, 'gross_amount');

        $taxAccount = null;
        if ($document->tax_treatment === 'VAT_IN') {
            $taxAccounts = JournalEntryLine::query()->with('account')
                ->where('journal_entry_id', $original->journal_entry_id)
                ->where('subledger_type', 'TAX')->get()->pluck('account')->filter()->unique('id')->values();
            if ($taxAccounts->count() !== 1) {
                throw ValidationException::withMessages(['original_document_id' => 'ใบลดหนี้ VAT ต้องใช้บัญชีภาษีจาก Journal ใบตั้งหนี้ต้นทางเพียงหนึ่งบัญชี']);
            }
            $taxAccount = $taxAccounts->sole();
            if ($taxAccount->trashed() || ! $taxAccount->is_active || ! $taxAccount->is_postable || $taxAccount->control_account_type !== 'INPUT_VAT') {
                throw ValidationException::withMessages(['original_document_id' => 'บัญชีภาษีของ Journal ใบตั้งหนี้ต้นทางต้องเปิดใช้งาน']);
            }
        }

        return [$account, $openItem, $taxAccount];
    }

    private function assertCreditAccountCeilings(PurchaseDocument $document, PurchaseDocument $original): void
    {
        $limits = $this->amountsByAccount($original->lines);
        $credits = PurchaseDocument::query()->with('lines')
            ->where('original_document_id', $original->id)
            ->where('document_type', 'CREDIT_NOTE')
            ->where('status', '!=', 'VOID')
            ->get();
        $used = [];
        foreach ($credits as $credit) {
            foreach ($this->amountsByAccount($credit->lines) as $accountId => $amount) {
                $used[$accountId] = JournalBalance::add($used[$accountId] ?? '0.00', $amount);
            }
        }
        foreach ($used as $accountId => $amount) {
            if (! isset($limits[$accountId]) || JournalBalance::subtract($limits[$accountId], $amount)[0] === '-') {
                throw ValidationException::withMessages(['lines' => 'ยอดใบลดหนี้สะสมแยกตามบัญชีเกินยอดใบตั้งหนี้ต้นทาง']);
            }
        }
    }

    private function amountsByAccount($lines): array
    {
        $amounts = [];
        foreach ($lines as $line) {
            $amounts[$line->account_id] = JournalBalance::add($amounts[$line->account_id] ?? '0.00', $line->gross_amount);
        }

        return $amounts;
    }

    private function controlLine(JournalEntry $journal, PurchaseDocument $document, Account $apAccount): JournalEntryLine
    {
        $lines = JournalEntryLine::query()
            ->where('journal_entry_id', $journal->id)
            ->where('account_id', $apAccount->id)
            ->where('subledger_type', 'SUPPLIER')
            ->where('subledger_id', (string) $document->supplier_id)
            ->lockForUpdate()
            ->get();
        $invoice = $document->document_type === 'INVOICE';
        if ($lines->count() !== 1
            || JournalBalance::decimal($lines->first()->debit) !== ($invoice ? '0.00' : $document->gross_amount)
            || JournalBalance::decimal($lines->first()->credit) !== ($invoice ? $document->gross_amount : '0.00')) {
            throw ValidationException::withMessages(['journal_entry_id' => 'บรรทัดบัญชีคุม AP ของ Journal ไม่ตรงกับเอกสาร']);
        }

        return $lines->first();
    }

    private function assertPostedIntegrity(PurchaseDocument $document, string $postingDate): PurchaseDocument
    {
        $event = $document->document_type === 'INVOICE' ? 'supplier_invoice.expense' : 'purchase_credit_note';
        $journal = JournalEntry::query()->whereKey($document->journal_entry_id)->where('status', 'POSTED')
            ->where('source_type', 'PURCHASING')->where('source_event', $event)->where('source_id', (string) $document->id)
            ->where('source_reference', $document->document_number)->whereDate('entry_date', $postingDate)->first();
        if (! $journal) {
            throw ValidationException::withMessages(['journal_entry_id' => 'ข้อมูล Journal ของเอกสารที่ Post แล้วไม่ตรงกับเอกสาร']);
        }
        $controlLines = JournalEntryLine::query()->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entry_lines.journal_entry_id', $journal->id)->where('accounts.control_account_type', 'AP')
            ->where('journal_entry_lines.subledger_type', 'SUPPLIER')->where('journal_entry_lines.subledger_id', (string) $document->supplier_id)
            ->get(['journal_entry_lines.*']);
        if ($controlLines->count() !== 1) {
            throw ValidationException::withMessages(['journal_entry_id' => 'Journal ต้องมีบรรทัดบัญชีคุม AP เพียงหนึ่งบรรทัด']);
        }
        $line = $controlLines->first();
        $invoice = $document->document_type === 'INVOICE';
        if (JournalBalance::decimal($line->debit) !== ($invoice ? '0.00' : $document->gross_amount)
            || JournalBalance::decimal($line->credit) !== ($invoice ? $document->gross_amount : '0.00')) {
            throw ValidationException::withMessages(['journal_entry_id' => 'ยอดบัญชีคุม AP ของ Journal ไม่ตรงกับเอกสาร']);
        }
        $openItems = OpenItem::query()->where('journal_entry_line_id', $line->id)->where('account_id', $line->account_id)
            ->where('document_number', $document->document_number)->where('original_amount', $document->gross_amount)
            ->where('balance_side', $invoice ? 'CREDIT' : 'DEBIT')->get();
        if ($openItems->count() !== 1) {
            throw ValidationException::withMessages(['journal_entry_id' => 'Open Item ของเอกสารที่ Post แล้วไม่สมบูรณ์']);
        }
        if (! $invoice) {
            $sourceId = "credit-note:{$document->id}:invoice:{$document->original_document_id}";
            $allocations = Allocation::query()->where('source_type', 'PURCHASING')->where('source_id', $sourceId)
                ->where('debit_open_item_id', $openItems->first()->id)->where('amount', $document->gross_amount)
                ->whereDate('allocation_date', $postingDate)
                ->whereHas('creditOpenItem.journalEntryLine', fn ($query) => $query->where('journal_entry_id', $document->originalDocument?->journal_entry_id))
                ->whereNull('reversed_at')->whereNull('reversal_date')->get();
            if ($allocations->count() !== 1) {
                throw ValidationException::withMessages(['journal_entry_id' => 'Allocation ของใบลดหนี้ที่ Post แล้วไม่สมบูรณ์']);
            }
        }

        return $document->fresh(['journalEntry']);
    }

    private function assertLineAccounts(PurchaseDocument $document): void
    {
        $ids = $document->lines->pluck('account_id')->unique()->sort()->values();
        $accounts = Account::query()->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->whereKey($ids)->sharedLock()->get([
                'accounts.id', 'accounts.is_active', 'accounts.is_postable', 'accounts.control_account_type', 'account_types.code as type_code',
            ])->keyBy('id');
        foreach ($document->lines as $index => $line) {
            $account = $accounts->get($line->account_id);
            $isReturnInventoryLine = $document->document_type === 'CREDIT_NOTE' && $document->credit_note_mode === 'RETURN' && $account?->control_account_type === 'INVENTORY';
            if (! $account || ! $account->is_active || ! $account->is_postable || ($account->control_account_type !== null && ! $isReturnInventoryLine)
                || ! in_array($account->type_code, ['ASSET', 'EXPENSE'], true)) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => 'บัญชีรายการต้องเป็นบัญชีย่อย Asset/Expense ที่เปิดใช้งานและไม่ใช่บัญชีคุม']);
            }
        }
    }

    private function assertTaxCodes(PurchaseDocument $document): void
    {
        $hasTax = $document->tax_treatment === 'VAT_IN';
        foreach ($document->lines as $index => $line) {
            $valid = $line->taxCode && $line->taxCode->is_active && $line->taxCode->kind === 'VAT_IN';
            if ($hasTax !== $valid) {
                throw ValidationException::withMessages(["lines.{$index}.tax_code_id" => $hasTax
                    ? 'VAT IN ต้องใช้ Tax Code ที่เปิดใช้งาน'
                    : 'NONE VAT ห้ามระบุ Tax Code']);
            }
        }
    }

    private function assertWithholding(PurchaseDocument $document): void
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
        if (! $tax || $base->isGreaterThan(BigDecimal::of((string) $document->subtotal)) || (string) $tax->rate !== (string) $document->withholding_rate) {
            throw ValidationException::withMessages(['withholding_tax_code_id' => 'ข้อมูล WHT ต้องเป็น Tax Code ที่เปิดใช้งานและตรงกับเอกสาร']);
        }
        $amount = $base->multipliedBy(BigDecimal::of((string) $tax->rate))->dividedBy(100, 2, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP)->__toString();
        if ($amount !== $document->withholding_amount) {
            throw ValidationException::withMessages(['withholding_amount' => 'ยอด WHT ไม่ตรงกับฐานและอัตรา']);
        }
    }

    private function journalLines(PurchaseDocument $document, Account $apAccount, ?Account $taxAccount = null): array
    {
        $invoice = $document->document_type === 'INVOICE';
        $lines = $document->lines->map(fn ($line) => [
            'account_id' => $line->account_id,
            'subledger_type' => $line->item_id ? 'ITEM' : null,
            'subledger_id' => $line->item_id ? (string) $line->item_id : null,
            'description' => $line->description,
            'debit' => $invoice ? ($document->tax_treatment === 'VAT_IN' ? $line->tax_base : $line->gross_amount) : '0.00',
            'credit' => $invoice ? '0.00' : ($document->tax_treatment === 'VAT_IN' ? $line->tax_base : $line->gross_amount),
            'tax_code_id' => $document->tax_treatment === 'VAT_IN' ? $line->tax_code_id : null,
            'tax_base' => $document->tax_treatment === 'VAT_IN' ? $line->tax_base : null,
            'tax_amount' => $document->tax_treatment === 'VAT_IN' ? $line->tax_amount : null,
            'tax_point_date' => $document->tax_treatment === 'VAT_IN' ? $document->document_date->format('Y-m-d') : null,
        ])->all();
        if ($document->tax_treatment === 'VAT_IN') {
            if (! $taxAccount) {
                throw ValidationException::withMessages(['account_mapping' => 'ยังไม่ได้ตั้งค่า Deferred Input VAT สำหรับ Posting event นี้']);
            }
            foreach ($document->lines->groupBy('tax_code_id') as $taxCodeId => $taxLines) {
                $base = $taxLines->reduce(fn (string $total, $line) => JournalBalance::add($total, $line->tax_base), '0.00');
                $tax = $taxLines->reduce(fn (string $total, $line) => JournalBalance::add($total, $line->tax_amount), '0.00');
                if ($tax === '0.00') {
                    continue;
                }
                $lines[] = [
                    'account_id' => $taxAccount->id, 'subledger_type' => 'TAX', 'subledger_id' => (string) $taxCodeId,
                    'tax_code_id' => (int) $taxCodeId, 'description' => 'ภาษีซื้อพักรอรับรู้ '.$document->document_number,
                    'debit' => $invoice ? $tax : '0.00', 'credit' => $invoice ? '0.00' : $tax,
                    'tax_base' => $base, 'tax_amount' => $tax,
                    'tax_point_date' => $document->document_date->format('Y-m-d'),
                ];
            }
        }
        $lines[] = [
            'account_id' => $apAccount->id,
            'subledger_type' => 'SUPPLIER',
            'subledger_id' => (string) $document->supplier_id,
            'description' => $document->document_number,
            'debit' => $invoice ? '0.00' : $document->gross_amount,
            'credit' => $invoice ? $document->gross_amount : '0.00',
        ];

        return $lines;
    }
}
