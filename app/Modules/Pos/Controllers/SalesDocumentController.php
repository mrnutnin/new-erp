<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\PartyRole;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Finance\Support\PaymentDueDate;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\SalesDocument;
use App\Modules\Pos\Requests\ChangeSalesDocumentStatusRequest;
use App\Modules\Pos\Requests\PostSalesDocumentRequest;
use App\Modules\Pos\Requests\SaveSalesDocumentRequest;
use App\Modules\Pos\Services\CreditLimitService;
use App\Modules\Pos\Services\PricingResolver;
use App\Modules\Pos\Services\SalesDocumentPostingService;
use App\Modules\Pos\Support\SalesDiscountApproval;
use App\Modules\Pos\Support\SalesDocumentCalculator;
use App\Modules\Pos\Support\SalesDocumentPrecision;
use App\Modules\Pos\Support\SalesDocumentState;
use App\Modules\Pos\Support\SalesInventoryPostingContract;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Models\UomConversion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Yajra\DataTables\Facades\DataTables;

class SalesDocumentController extends Controller
{
    public function index(): View
    {
        return view('Pos::sales-documents.index');
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $format = (string) $settings->value('date_format');
        $table = DataTables::eloquent($this->documentsQuery($request))
            ->filter(fn (Builder $query) => $this->applySearch($query, $request))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('type_label', fn (SalesDocument $document) => $document->document_type === 'INVOICE' ? 'ใบแจ้งหนี้' : 'ใบลดหนี้')
            ->addColumn('document_date_label', fn (SalesDocument $document) => $document->document_date->format($format))
            ->addColumn('due_date_label', fn (SalesDocument $document) => $document->due_date?->format($format) ?? '—')
            ->addColumn('party_label', fn (SalesDocument $document) => $document->party_code.' · '.$document->party_name)
            ->addColumn('status_label', fn (SalesDocument $document) => match ($document->status) {
                'DRAFT' => 'ร่าง',
                'APPROVED' => 'อนุมัติแล้ว',
                'POSTED' => 'ลงบัญชีแล้ว',
                'VOID' => 'ยกเลิก',
                default => $document->status,
            })
            ->addColumn('payment_status', fn (SalesDocument $document) => $this->paymentStatus($document))
            ->addColumn('payment_status_label', function (SalesDocument $document): string {
                return match ($this->paymentStatus($document)) {
                    'UNPAID' => 'ยังไม่ชำระ',
                    'PARTIAL' => 'ชำระบางส่วน',
                    'PAID' => 'ชำระครบ',
                    'CHECK' => 'ต้องตรวจสอบ AR',
                    default => '—',
                };
            });

        if ($request->user()->hasPermission('pos.sales-documents.view')) {
            $table->addColumn('show_url', fn (SalesDocument $document) => route('pos.sales-documents.show', $document));
        }
        if ($request->user()->hasPermission('pos.sales-documents.update')) {
            $table->addColumn('edit_url', fn (SalesDocument $document) => $document->status === 'DRAFT' ? route('pos.sales-documents.edit', $document) : null);
        }
        if ($request->user()->hasPermission('pos.sales-documents.approve')) {
            $table->addColumn('approve_url', fn (SalesDocument $document) => $document->status === 'DRAFT' ? route('pos.sales-documents.approve', $document) : null);
        }
        if ($request->user()->hasPermission('pos.sales-documents.void')) {
            $table->addColumn('void_url', fn (SalesDocument $document) => in_array($document->status, ['DRAFT', 'APPROVED'], true) ? route('pos.sales-documents.void', $document) : null);
        }
        if ($request->user()->hasPermission('pos.sales-documents.post')) {
            $table->addColumn('post_url', fn (SalesDocument $document) => $document->status === 'APPROVED' ? route('pos.sales-documents.post', $document) : null);
        }

        return $table->toJson();
    }

    public function partyOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $page = max(1, $request->integer('page', 1));
        $rows = Party::query()->join('party_roles', fn ($join) => $join->on('party_roles.party_id', '=', 'parties.id')->where('party_roles.role', 'CUSTOMER')->where('party_roles.is_active', true))
            ->where('parties.is_active', true)->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('parties.code', 'like', "%{$q}%")->orWhere('parties.name', 'like', "%{$q}%")->orWhere('parties.tax_id', 'like', "%{$q}%")))
            ->orderBy('parties.code')->forPage($page, 31)->get(['parties.id', 'parties.code', 'parties.name']);

        return response()->json(['results' => $rows->take(30)->map(fn (Party $party) => ['id' => $party->id, 'text' => $party->code.' · '.$party->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function revenueAccountOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $page = max(1, $request->integer('page', 1));
        $rows = Account::query()->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')->where('account_types.code', 'REVENUE')->where('accounts.is_active', true)->where('accounts.is_postable', true)->whereNull('accounts.control_account_type')
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('accounts.code', 'like', "%{$q}%")->orWhere('accounts.name', 'like', "%{$q}%")))
            ->orderBy('accounts.code')->forPage($page, 31)->get(['accounts.id', 'accounts.code', 'accounts.name']);

        return response()->json(['results' => $rows->take(30)->map(fn (Account $account) => ['id' => $account->id, 'text' => $account->code.' · '.$account->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function taxCodeOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $page = max(1, $request->integer('page', 1));
        $rows = TaxCode::query()->whereIn('kind', ['NONE_VAT', 'VAT_OUT'])->where('is_active', true)
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('code')->forPage($page, 31)->get(['id', 'code', 'name', 'kind', 'rate']);

        return response()->json(['results' => $rows->take(30)->map(fn (TaxCode $tax) => ['id' => $tax->id, 'text' => $tax->code.' · '.$tax->name, 'kind' => $tax->kind, 'rate' => $tax->rate])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function withholdingTaxCodeOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $page = max(1, $request->integer('page', 1));
        $rows = TaxCode::query()->where('kind', 'WHT')->where('is_active', true)
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('code')->forPage($page, 31)->get(['id', 'code', 'name', 'rate']);

        return response()->json(['results' => $rows->take(30)->map(fn (TaxCode $tax) => ['id' => $tax->id, 'text' => $tax->code.' · '.$tax->name.' ('.$tax->rate.'%)', 'rate' => $tax->rate])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function create(Request $request): View
    {
        $type = strtoupper((string) $request->route('documentType', 'INVOICE'));
        abort_unless(in_array($type, ['INVOICE', 'CREDIT_NOTE'], true), 404);
        $source = $type === 'CREDIT_NOTE' ? $this->sourceInvoice($request, $request->integer('source_invoice_id')) : null;

        return $this->formView(new SalesDocument(['document_type' => $type, 'source_invoice_id' => $source?->id, 'party_id' => $source?->party_id, 'payment_term_id' => $source?->payment_term_id, 'document_date' => today(), 'due_date' => $type === 'INVOICE' ? today() : null, 'price_includes_vat' => false]), $source);
    }

    public function store(SaveSalesDocumentRequest $request, DocumentSequenceService $sequences, AuditLogger $audit, GlobalSettings $settings): JsonResponse
    {
        $document = DB::transaction(function () use ($request, $sequences, $audit, $settings) {
            $values = $request->validated();
            $warehouse = $request->attributes->get('selectedWarehouse');
            [$party, $source, $calculation] = $this->validatedDomain($request, $values, null, (int) $settings->value('tax_decimal_places'));
            $sequenceType = $values['document_type'] === 'INVOICE' ? 'SALES_INVOICE' : 'SALES_CREDIT_NOTE';
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', $sequenceType)->where('is_active', true)->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_type' => 'ยังไม่ได้ตั้งค่าเลขเอกสารประเภทนี้']);
            }
            $document = SalesDocument::query()->create([
                ...collect($values)->except('lines')->all(), ...collect($calculation)->except('lines')->all(),
                'warehouse_id' => $warehouse->id, 'branch_id' => $request->attributes->get('selectedBranch')->id, 'document_number' => $sequences->issueForBranch($sequence, $request->attributes->get('selectedBranch'), Carbon::parse($values['document_date'])),
                'source_invoice_id' => $source?->id, 'tax_decimal_places' => (int) $settings->value('tax_decimal_places'),
                'party_code' => $party->code, 'party_name' => $party->name, 'party_tax_id' => $party->tax_id, 'party_branch_code' => $party->branch_code, 'party_address' => $party->address,
                'status' => 'DRAFT', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $document->lines()->createMany($this->lineRows($calculation['lines']));
            $sequences->recordIssued($sequence, $document->document_number, 'sales_documents', (int) $document->id, Carbon::parse($document->document_date), $request->user()->id);
            $audit->record('pos.sales_document.created', $document, [], $this->auditValues($document->load('lines')), $request->user(), $request);

            return $document;
        });

        return response()->json(['status' => true, 'msg' => "สร้างร่าง {$document->document_number} แล้ว", 'redirect' => route('pos.sales-documents.show', $document)]);
    }

    public function show(Request $request, SalesDocument $salesDocument, OpenItemService $openItems): View
    {
        $document = $this->scoped($request, $salesDocument)->load(['lines.revenueAccount', 'lines.taxCode', 'lines.item', 'lines.uom', 'lines.stockUom', 'sourceInvoice']);
        $payment = null;
        $paymentOpenItem = null;
        if ($document->document_type === 'INVOICE' && $document->status === 'POSTED') {
            $openItem = OpenItem::query()
                ->where('warehouse_id', $document->warehouse_id)
                ->where('party_id', $document->party_id)
                ->where('ledger_type', 'AR')
                ->where('party_type', 'CUSTOMER')
                ->where('balance_side', 'DEBIT')
                ->where('document_type', 'INVOICE')
                ->where('document_number', $document->document_number)
                ->whereHas('journalEntryLine', fn (Builder $query) => $query->where('journal_entry_id', $document->journal_entry_id))
                ->first();
            if ($openItem) {
                $remaining = $openItems->remainingAt($openItem, today()->format('Y-m-d'));
                $original = JournalBalance::decimal($openItem->original_amount);
                $paid = JournalBalance::subtract($original, $remaining);
                $payment = [
                    'original' => $original,
                    'paid' => $paid,
                    'remaining' => $remaining,
                    'status' => $remaining === '0.00' ? 'PAID' : ($remaining === $original ? 'UNPAID' : 'PARTIAL'),
                ];
                $paymentOpenItem = $openItem;
            }
        }

        return view('Pos::sales-documents.show', ['salesDocument' => $document, 'payment' => $payment, 'paymentOpenItem' => $paymentOpenItem]);
    }

    public function edit(Request $request, SalesDocument $salesDocument): View
    {
        $salesDocument = $this->scoped($request, $salesDocument);
        abort_unless($salesDocument->status === 'DRAFT', 404);

        return $this->formView($salesDocument->load('lines'), $salesDocument->sourceInvoice);
    }

    public function update(SaveSalesDocumentRequest $request, SalesDocument $salesDocument, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse
    {
        DB::transaction(function () use ($request, $salesDocument, $audit, $sequences) {
            $document = SalesDocument::query()->whereKey($this->scoped($request, $salesDocument)->id)->lockForUpdate()->firstOrFail();
            if ($document->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'แก้ไขได้เฉพาะเอกสาร Draft']);
            }
            $values = $request->validated();
            if ($values['document_type'] !== $document->document_type || (int) ($values['source_invoice_id'] ?? 0) !== (int) ($document->source_invoice_id ?? 0)) {
                throw ValidationException::withMessages(['document_type' => 'ไม่สามารถเปลี่ยนประเภทหรือเอกสารต้นทาง']);
            }
            [$party, , $calculation] = $this->validatedDomain($request, $values, $document, (int) $document->tax_decimal_places);
            $before = $this->auditValues($document->load('lines'));
            $newNumber = $document->document_number;
            if ($document->document_date->toDateString() !== $values['document_date']) {
                $sequenceType = $document->document_type === 'INVOICE' ? 'SALES_INVOICE' : 'SALES_CREDIT_NOTE';
                $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', $sequenceType)->where('is_active', true)->lockForUpdate()->first();
                if (! $sequence) {
                    throw ValidationException::withMessages(['document_date' => 'ยังไม่ได้ตั้งค่าเลขเอกสารสำหรับวันที่ใหม่']);
                }
                $newNumber = $sequences->replaceDraftNumber($sequence, $document->document_number, 'sales_documents', (int) $document->id, Carbon::parse($values['document_date']), $request->user()->id);
            }
            $document->update([...collect($values)->except('lines')->all(), ...collect($calculation)->except('lines')->all(), 'document_number' => $newNumber, 'party_code' => $party->code, 'party_name' => $party->name, 'party_tax_id' => $party->tax_id, 'party_branch_code' => $party->branch_code, 'party_address' => $party->address, 'updated_by' => $request->user()->id]);
            $document->lines()->delete();
            $document->lines()->createMany($this->lineRows($calculation['lines']));
            $audit->record('pos.sales_document.updated', $document, $before, $this->auditValues($document->fresh('lines')), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขเอกสารแล้ว']);
    }

    public function approve(ChangeSalesDocumentStatusRequest $request, SalesDocument $salesDocument, AuditLogger $audit, CreditLimitService $creditLimits, GlobalSettings $settings): JsonResponse
    {
        $this->transition($request, $salesDocument, $audit, 'approve', $creditLimits, $settings);

        return response()->json(['status' => true, 'msg' => 'อนุมัติเอกสารแล้ว']);
    }

    public function void(ChangeSalesDocumentStatusRequest $request, SalesDocument $salesDocument, AuditLogger $audit): JsonResponse
    {
        $this->transition($request, $salesDocument, $audit, 'void');

        return response()->json(['status' => true, 'msg' => 'ยกเลิกเอกสารแล้ว']);
    }

    public function post(PostSalesDocumentRequest $request, SalesDocument $salesDocument, SalesDocumentPostingService $posting): JsonResponse
    {
        $document = $posting->post(
            $this->scoped($request, $salesDocument),
            $request->validated('posting_date'),
            $this->scoped($request, $salesDocument)->warehouse,
            $request->user(),
            $request,
        );

        return response()->json(['status' => true, 'msg' => "Post {$document->document_number} แล้ว"]);
    }

    private function transition(ChangeSalesDocumentStatusRequest $request, SalesDocument $document, AuditLogger $audit, string $action, ?CreditLimitService $creditLimits = null, ?GlobalSettings $settings = null): void
    {
        DB::transaction(function () use ($request, $document, $audit, $action, $creditLimits, $settings) {
            $document = SalesDocument::query()->whereKey($this->scoped($request, $document)->id)->lockForUpdate()->firstOrFail();
            try {
                $status = SalesDocumentState::{$action}($document->status);
            } catch (DomainException $e) {
                throw ValidationException::withMessages(['status' => $e->getMessage()]);
            }
            if ($action === 'approve') {
                $document->load('lines');
                [, , $calculation] = $this->validatedDomain($request, [
                    ...$document->only(['document_type', 'source_invoice_id', 'party_id', 'payment_term_id', 'document_date', 'due_date', 'price_includes_vat']),
                    'withholding_tax_code_id' => $document->withholding_tax_code_id, 'withholding_base' => $document->withholding_base,
                    'document_date' => $document->document_date->format('Y-m-d'),
                    'due_date' => $document->due_date?->format('Y-m-d'),
                    'lines' => $document->lines->map->only(['description', 'quantity', 'unit', 'unit_price', 'discount_amount', 'revenue_account_id', 'tax_code_id', 'item_id', 'uom_id', 'stock_uom_id', 'uom_factor', 'conversion_snapshot', 'price_snapshot'])->all(),
                ], $document, (int) $document->tax_decimal_places);
                foreach (['subtotal', 'discount_amount', 'tax_base', 'tax_amount', 'total_amount', 'due_date', 'withholding_tax_code_id', 'withholding_base', 'withholding_rate', 'withholding_amount'] as $field) {
                    $stored = $field === 'due_date' ? $document->due_date?->format('Y-m-d') : $document->{$field};
                    if ($stored !== $calculation[$field]) {
                        throw ValidationException::withMessages(['lines' => 'ยอดหรือวันครบกำหนดไม่ตรงกับข้อมูลปัจจุบัน กรุณาบันทึกร่างใหม่']);
                    }
                }
                if ($document->document_type === 'INVOICE' && $creditLimits) {
                    $creditLimits->assertInvoiceWithinLimit((int) $document->party_id, (string) $calculation['total_amount']);
                }
                $discountApproval = SalesDiscountApproval::assess($calculation['lines'], (string) ($settings?->value('manual_discount_approval_threshold') ?? '0'));
                if ($discountApproval['requires_reason'] && mb_strlen(trim((string) $request->validated('reason'))) < 10) {
                    throw ValidationException::withMessages(['reason' => "ส่วนลดนอก Price List {$discountApproval['manual_discount_percent']}% เกินเพดาน {$discountApproval['threshold_percent']}% กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษรก่อนอนุมัติ"]);
                }
            }
            $before = $document->only(['status', 'approved_by', 'approved_at', 'approval_reason', 'voided_by', 'voided_at', 'void_reason']);
            $document->update($action === 'approve' ? ['status' => $status, 'approved_by' => $request->user()->id, 'approved_at' => now(), 'approval_reason' => $request->validated('reason'), 'discount_approval_snapshot' => $discountApproval] : ['status' => $status, 'voided_by' => $request->user()->id, 'voided_at' => now(), 'void_reason' => $request->validated('reason')]);
            $audit->record("pos.sales_document.{$action}d", $document, $before, $document->only(array_keys($before)), $request->user(), $request);
        });
    }

    private function validatedDomain(Request $request, array $values, ?SalesDocument $current = null, int $taxDecimals = 2): array
    {
        try {
            SalesDocumentPrecision::assertStorageCompatible($taxDecimals);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['tax_decimal_places' => $e->getMessage()]);
        }
        $values['lines'] = $this->normalizeInventoryLines($values['lines'] ?? [], $request, (string) $values['document_date']);
        $party = Party::query()->whereKey($values['party_id'])->where('is_active', true)->sharedLock()->first();
        $role = $party ? PartyRole::query()->where('party_id', $party->id)->where('role', 'CUSTOMER')->where('is_active', true)->sharedLock()->first() : null;
        if (! $role) {
            throw ValidationException::withMessages(['party_id' => 'ลูกค้าและบทบาทต้องเปิดใช้งาน']);
        }
        if ($current) {
            $existingLines = $current->relationLoaded('lines') ? $current->lines : $current->lines()->orderBy('line_number')->get();
            foreach ($values['lines'] as $index => &$line) {
                $snapshot = $existingLines->get($index)?->price_snapshot;
                if (is_array($snapshot) && (int) ($snapshot['item_id'] ?? 0) === (int) ($line['item_id'] ?? 0) && (int) ($snapshot['uom_id'] ?? 0) === (int) ($line['uom_id'] ?? 0)) {
                    $line['price_snapshot'] = $snapshot;
                } else {
                    unset($line['price_snapshot']);
                }
            }
            unset($line);
        }
        $values['lines'] = $this->applyPricingSnapshots($values['lines'], $party, (string) $values['document_type'], (string) $values['document_date'], (int) $request->attributes->get('selectedBranch')->id, $current !== null);
        $this->hydrateTaxRates($values);
        try {
            $calculation = SalesDocumentCalculator::calculate($values['lines'], (bool) ($values['price_includes_vat'] ?? false), $taxDecimals);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }
        $term = null;
        if (! empty($values['payment_term_id'])) {
            $term = PaymentTerm::query()->whereKey($values['payment_term_id'])->where('is_active', true)->sharedLock()->first();
            if (! $term) {
                throw ValidationException::withMessages(['payment_term_id' => 'เงื่อนไขการชำระเงินต้องเปิดใช้งาน']);
            }
        }
        if ($values['document_type'] === 'INVOICE' && ! $term) {
            throw ValidationException::withMessages(['payment_term_id' => 'Invoice ต้องมีเงื่อนไขการชำระเงิน']);
        }
        $calculation['due_date'] = $values['document_type'] === 'INVOICE'
            ? PaymentDueDate::calculate($values['document_date'], $term->due_rule, $term->credit_days)
            : null;
        $calculation = [...$calculation, ...$this->withholdingSnapshot($values, $calculation)];
        $accounts = Account::query()->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')->whereKey(collect($values['lines'])->pluck('revenue_account_id'))->where('account_types.code', 'REVENUE')->where('accounts.is_active', true)->where('accounts.is_postable', true)->whereNull('accounts.control_account_type')->sharedLock()->count();
        if ($accounts !== collect($values['lines'])->pluck('revenue_account_id')->unique()->count()) {
            throw ValidationException::withMessages(['lines' => 'บัญชีรายได้ต้องเปิดใช้งานและลงรายการได้']);
        }
        $taxes = TaxCode::query()->whereKey(collect($values['lines'])->pluck('tax_code_id'))->whereIn('kind', ['NONE_VAT', 'VAT_OUT'])->where('is_active', true)->sharedLock()->count();
        if ($taxes !== collect($values['lines'])->pluck('tax_code_id')->unique()->count()) {
            throw ValidationException::withMessages(['lines' => 'Tax Code ต้องเป็น NONE VAT หรือ VAT OUT และเปิดใช้งาน']);
        }
        $source = null;
        if ($values['document_type'] === 'CREDIT_NOTE') {
            $source = $this->sourceInvoice($request, (int) $values['source_invoice_id'], true);
            if ((int) $source->party_id !== (int) $party->id || $values['document_date'] < $source->document_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['source_invoice_id' => 'ใบลดหนี้ต้องตรงลูกค้า/สาขาและวันที่ไม่ก่อน Invoice']);
            }
            $used = SalesDocument::query()->where('source_invoice_id', $source->id)->where('document_type', 'CREDIT_NOTE')->where('status', '!=', 'VOID')->when($current, fn ($q) => $q->where('sales_documents.id', '!=', $current->id))->sum('total_amount');
            $creditTotal = BigDecimal::of((string) $used)->plus($calculation['total_amount'])->toScale(2, RoundingMode::HALF_UP);
            if ($creditTotal->isGreaterThan(BigDecimal::of((string) $source->total_amount))) {
                throw ValidationException::withMessages(['lines' => 'ยอดใบลดหนี้สะสมต้องไม่เกินยอด Invoice ต้นทาง']);
            }
        }

        return [$party, $source, $calculation];
    }

    private function sourceInvoice(Request $request, int $id, bool $lock = false): SalesDocument
    {
        $query = SalesDocument::query()->whereKey($id)->where('branch_id', $request->attributes->get('selectedBranch')->id)->where('document_type', 'INVOICE')->where('status', 'POSTED');

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function hydrateTaxRates(array &$values): void
    {
        $ids = collect($values['lines'] ?? [])->pluck('tax_code_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $codes = TaxCode::query()->whereKey($ids)->whereIn('kind', ['NONE_VAT', 'VAT_OUT'])->where('is_active', true)->sharedLock()->get()->keyBy('id');
        foreach ($values['lines'] ?? [] as &$line) {
            $line['tax_rate'] = $codes->get((int) ($line['tax_code_id'] ?? 0))?->rate ?? '0';
        }
        unset($line);
    }

    private function formView(SalesDocument $document, ?SalesDocument $source): View
    {
        $taxCodes = TaxCode::query()->whereIn('kind', ['NONE_VAT', 'VAT_OUT'])->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'kind', 'rate']);
        $paymentTerms = PaymentTerm::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        $accountIds = collect(old('lines', $document->exists ? $document->lines->toArray() : []))->pluck('revenue_account_id')->filter();
        $selectedAccounts = Account::query()->whereKey($accountIds)->get(['id', 'code', 'name'])->keyBy('id');
        $lineValues = collect(old('lines', $document->exists ? $document->lines->toArray() : []));
        $itemIds = $lineValues->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $uomIds = $lineValues->pluck('uom_id')->filter()->merge($lineValues->pluck('stock_uom_id')->filter())->map(fn ($id) => (int) $id)->unique();
        $selectedItems = Item::query()->whereKey($itemIds)->get(['id', 'code', 'name', 'base_uom_id'])->keyBy('id');
        $selectedUoms = Uom::query()->whereKey($uomIds)->get(['id', 'code', 'name'])->keyBy('id');
        $selectedParty = $document->party_id ? Party::query()->find($document->party_id) : null;
        $withholdingId = old('withholding_tax_code_id', $document->withholding_tax_code_id);
        $withholdingTaxCode = $withholdingId ? TaxCode::query()->whereKey($withholdingId)->where('kind', 'WHT')->first(['id', 'code', 'name', 'rate']) : null;

        return view('Pos::sales-documents.form', compact('document', 'source', 'taxCodes', 'paymentTerms', 'selectedAccounts', 'selectedParty', 'selectedItems', 'selectedUoms', 'withholdingTaxCode'));
    }

    private function withholdingSnapshot(array $values, array $calculation): array
    {
        $id = (int) ($values['withholding_tax_code_id'] ?? 0);
        $base = BigDecimal::of((string) ($values['withholding_base'] ?? '0') ?: '0');
        if ($values['document_type'] !== 'INVOICE') {
            if ($id || $base->isPositive()) {
                throw ValidationException::withMessages(['withholding_tax_code_id' => 'ใบลดหนี้ยังไม่รองรับการหัก ณ ที่จ่าย']);
            }

            return ['withholding_tax_code_id' => null, 'withholding_rate' => '0.0000', 'withholding_base' => '0.00', 'withholding_amount' => '0.00'];
        }
        if (! $id && $base->isPositive()) {
            throw ValidationException::withMessages(['withholding_tax_code_id' => 'ต้องเลือก Tax Code หัก ณ ที่จ่าย']);
        }
        if (! $id) {
            return ['withholding_tax_code_id' => null, 'withholding_rate' => '0.0000', 'withholding_base' => '0.00', 'withholding_amount' => '0.00'];
        }
        $tax = TaxCode::query()->whereKey($id)->where('kind', 'WHT')->where('is_active', true)->sharedLock()->first();
        if (! $tax) {
            throw ValidationException::withMessages(['withholding_tax_code_id' => 'Tax Code หัก ณ ที่จ่ายต้องเปิดใช้งาน']);
        }
        $limit = BigDecimal::of((string) $calculation['tax_base']);
        if ($base->isNegative() || $base->isGreaterThan($limit)) {
            throw ValidationException::withMessages(['withholding_base' => 'ฐานหัก ณ ที่จ่ายต้องไม่เกินฐานภาษีเอกสาร']);
        }
        $amount = $base->multipliedBy(BigDecimal::of((string) $tax->rate))->dividedBy(100, 2, RoundingMode::HALF_UP);

        return ['withholding_tax_code_id' => $tax->id, 'withholding_rate' => (string) $tax->rate, 'withholding_base' => $base->toScale(2, RoundingMode::HALF_UP)->__toString(), 'withholding_amount' => $amount->toScale(2, RoundingMode::HALF_UP)->__toString()];
    }

    private function lineRows(array $lines): array
    {
        return collect($lines)->values()->map(fn ($line, $i) => [...collect($line)->only(['description', 'quantity', 'unit', 'unit_price', 'discount_amount', 'revenue_account_id', 'tax_code_id', 'item_id', 'uom_id', 'stock_uom_id', 'uom_factor', 'conversion_snapshot', 'price_snapshot', 'tax_rate', 'tax_base', 'tax_amount', 'line_total'])->all(), 'line_number' => $i + 1])->all();
    }

    private function applyPricingSnapshots(array $lines, Party $party, string $documentType, string $documentDate, int $branchId, bool $preserveExistingSnapshots = false): array
    {
        $groupCode = $this->customerGroupCode($party);
        $resolver = app(PricingResolver::class);
        foreach ($lines as &$line) {
            $snapshot = $line['price_snapshot'] ?? null;
            if ($preserveExistingSnapshots && is_array($snapshot) && ($selected = $resolver->fromSnapshot($snapshot, (string) ($line['quantity'] ?? '0')))) {
                $line = [...$line, ...$selected];

                continue;
            }
            if ($documentType === 'INVOICE' && ! empty($line['item_id'])) {
                $selected = $resolver->resolve($branchId, (int) $line['item_id'], ! empty($line['uom_id']) ? (int) $line['uom_id'] : null, $groupCode, Carbon::parse($documentDate), (string) ($line['quantity'] ?? '0'), 'THB');
                if ($selected) {
                    $line = [...$line, ...$selected];

                    continue;
                }
            }
            $line['price_snapshot'] = null;
        }
        unset($line);

        return $lines;
    }

    private function customerGroupCode(Party $party): ?string
    {
        $companyId = CompanySetting::query()->orderByDesc('id')->value('id');

        return $party->customerGroups()
            ->where('pos_customer_groups.company_setting_id', $companyId)
            ->where('pos_customer_groups.is_active', true)
            ->orderBy('pos_customer_groups.code')
            ->value('pos_customer_groups.code');
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $page = max(1, $request->integer('page', 1));
        $rows = Item::query()->where('is_active', true)->where('is_stock_item', true)
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('code')->forPage($page, 31)->get(['id', 'code', 'name', 'base_uom_id']);

        return response()->json(['results' => $rows->take(30)->map(fn (Item $item) => ['id' => $item->id, 'text' => $item->code.' · '.$item->name, 'base_uom_id' => $item->base_uom_id])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function pricePreview(Request $request, PricingResolver $resolver): JsonResponse
    {
        $values = $request->validate([
            'party_id' => ['required', 'integer', 'min:1'],
            'item_id' => ['required', 'integer', 'min:1'],
            'uom_id' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'document_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $party = Party::query()->whereKey($values['party_id'])->where('is_active', true)->first();
        $role = $party ? PartyRole::query()->where('party_id', $party->id)->where('role', 'CUSTOMER')->where('is_active', true)->exists() : false;
        if (! $role) {
            throw ValidationException::withMessages(['party_id' => 'ลูกค้าและบทบาทต้องเปิดใช้งาน']);
        }
        $selected = $resolver->resolve((int) $request->attributes->get('selectedBranch')->id, (int) $values['item_id'], isset($values['uom_id']) ? (int) $values['uom_id'] : null, $this->customerGroupCode($party), Carbon::parse($values['document_date']), (string) $values['quantity'], 'THB');

        return response()->json([
            'price_snapshot' => $selected['price_snapshot'] ?? null,
            'unit_price' => $selected['unit_price'] ?? null,
            'discount_amount' => $selected['discount_amount'] ?? null,
        ]);
    }

    public function uomOptions(Request $request): JsonResponse
    {
        $item = Item::query()->where('is_active', true)->where('is_stock_item', true)->find($request->integer('item_id'));
        abort_unless($item, 404);
        $date = (string) $request->input('business_date', today()->toDateString());
        $base = Uom::query()->whereKey($item->base_uom_id)->where('is_active', true)->first();
        $rows = collect($base ? [['id' => $base->id, 'code' => $base->code, 'name' => $base->name, 'factor' => '1.00000000']] : []);
        $conversions = UomConversion::query()->with('fromUom:id,code,name')->where('to_uom_id', $item->base_uom_id)
            ->whereHas('fromUom', fn (Builder $query) => $query->where('is_active', true))
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
            })
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->get();
        foreach ($conversions as $conversion) {
            if ($conversion->fromUom) {
                $rows->push(['id' => $conversion->fromUom->id, 'code' => $conversion->fromUom->code, 'name' => $conversion->fromUom->name, 'factor' => (string) $conversion->factor]);
            }
        }
        $q = trim((string) $request->input('q', ''));
        $rows = $rows->unique('id')->when($q !== '', fn ($items) => $items->filter(fn ($row) => str_contains(strtolower($row['code'].' '.$row['name']), strtolower($q))))->values();

        return response()->json(['results' => $rows->map(fn ($row) => ['id' => $row['id'], 'text' => $row['code'].' · '.$row['name'], 'factor' => $row['factor'], 'stock_uom_id' => $item->base_uom_id])->values(), 'pagination' => ['more' => false]]);
    }

    private function normalizeInventoryLines(array $lines, Request $request, string $businessDate): array
    {
        $warehouse = $request->attributes->get('selectedWarehouse');

        return collect($lines)->values()->map(function (array $line) use ($warehouse, $businessDate): array {
            $itemId = $line['item_id'] ?? null;
            if ($itemId === null || $itemId === '') {
                foreach (['uom_id', 'stock_uom_id', 'uom_factor', 'conversion_snapshot'] as $field) {
                    if (($line[$field] ?? null) !== null && ($line[$field] ?? '') !== '') {
                        throw ValidationException::withMessages(['lines' => 'รายการบริการห้ามระบุหน่วยสินค้าคงคลัง']);
                    }
                }

                return [...$line, 'item_id' => null, 'uom_id' => null, 'stock_uom_id' => null, 'uom_factor' => null, 'conversion_snapshot' => null];
            }
            $item = Item::query()->whereKey((int) $itemId)->where('is_active', true)->where('is_stock_item', true)->lockForUpdate()->first();
            $uom = Uom::query()->whereKey((int) ($line['uom_id'] ?? 0))->where('is_active', true)->first();
            if (! $item || ! $uom || ! $item->base_uom_id) {
                throw ValidationException::withMessages(['lines' => 'สินค้าและหน่วยต้องเปิดใช้งานและมีหน่วย Stock']);
            }
            $factor = $uom->id === (int) $item->base_uom_id ? '1.00000000' : (string) (UomConversion::query()->where('from_uom_id', $uom->id)->where('to_uom_id', $item->base_uom_id)->where(function (Builder $query) use ($businessDate) {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $businessDate);
            })->where(function (Builder $query) use ($businessDate) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $businessDate);
            })->value('factor') ?? '0');
            if (BigDecimal::of($factor)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['lines' => 'ยังไม่มี Conversion หน่วยที่มีผลในวันที่เอกสาร']);
            }
            $snapshot = ['purchase_uom_id' => (int) $uom->id, 'stock_uom_id' => (int) $item->base_uom_id, 'factor' => $factor, 'business_date' => $businessDate];
            SalesInventoryPostingContract::preview([...$line, 'item_id' => $item->id, 'uom_id' => $uom->id, 'stock_uom_id' => $item->base_uom_id, 'uom_factor' => $factor, 'conversion_snapshot' => $snapshot, 'warehouse_id' => $warehouse->id]);

            return [...$line, 'item_id' => $item->id, 'uom_id' => $uom->id, 'stock_uom_id' => $item->base_uom_id, 'unit' => $uom->code, 'uom_factor' => $factor, 'conversion_snapshot' => $snapshot];
        })->all();
    }

    private function scoped(Request $request, SalesDocument $document): SalesDocument
    {
        abort_unless($document->branch_id === $request->attributes->get('selectedBranch')->id, 404);

        return $document;
    }

    private function auditValues(SalesDocument $document): array
    {
        return [...$document->only(['warehouse_id', 'document_type', 'document_number', 'source_invoice_id', 'party_id', 'payment_term_id', 'document_date', 'due_date', 'subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'withholding_tax_code_id', 'withholding_rate', 'withholding_base', 'withholding_amount', 'status', 'discount_approval_snapshot']), 'lines' => $document->lines->map->only(['line_number', 'description', 'quantity', 'unit', 'unit_price', 'discount_amount', 'revenue_account_id', 'tax_code_id', 'item_id', 'uom_id', 'stock_uom_id', 'uom_factor', 'conversion_snapshot', 'price_snapshot', 'line_total'])->all()];
    }

    private function documentsQuery(Request $request): Builder
    {
        $asOf = today()->format('Y-m-d');
        $allocations = DB::table('finance_allocations')
            ->selectRaw('debit_open_item_id, SUM(amount) AS allocated_amount')
            ->where('allocation_date', '<=', $asOf)
            ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))
            ->groupBy('debit_open_item_id');
        $advanceApplications = DB::table('finance_advance_deposit_applications')
            ->selectRaw('open_item_id, SUM(amount) AS applied_amount')
            ->where('application_date', '<=', $asOf)
            ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))
            ->groupBy('open_item_id');
        $payments = DB::table('finance_open_items as open_items')
            ->join('journal_entry_lines as open_item_lines', 'open_item_lines.id', '=', 'open_items.journal_entry_line_id')
            ->leftJoinSub($allocations, 'allocated', 'allocated.debit_open_item_id', '=', 'open_items.id')
            ->leftJoinSub($advanceApplications, 'advance_applications', 'advance_applications.open_item_id', '=', 'open_items.id')
            ->where('open_items.ledger_type', 'AR')
            ->where('open_items.party_type', 'CUSTOMER')
            ->where('open_items.balance_side', 'DEBIT')
            ->where('open_items.document_type', 'INVOICE')
            ->selectRaw('open_item_lines.journal_entry_id, open_items.original_amount AS payment_original, open_items.original_amount - COALESCE(allocated.allocated_amount, 0) - COALESCE(advance_applications.applied_amount, 0) AS payment_remaining');

        $query = SalesDocument::query()
            ->leftJoin('journal_entries', 'journal_entries.id', '=', 'sales_documents.journal_entry_id')
            ->leftJoinSub($payments, 'payments', 'payments.journal_entry_id', '=', 'sales_documents.journal_entry_id')
            ->where('sales_documents.branch_id', $request->attributes->get('selectedBranch')->id)
            ->select(['sales_documents.*', 'journal_entries.entry_number as journal_entry_number', 'payments.payment_original', 'payments.payment_remaining']);
        $paymentStatus = $request->input('payment_status');
        if (in_array($paymentStatus, ['UNPAID', 'PARTIAL', 'PAID'], true)) {
            $query->where('sales_documents.document_type', 'INVOICE')->where('sales_documents.status', 'POSTED');
            if ($paymentStatus === 'PAID') {
                $query->where('payments.payment_remaining', '=', 0);
            } elseif ($paymentStatus === 'UNPAID') {
                $query->whereColumn('payments.payment_remaining', 'payments.payment_original');
            } else {
                $query->where('payments.payment_remaining', '>', 0)->whereColumn('payments.payment_remaining', '<', 'payments.payment_original');
            }
        }

        return $query;
    }

    private function paymentStatus(SalesDocument $document): ?string
    {
        if ($document->document_type !== 'INVOICE' || $document->status !== 'POSTED') {
            return null;
        }
        if ($document->payment_original === null || $document->payment_remaining === null) {
            return 'CHECK';
        }
        $original = JournalBalance::decimal($document->payment_original);
        $remaining = JournalBalance::decimal($document->payment_remaining);

        return $remaining === '0.00' ? 'PAID' : ($remaining === $original ? 'UNPAID' : 'PARTIAL');
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $s = trim((string) $request->input('search.value', ''));
        if ($s !== '') {
            $query->where(fn ($q) => $q->where('sales_documents.document_number', 'like', "%{$s}%")->orWhere('sales_documents.party_code', 'like', "%{$s}%")->orWhere('sales_documents.party_name', 'like', "%{$s}%")->orWhere('sales_documents.status', 'like', "%{$s}%"));
        }
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'sales_documents.document_number', 1 => 'sales_documents.document_type', 2 => 'sales_documents.document_date', 3 => 'sales_documents.due_date', 4 => 'sales_documents.party_code', 5 => 'sales_documents.total_amount', 6 => 'payments.payment_remaining', 7 => 'payments.payment_remaining', 8 => 'journal_entries.entry_number', 9 => 'sales_documents.status'];
        $query->reorder($columns[(int) $request->input('order.0.column', 2)] ?? 'sales_documents.document_date', $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc')->orderByDesc('sales_documents.id');
    }
}
