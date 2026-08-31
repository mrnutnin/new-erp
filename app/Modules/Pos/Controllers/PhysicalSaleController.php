<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PartyRole;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesOrder;
use App\Modules\Pos\Models\SalesReturn;
use App\Modules\Pos\Requests\CancelFullPhysicalSaleRequest;
use App\Modules\Pos\Requests\PostPhysicalSaleRequest;
use App\Modules\Pos\Requests\SavePhysicalSaleRequest;
use App\Modules\Pos\Services\PhysicalSaleCancellationService;
use App\Modules\Pos\Services\PhysicalSalePostingService;
use App\Modules\Pos\Support\PhysicalSaleDueDate;
use App\Modules\Pos\Support\PhysicalSaleWithholdingSnapshot;
use App\Modules\Pos\Support\SalesDocumentCalculator;
use App\Modules\Pos\Support\SalesDocumentTrail;
use App\Modules\Settings\Services\GlobalSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class PhysicalSaleController extends Controller
{
    public function index(GlobalSettings $settings): View
    {
        return view('Pos::physical-sales.index', ['decimalPlaces' => (int) ($settings->value('tax_decimal_places') ?? 2)]);
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'document_type' => ['nullable', 'in:HS,IV'],
            'status' => ['nullable', 'in:DRAFT,POSTED,VOID'],
            'payment_status' => ['nullable', 'in:UNPAID,PARTIAL,PAID,CHECK'],
        ]);
        $branch = $request->attributes->get('selectedBranch');
        $canPrint = $request->user()->hasPermission('pos.physical-sales.print');
        $canPost = $request->user()->hasPermission('pos.physical-sales.post');
        $canVoid = $request->user()->hasPermission('pos.physical-sales.create');
        $canReceiveReceipt = $request->user()->hasPermission('pos.receipts.create');
        $canCancelFull = $request->user()->hasPermission('pos.physical-sales.cancel-full');
        $format = (string) ($settings->value('date_format') ?: 'd/m/Y');
        $asOf = today()->format('Y-m-d');
        $allocations = DB::table('finance_allocations')->selectRaw('debit_open_item_id, SUM(amount) AS allocated_amount')
            ->where('allocation_date', '<=', $asOf)->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))->groupBy('debit_open_item_id');
        $advanceApplications = DB::table('finance_advance_deposit_applications')->selectRaw('open_item_id, SUM(amount) AS applied_amount')
            ->where('application_date', '<=', $asOf)->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))->groupBy('open_item_id');
        $payments = DB::table('finance_open_items as open_items')
            ->join('journal_entry_lines as open_item_lines', 'open_item_lines.id', '=', 'open_items.journal_entry_line_id')
            ->leftJoinSub($allocations, 'allocated', 'allocated.debit_open_item_id', '=', 'open_items.id')
            ->leftJoinSub($advanceApplications, 'advance_applications', 'advance_applications.open_item_id', '=', 'open_items.id')
            ->where('open_items.ledger_type', 'AR')->where('open_items.party_type', 'CUSTOMER')->where('open_items.balance_side', 'DEBIT')->where('open_items.document_type', 'INVOICE')
            ->selectRaw('open_item_lines.journal_entry_id, open_items.id AS open_item_id, open_items.original_amount AS payment_original, open_items.original_amount - COALESCE(allocated.allocated_amount, 0) - COALESCE(advance_applications.applied_amount, 0) AS payment_remaining');
        $sales = PhysicalSale::query()->leftJoinSub($payments, 'payments', 'payments.journal_entry_id', '=', 'pos_physical_sales.journal_entry_id')
            ->where('pos_physical_sales.branch_id', $branch->id)
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('pos_physical_sales.document_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('pos_physical_sales.document_date', '<=', $date))
            ->when($filters['document_type'] ?? null, fn (Builder $query, string $type) => $query->where('pos_physical_sales.document_type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('pos_physical_sales.status', $status))
            ->when($filters['payment_status'] ?? null, function (Builder $query, string $status): void {
                $query->where('pos_physical_sales.status', 'POSTED');
                match ($status) {
                    'CHECK' => $query->where('pos_physical_sales.document_type', 'IV')->where(fn (Builder $q) => $q->whereNull('payments.payment_original')->orWhereNull('payments.payment_remaining')),
                    'PAID' => $query->where(fn (Builder $q) => $q->where('pos_physical_sales.document_type', 'HS')->orWhere(fn (Builder $iv) => $iv->whereNotNull('payments.payment_original')->where('payments.payment_remaining', 0))),
                    'UNPAID' => $query->where('pos_physical_sales.document_type', 'IV')->whereNotNull('payments.payment_original')->whereColumn('payments.payment_remaining', 'payments.payment_original'),
                    'PARTIAL' => $query->where('pos_physical_sales.document_type', 'IV')->whereNotNull('payments.payment_original')->where('payments.payment_remaining', '>', 0)->whereColumn('payments.payment_remaining', '!=', 'payments.payment_original'),
                };
            })
            ->select(['pos_physical_sales.*', 'payments.open_item_id', 'payments.payment_original', 'payments.payment_remaining']);

        return DataTables::eloquent($sales)
            ->order(fn (Builder $query) => $query->orderByDesc('pos_physical_sales.document_date')->orderByDesc('pos_physical_sales.id'))
            ->addColumn('type_label', fn (PhysicalSale $sale) => $sale->document_type === 'HS' ? 'ขายสด' : 'ขายเชื่อ')
            ->addColumn('document_date_label', fn (PhysicalSale $sale) => $sale->document_date?->format($format) ?: '—')
            ->addColumn('party_label', fn (PhysicalSale $sale) => $sale->party_code.' · '.$sale->party_name)
            ->addColumn('status_label', fn (PhysicalSale $sale) => ['DRAFT' => 'ร่าง', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก'][$sale->status] ?? $sale->status)
            ->addColumn('payment_status', fn (PhysicalSale $sale) => $this->paymentStatus($sale))
            ->addColumn('payment_status_label', fn (PhysicalSale $sale) => match ($this->paymentStatus($sale)) {
                'UNPAID' => 'ยังไม่ชำระ', 'PARTIAL' => 'ชำระบางส่วน', 'PAID' => 'ชำระครบ', 'CHECK' => 'ต้องตรวจสอบ AR', default => '—'
            })
            ->addColumn('show_url', fn (PhysicalSale $sale) => route('pos.physical-sales.show', $sale))
            ->addColumn('pdf_url', fn (PhysicalSale $sale) => $canPrint ? route('pos.physical-sales.pdf', $sale) : null)
            ->addColumn('post_detail_url', fn (PhysicalSale $sale) => $canPost && $sale->status === 'DRAFT' ? route('pos.physical-sales.show', $sale) : null)
            ->addColumn('void_detail_url', fn (PhysicalSale $sale) => $canVoid && $sale->status === 'DRAFT' ? route('pos.physical-sales.show', $sale) : null)
            ->addColumn('receive_receipt_url', fn (PhysicalSale $sale) => $canReceiveReceipt && $sale->document_type === 'IV' && $sale->status === 'POSTED' && in_array($this->paymentStatus($sale), ['UNPAID', 'PARTIAL'], true) ? route('pos.physical-sales.receive-payment.create', $sale) : null)
            ->addColumn('cancel_full_detail_url', fn (PhysicalSale $sale) => $canCancelFull && $sale->status === 'POSTED' ? route('pos.physical-sales.show', $sale) : null)
            ->toJson();
    }

    private function paymentStatus(PhysicalSale $sale): ?string
    {
        if ($sale->status !== 'POSTED') {
            return null;
        }
        if ($sale->document_type === 'HS' || JournalBalance::decimal($sale->total_amount) === '0.00') {
            return 'PAID';
        }
        if ($sale->payment_original === null || $sale->payment_remaining === null) {
            return 'CHECK';
        }
        $original = JournalBalance::decimal($sale->payment_original);
        $remaining = JournalBalance::decimal($sale->payment_remaining);

        return $remaining === '0.00' ? 'PAID' : ($remaining === $original ? 'UNPAID' : 'PARTIAL');
    }

    public function create(Request $request): View|RedirectResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $branch = $request->attributes->get('selectedBranch');
        $sourceOrderId = $request->integer('sales_order_id');
        if ($sourceOrderId) {
            $existing = PhysicalSale::query()->where('branch_id', $branch->id)
                ->where(['source_type' => 'SALES_ORDER', 'source_id' => $sourceOrderId])->where('status', '!=', 'VOID')->first();
            if ($existing) {
                return redirect()->route('pos.physical-sales.show', $existing)
                    ->with('warning', "ใบสั่งขายนี้สร้าง {$existing->document_number} แล้ว");
            }
        }
        $orders = SalesOrder::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'CONFIRMED')
            ->whereDoesntHave('physicalSales', fn (Builder $query) => $query->where('status', '!=', 'VOID'))
            ->when($sourceOrderId, fn (Builder $query) => $query->whereKey($sourceOrderId))
            ->with('party.customerRole.paymentTerm')
            ->latest('id')
            ->limit(100)
            ->get();
        abort_if($sourceOrderId && $orders->isEmpty(), 404);

        $sourceOrder = $orders->firstWhere('id', $sourceOrderId);
        $sourceOrder?->load(['lines', 'sourceIntake.preparedBy', 'quotation.sourceIntake.preparedBy', 'quotation.rfq.sourceIntake.preparedBy', 'rfq.sourceIntake.preparedBy']);
        $documentType = (float) ($sourceOrder?->party?->customerRole?->credit_limit ?? 0) > 0 ? 'IV' : 'HS';
        $documentDate = today();
        $term = $sourceOrder?->party?->customerRole?->paymentTerm;
        $dueDate = $documentType === 'HS'
            ? $documentDate
            : ($term?->is_active ? PhysicalSaleDueDate::resolve('IV', $documentDate->format('Y-m-d'), $term, null) : null);
        $sourceIntake = $sourceOrder?->sourceIntake ?? $sourceOrder?->quotation?->sourceIntake ?? $sourceOrder?->quotation?->rfq?->sourceIntake ?? $sourceOrder?->rfq?->sourceIntake;

        return view('Pos::physical-sales.form', ['sale' => new PhysicalSale(['document_type' => $documentType, 'source_type' => 'SALES_ORDER', 'document_date' => $documentDate, 'tax_treatment' => 'VAT_OUT', 'prices_include_vat' => true, 'due_date' => $dueDate]), 'orders' => $orders, 'sourceOrder' => $sourceOrder, 'sourceIntake' => $sourceIntake, 'sourceOrderId' => $sourceOrderId, 'fulfillmentWarehouseId' => $warehouse->id, 'fulfillmentWarehouses' => $request->user()->warehouses()->where('warehouses.branch_id', $branch->id)->where('warehouses.is_active', true)->orderBy('warehouses.code')->get(['warehouses.id', 'warehouses.code', 'warehouses.name']), 'vatTaxCodes' => TaxCode::query()->where('kind', 'VAT_OUT')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'rate']), 'whtTaxCodes' => TaxCode::query()->where('kind', 'WHT')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'rate'])]);
    }

    public function store(SavePhysicalSaleRequest $request, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $values = $request->validated();
        $branch = $request->attributes->get('selectedBranch');
        $sale = DB::transaction(function () use ($values, $request, $branch, $sequences, $audit) {
            $warehouse = $this->fulfillmentWarehouse($request, (int) $values['fulfillment_warehouse_id']);
            $source = $this->source($values['source_type'], (int) $values['source_id'], (int) $branch->id);
            if (! $source) {
                throw ValidationException::withMessages(['source_id' => 'ไม่พบเอกสารต้นทางในสาขาที่เลือก']);
            }
            if (PhysicalSale::query()->where(['source_type' => $values['source_type'], 'source_id' => $source->id])->where('status', '!=', 'VOID')->exists()) {
                throw ValidationException::withMessages(['source_id' => 'ใบสั่งขายนี้ถูกนำไปสร้าง HS/IV แล้ว ไม่สามารถสร้างซ้ำได้']);
            }
            $type = $values['document_type'] === 'HS' ? 'PHYSICAL_SALE_HS' : 'PHYSICAL_SALE_IV';
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => $type, 'is_active' => true])->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_type' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบขายสด/ขายเชื่อ']);
            }
            $dueDate = $this->dueDate($values, $source);
            $taxCode = $this->vatTaxCode($values);
            $draftLines = $source->lines->map(fn ($line): array => [
                'source' => $line, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount ?? '0.00', 'tax_code_id' => $taxCode?->id,
                'tax_rate' => $taxCode?->rate ?? '0.0000',
            ])->all();
            $calculation = SalesDocumentCalculator::calculate($draftLines, (bool) $values['prices_include_vat']);
            $withholding = $this->withholdingSnapshot($values, $calculation['tax_base']);
            $sale = PhysicalSale::query()->create([
                'warehouse_id' => $warehouse->id, 'document_type' => $values['document_type'], 'document_number' => $sequences->issueAvailableForBranch($sequence, $branch, Carbon::parse($values['document_date']), fn (string $number): bool => PhysicalSale::query()
                    ->where('document_type', $values['document_type'])->where('document_number', $number)->exists()),
                'source_type' => $values['source_type'], 'source_id' => $source->id, 'party_id' => $source->party_id, 'party_code' => $source->party_code, 'party_name' => $source->party_name,
                'party_tax_id' => $source->party_tax_id, 'party_branch_code' => $source->party_branch_code, 'party_address' => $source->party_address, 'document_date' => $values['document_date'], 'tax_treatment' => $values['tax_treatment'], 'prices_include_vat' => $values['prices_include_vat'], 'due_date' => $dueDate, 'posting_date' => $values['posting_date'] ?? null,
                'subtotal' => $calculation['subtotal'], 'discount_amount' => $calculation['discount_amount'],
                'promotion_snapshot' => $source->promotion_snapshot, 'promotion_discount_amount' => $source->promotion_discount_amount,
                'tax_base' => $calculation['tax_base'], 'tax_amount' => $calculation['tax_amount'], 'total_amount' => $calculation['total_amount'],
                ...$withholding,
                'description' => $values['description'] ?? null, 'status' => 'DRAFT', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            // Freeze the source quantities/prices with the selected VAT code and
            // calculation; source orders intentionally do not own tax snapshots.
            foreach ($calculation['lines'] as $index => $calculated) {
                $line = $draftLines[$index]['source'];
                $item = $line->item;
                $stockUom = $item?->base_uom_id;
                if (! $item || ! $stockUom || ! $line->uom_id) {
                    throw ValidationException::withMessages(['source_id' => 'ใบสั่งขายมีรายการสินค้าหรือหน่วยนับไม่ครบถ้วน']);
                }
                $sale->lines()->create([
                    'line_number' => $line->line_number,
                    'source_line_id' => $line->id,
                    'item_id' => $item->id,
                    'sale_uom_id' => $line->uom_id,
                    'stock_uom_id' => $stockUom,
                    'quantity' => $line->quantity,
                    'uom_factor' => 1,
                    'stock_quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_amount' => $calculated['discount_amount'], 'promotion_discount_amount' => $line->promotion_discount_amount, 'tax_code_id' => $calculated['tax_code_id'],
                    'tax_rate' => $calculated['tax_rate'], 'tax_base' => $calculated['tax_base'],
                    'tax_amount' => $calculated['tax_amount'], 'line_total' => $calculated['line_total'],
                    'pricing_snapshot' => $line->pricing_snapshot,
                    'item_snapshot' => $line->item_snapshot ?: ['code' => $item->code, 'name' => $item->name],
                    'conversion_snapshot' => ['factor' => 1, 'source' => 'sales_order'],
                ]);
            }
            $sequences->recordIssued($sequence, $sale->document_number, 'pos_physical_sales', $sale->id, Carbon::parse($sale->document_date), $request->user()->id);
            $audit->record('pos.physical-sale.created', $sale, [], $sale->fresh()->toArray(), $request->user(), $request);

            return $sale;
        });

        return response()->json(['status' => true, 'msg' => "สร้างร่าง {$sale->document_number} แล้ว", 'redirect' => route('pos.physical-sales.show', $sale)]);
    }

    public function show(Request $request, PhysicalSale $physicalSale, GlobalSettings $settings, OpenItemService $openItems): View
    {
        $this->ensureCurrentBranch($request, $physicalSale);
        $sale = $physicalSale->load(['warehouse', 'party', 'lines.item', 'lines.saleUom', 'lines.stockUom', 'tenders.bankAccount', 'advanceDepositApplications.advanceDeposit']);
        $source = $sale->source_type === 'SALES_ORDER'
            ? SalesOrder::query()->with(['sourceIntake.preparedBy', 'quotation.sourceIntake.preparedBy', 'quotation.rfq.sourceIntake.preparedBy', 'rfq.sourceIntake.preparedBy', 'physicalSales'])->whereKey($sale->source_id)->where('warehouse_id', $sale->warehouse_id)->first()
            : null;
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $sale->getMorphClass())->where('subject_id', $sale->id)->latest('created_at')->latest('id')->get();

        $sourceIntake = $source?->sourceIntake ?? $source?->quotation?->sourceIntake ?? $source?->quotation?->rfq?->sourceIntake ?? $source?->rfq?->sourceIntake;

        $flowDocuments = $source ? SalesDocumentTrail::for($source) : [];
        $flowDocuments[strtolower($sale->document_type)] = $sale;
        $paymentOpenItem = null;
        $saleOpenItem = null;
        $receipts = collect();
        $hasPostedReceipt = false;
        if ($sale->document_type === 'IV' && $sale->status === 'POSTED' && $sale->journal_entry_id) {
            $candidate = OpenItem::query()
                ->where('warehouse_id', $sale->warehouse_id)
                ->where('party_id', $sale->party_id)
                ->where('ledger_type', 'AR')
                ->where('party_type', 'CUSTOMER')
                ->where('balance_side', 'DEBIT')
                ->where('document_type', 'INVOICE')
                ->where('document_number', $sale->document_number)
                ->whereHas('journalEntryLine', fn (Builder $query) => $query->where('journal_entry_id', $sale->journal_entry_id))
                ->first();
            if ($candidate && $openItems->remainingAt($candidate, today()->format('Y-m-d')) !== '0.00') {
                $paymentOpenItem = $candidate;
            }
            if ($candidate) {
                $saleOpenItem = $candidate;
                $receipts = Settlement::query()->withTrashed()->where('document_type', 'RECEIPT')
                    ->whereHas('allocationIntents', fn (Builder $query) => $query->where('open_item_id', $candidate->id))
                    ->with(['allocationIntents', 'tenders.bankAccount'])->orderByDesc('settlement_date')->orderByDesc('id')->get();
                $hasPostedReceipt = $receipts->contains(fn (Settlement $receipt) => $receipt->status === 'POSTED');
            }
        }
        $flowDocuments['receipts'] = $receipts;
        $flowDocuments['sales_returns'] = SalesReturn::query()
            ->where('physical_sale_id', $sale->id)
            ->orderBy('document_date')
            ->orderBy('id')
            ->get();

        return view('Pos::physical-sales.show', ['sale' => $sale, 'source' => $source, 'sourceIntake' => $sourceIntake, 'flowDocuments' => $flowDocuments, 'history' => $history, 'paymentOpenItem' => $paymentOpenItem, 'saleOpenItem' => $saleOpenItem, 'receipts' => $receipts, 'hasPostedReceipt' => $hasPostedReceipt, 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'), 'whtTaxCodes' => TaxCode::query()->where('kind', 'WHT')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'rate'])]);
    }

    public function void(Request $request, PhysicalSale $physicalSale, AuditLogger $audit): JsonResponse
    {
        $this->ensureCurrentBranch($request, $physicalSale);
        $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        abort_unless($physicalSale->status === 'DRAFT', 422, 'ยกเลิกได้เฉพาะ HS/IV ร่าง');
        $before = $physicalSale->only(['status', 'void_reason']);
        $physicalSale->update(['status' => 'VOID', 'void_reason' => $request->string('reason'), 'voided_by' => $request->user()->id, 'voided_at' => now(), 'updated_by' => $request->user()->id]);
        $audit->record('pos.physical-sale.voided', $physicalSale, $before, $physicalSale->fresh()->only(['status', 'void_reason', 'voided_by', 'voided_at']), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => "ยกเลิก {$physicalSale->document_number} แล้ว"]);
    }

    public function cancelFull(CancelFullPhysicalSaleRequest $request, PhysicalSale $physicalSale, PhysicalSaleCancellationService $cancellation): JsonResponse
    {
        $this->ensureCurrentBranch($request, $physicalSale);
        $warehouse = $physicalSale->warehouse;

        $sale = $cancellation->cancel(
            $physicalSale,
            $warehouse,
            $request->validated('reversal_date'),
            $request->validated('reason'),
            $request->user(),
            $request,
        );

        return response()->json(['status' => true, 'msg' => "ยกเลิก {$sale->document_number} พร้อมคืนเงินและคืนสินค้าแล้ว", 'redirect' => route('pos.physical-sales.show', $sale)]);
    }

    public function post(PostPhysicalSaleRequest $request, PhysicalSale $physicalSale, PhysicalSalePostingService $posting): JsonResponse
    {
        $this->ensureCurrentBranch($request, $physicalSale);
        if ($physicalSale->status === 'POSTED') {
            return response()->json(['status' => true, 'msg' => "{$physicalSale->document_number} ถูกยืนยันขายแล้ว"]);
        }

        $warehouse = $physicalSale->warehouse;
        $sale = DB::transaction(function () use ($request, $physicalSale, $posting, $warehouse) {
            $draft = PhysicalSale::query()->lockForUpdate()->findOrFail($physicalSale->id);
            $tax = $request->filled('withholding_tax_code_id')
                ? TaxCode::query()->whereKey($request->integer('withholding_tax_code_id'))->where('kind', 'WHT')->where('is_active', true)->lockForUpdate()->first()
                : null;
            $draft->forceFill(PhysicalSaleWithholdingSnapshot::build($tax, $request->input('withholding_base', '0.00'), $draft->tax_base))->save();
            $sale = $posting->post($physicalSale, $request->validated('posting_date'), $warehouse, $request->user(), $request, $request->validated('tenders', []));

            return $sale;
        }, 3);

        return response()->json(['status' => true, 'msg' => "ยืนยันขาย {$sale->document_number}".($sale->document_type === 'HS' ? ' และรับชำระเงินแล้ว' : ' แล้ว')]);
    }

    public function pdf(Request $request, PhysicalSale $physicalSale, DocumentPdfRenderer $renderer, GlobalSettings $settings)
    {
        $this->ensureCurrentBranch($request, $physicalSale);
        $sale = $physicalSale->load(['warehouse', 'lines.item', 'lines.saleUom', 'party']);
        $source = $sale->source_type === 'SALES_ORDER'
            ? SalesOrder::query()->with(['lines.item', 'lines.uom', 'sourceIntake.preparedBy', 'quotation.sourceIntake.preparedBy', 'quotation.rfq.sourceIntake.preparedBy', 'rfq.sourceIntake.preparedBy'])->whereKey($sale->source_id)->where('warehouse_id', $sale->warehouse_id)->first()
            : null;
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $sale->getMorphClass())->where('subject_id', $sale->id)->latest('created_at')->latest('id')->get();
        $logoPath = $settings->value('logo_path');
        $logo = $logoPath && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->path($logoPath) : null;
        $bytes = $renderer->renderView('Pos::pdf.physical-sale', [
            'sale' => $sale,
            'source' => $source,
            'sourceIntake' => $source?->sourceIntake ?? $source?->quotation?->sourceIntake ?? $source?->quotation?->rfq?->sourceIntake ?? $source?->rfq?->sourceIntake,
            'sourceLabel' => $source?->document_number,
            'history' => $history,
            'logo' => $logo,
            'companyName' => $settings->value('company_name') ?: 'บริษัท',
            'companyAddress' => $settings->value('company_address'),
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
            'decimalPlaces' => (int) ($settings->value('tax_decimal_places') ?? 2),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($sale->document_number).'.pdf"',
        ]);
    }

    private function source(string $type, int $id, int $branchId): ?object
    {
        if ($type !== 'SALES_ORDER') {
            return null;
        }

        return SalesOrder::query()->with(['lines.item', 'lines.uom'])->whereKey($id)->where('branch_id', $branchId)->where('status', 'CONFIRMED')->first();
    }

    private function fulfillmentWarehouse(Request $request, int $warehouseId): Warehouse
    {
        $warehouse = $request->user()->warehouses()->whereKey($warehouseId)
            ->where('warehouses.branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('warehouses.is_active', true)->first();
        if (! $warehouse) {
            throw ValidationException::withMessages(['fulfillment_warehouse_id' => 'กรุณาเลือกคลังจัดส่งที่อยู่ในสาขาปัจจุบันและคุณมีสิทธิ์ใช้งาน']);
        }

        return $warehouse;
    }

    private function ensureCurrentBranch(Request $request, PhysicalSale $sale): void
    {
        abort_unless((int) $sale->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
    }

    private function dueDate(array $values, SalesOrder $source): string
    {
        $documentDate = (string) $values['document_date'];
        $explicitDueDate = $values['due_date'] ?? null;
        $term = PartyRole::query()
            ->where('party_id', $source->party_id)
            ->where('role', 'CUSTOMER')
            ->where('is_active', true)
            ->whereNotNull('payment_term_id')
            ->with('paymentTerm')
            ->sharedLock()
            ->first()?->paymentTerm;

        return PhysicalSaleDueDate::resolve((string) $values['document_type'], $documentDate, $term, $explicitDueDate);
    }

    private function withholdingSnapshot(array $values, string $maximumBase): array
    {
        $tax = empty($values['withholding_tax_code_id']) ? null : TaxCode::query()
            ->whereKey($values['withholding_tax_code_id'])->where('kind', 'WHT')->where('is_active', true)->sharedLock()->first();

        return PhysicalSaleWithholdingSnapshot::build(
            $tax,
            $values['withholding_base'] ?? '0.00',
            $maximumBase,
        );
    }

    private function vatTaxCode(array $values): ?TaxCode
    {
        if ($values['tax_treatment'] === 'NONE_VAT') {
            return null;
        }

        return TaxCode::query()->whereKey($values['tax_code_id'])->where('kind', 'VAT_OUT')->where('is_active', true)
            ->lockForUpdate()->firstOr(fn () => throw ValidationException::withMessages(['tax_code_id' => 'Tax Code ภาษีขายไม่พร้อมใช้งาน']));
    }
}
