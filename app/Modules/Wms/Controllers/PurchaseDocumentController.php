<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Finance\Support\PaymentDueDate;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\GoodsReceipt;
use App\Modules\Wms\Models\GoodsReceiptLine;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\PurchaseOrderLine;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Models\UomConversion;
use App\Modules\Wms\Requests\ChangePurchaseDocumentStatusRequest;
use App\Modules\Wms\Requests\PostPurchaseDocumentRequest;
use App\Modules\Wms\Requests\PurchaseVarianceDecisionRequest;
use App\Modules\Wms\Requests\SavePurchaseDocumentRequest;
use App\Modules\Wms\Services\CreditPurchaseInventoryReversalAdapter;
use App\Modules\Wms\Services\InventoryPurchaseLiveReversalAdapter;
use App\Modules\Wms\Services\InventoryPurchaseProductionAdapter;
use App\Modules\Wms\Services\PurchaseDocumentPostingService;
use App\Modules\Wms\Services\PurchaseVarianceApprovalService;
use App\Modules\Wms\Support\PurchaseDocumentCalculator;
use App\Modules\Wms\Support\PurchaseDocumentState;
use App\Modules\Wms\Support\PurchaseThreeWayMatchGate;
use App\Modules\Wms\Support\WmsDecimal;
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
use Yajra\DataTables\Facades\DataTables;

class PurchaseDocumentController extends Controller
{
    protected function modulePermission(string $permission): string
    {
        return $this->moduleRoutePrefix().'.'.$permission;
    }

    protected function moduleRoutePrefix(): string
    {
        return 'wms';
    }

    protected function moduleViewPrefix(): string
    {
        return 'Wms';
    }

    public function index(): View
    {
        $type = request()->query('document_type');

        return view($this->moduleViewPrefix().'::purchase-documents.index', [
            'documentType' => in_array($type, ['INVOICE', 'CREDIT_NOTE'], true) ? $type : null,
            'moduleRoutePrefix' => $this->moduleRoutePrefix(),
        ]);
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $dateFormat = (string) $settings->value('date_format');
        $dataTable = DataTables::eloquent($this->documentsQuery($request))
            ->filter(fn (Builder $query) => $this->applySearch($query, $request))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('document_date_iso', fn (PurchaseDocument $document) => $document->document_date->format('Y-m-d'))
            ->addColumn('document_date_label', fn (PurchaseDocument $document) => $document->document_date->format($dateFormat))
            ->addColumn('due_date_label', fn (PurchaseDocument $document) => $document->due_date?->format($dateFormat) ?? '—')
            ->addColumn('supplier_label', fn (PurchaseDocument $document) => $document->supplier_code.' · '.$document->supplier_name)
            ->addColumn('original_label', fn (PurchaseDocument $document) => $document->original_number ?? '—')
            ->addColumn('original_url', fn (PurchaseDocument $document) => $document->original_document_id && $request->user()->hasPermission($this->modulePermission('purchase-documents.view'))
                ? route($this->moduleRoutePrefix().'.purchase-documents.show', $document->original_document_id)
                : null)
            ->addColumn('status_label', fn (PurchaseDocument $document) => [
                'DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก',
            ][$document->status])
            ->addColumn('show_url', fn (PurchaseDocument $document) => route($this->moduleRoutePrefix().'.purchase-documents.show', $document))
            ->addColumn('print_url', fn (PurchaseDocument $document) => $request->user()->hasPermission($this->modulePermission('purchase-documents.print')) ? route($this->moduleRoutePrefix().'.purchase-documents.pdf', $document) : null);

        if ($request->user()->hasPermission($this->modulePermission('purchase-documents.update'))) {
            $dataTable->addColumn('edit_url', fn (PurchaseDocument $document) => $document->status === 'DRAFT' ? route($this->moduleRoutePrefix().'.purchase-documents.edit', $document) : null);
        }
        if ($request->user()->hasPermission($this->modulePermission('purchase-documents.delete'))) {
            $dataTable->addColumn('delete_url', fn (PurchaseDocument $document) => $document->status === 'DRAFT' ? route($this->moduleRoutePrefix().'.purchase-documents.destroy', $document) : null);
        }
        if ($request->user()->hasPermission($this->modulePermission('purchase-documents.approve'))) {
            $dataTable->addColumn('approve_url', fn (PurchaseDocument $document) => $document->status === 'DRAFT' ? route($this->moduleRoutePrefix().'.purchase-documents.approve', $document) : null);
        }
        if ($request->user()->hasPermission($this->modulePermission('purchase-documents.void'))) {
            $dataTable->addColumn('void_url', fn (PurchaseDocument $document) => in_array($document->status, ['DRAFT', 'APPROVED'], true) ? route($this->moduleRoutePrefix().'.purchase-documents.void', $document) : null);
        }
        if ($request->user()->hasPermission($this->modulePermission('purchase-documents.post'))) {
            $dataTable->addColumn('post_url', fn (PurchaseDocument $document) => $document->status === 'APPROVED' && ! $this->isInventoryPurchase($document) ? route($this->moduleRoutePrefix().'.purchase-documents.post', $document) : null);
        }
        if (config('erp.inventory.purchase_posting_enabled') && $request->user()->hasPermission($this->modulePermission('purchase-documents.inventory-post'))) {
            $dataTable->addColumn('inventory_post_url', fn (PurchaseDocument $document) => $document->status === 'APPROVED'
                && $document->document_type === 'INVOICE' && $document->tax_treatment === 'NONE_VAT'
                ? route($this->moduleRoutePrefix().'.purchase-documents.inventory-post', $document) : null);
        }
        if (config('erp.inventory.purchase_posting_enabled') && $request->user()->hasPermission($this->modulePermission('purchase-documents.inventory-reverse'))) {
            $dataTable->addColumn('inventory_reverse_url', fn (PurchaseDocument $document) => $document->status === 'POSTED' && $document->reversal_status !== 'REVERSED' ? route($this->moduleRoutePrefix().'.purchase-documents.inventory-reverse', $document) : null);
        }

        return $dataTable->toJson();
    }

    public function supplierOptions(Request $request): JsonResponse
    {
        $values = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $search = trim((string) ($values['q'] ?? ''));
        $page = (int) ($values['page'] ?? 1);
        $suppliers = Party::query()
            ->join('party_roles', fn ($join) => $join->on('party_roles.party_id', '=', 'parties.id')->where('party_roles.role', 'SUPPLIER')->where('party_roles.is_active', true))
            ->where('parties.is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('parties.code', 'like', "%{$search}%")->orWhere('parties.name', 'like', "%{$search}%")
                ->orWhere('parties.tax_id', 'like', "%{$search}%")->orWhere('parties.phone', 'like', "%{$search}%")))
            ->orderBy('parties.code')->forPage($page, 31)->get(['parties.id', 'parties.code', 'parties.name']);

        return response()->json([
            'results' => $suppliers->take(30)->map(fn (Party $party) => ['id' => $party->id, 'text' => $party->code.' · '.$party->name])->values(),
            'pagination' => ['more' => $suppliers->count() > 30],
        ]);
    }

    public function accountOptions(Request $request): JsonResponse
    {
        $values = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $search = trim((string) ($values['q'] ?? ''));
        $page = (int) ($values['page'] ?? 1);
        $accounts = Account::query()->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->whereIn('account_types.code', ['ASSET', 'EXPENSE'])->whereNull('accounts.control_account_type')
            ->where('accounts.is_active', true)->where('accounts.is_postable', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('accounts.code', 'like', "%{$search}%")->orWhere('accounts.name', 'like', "%{$search}%")))
            ->orderBy('accounts.code')->forPage($page, 31)->get(['accounts.id', 'accounts.code', 'accounts.name']);

        return response()->json([
            'results' => $accounts->take(30)->map(fn (Account $account) => ['id' => $account->id, 'text' => $account->code.' · '.$account->name])->values(),
            'pagination' => ['more' => $accounts->count() > 30],
        ]);
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $values = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'item_type' => ['nullable', 'in:GOODS,SERVICE']]);
        $search = trim((string) ($values['q'] ?? ''));
        $page = (int) ($values['page'] ?? 1);
        $items = Item::query()->where('is_active', true)->where('item_type', $values['item_type'] ?? 'GOODS')->when(($values['item_type'] ?? 'GOODS') === 'GOODS', fn (Builder $query) => $query->where('is_stock_item', true))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')->forPage($page, 31)->get(['id', 'code', 'name']);

        return response()->json(['results' => $items->take(30)->map(fn (Item $item) => ['id' => $item->id, 'text' => $item->code.' · '.$item->name])->values(), 'pagination' => ['more' => $items->count() > 30]]);
    }

    public function uomOptions(Request $request): JsonResponse
    {
        $values = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'item_id' => ['nullable', 'integer', 'min:1']]);
        $search = trim((string) ($values['q'] ?? ''));
        $page = (int) ($values['page'] ?? 1);
        $uoms = Uom::query()->where('is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')->forPage($page, 31)->get(['id', 'code', 'name']);

        return response()->json(['results' => $uoms->take(30)->map(fn (Uom $uom) => ['id' => $uom->id, 'text' => $uom->code.' · '.$uom->name])->values(), 'pagination' => ['more' => $uoms->count() > 30]]);
    }

    public function taxCodeOptions(Request $request): JsonResponse
    {
        $values = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $search = trim((string) ($values['q'] ?? ''));
        $page = max(1, (int) ($values['page'] ?? 1));
        $codes = TaxCode::query()->where('kind', 'VAT_IN')->where('is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')->forPage($page, 31)->get(['id', 'code', 'name', 'rate']);

        return response()->json(['results' => $codes->take(30)->map(fn (TaxCode $code) => ['id' => $code->id, 'text' => "{$code->code} · {$code->name} ({$code->rate}%)", 'rate' => $code->rate])->values(), 'pagination' => ['more' => $codes->count() > 30]]);
    }

    public function withholdingTaxCodeOptions(Request $request): JsonResponse
    {
        $values = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $search = trim((string) ($values['q'] ?? ''));
        $page = max(1, (int) ($values['page'] ?? 1));
        $codes = TaxCode::query()->where('kind', 'WHT')->where('is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')->forPage($page, 31)->get(['id', 'code', 'name', 'rate']);

        return response()->json(['results' => $codes->take(30)->map(fn (TaxCode $code) => ['id' => $code->id, 'text' => "{$code->code} · {$code->name} ({$code->rate}%)", 'rate' => $code->rate])->values(), 'pagination' => ['more' => $codes->count() > 30]]);
    }

    public function originalOptions(Request $request): JsonResponse
    {
        $values = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'supplier_id' => ['required', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($values['q'] ?? ''));
        $page = (int) ($values['page'] ?? 1);
        $documents = PurchaseDocument::query()
            ->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)
            ->where('supplier_id', $values['supplier_id'])->where('document_type', 'INVOICE')->where('status', 'POSTED')
            ->when($search !== '', fn (Builder $query) => $query->where('document_number', 'like', "%{$search}%"))
            ->orderByDesc('document_date')->orderByDesc('id')->forPage($page, 31)->get(['id', 'document_number', 'gross_amount']);

        return response()->json([
            'results' => $documents->take(30)->map(fn (PurchaseDocument $document) => [
                'id' => $document->id, 'text' => $document->document_number.' · '.WmsDecimal::format($document->gross_amount),
            ])->values(),
            'pagination' => ['more' => $documents->count() > 30],
        ]);
    }

    public function purchaseOrderLineOptions(Request $request): JsonResponse
    {
        $values = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'supplier_id' => ['required', 'integer', 'min:1'], 'item_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($values['q'] ?? ''));
        $page = (int) ($values['page'] ?? 1);
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $lines = PurchaseOrderLine::query()->with(['purchaseOrder:id,document_number,supplier_id,warehouse_id,status', 'item:id,code,name', 'uom:id,code,name'])
            ->whereHas('purchaseOrder', fn (Builder $q) => $q->where('warehouse_id', $warehouseId)->where('supplier_id', $values['supplier_id'])->where('status', 'APPROVED'))
            ->when(! empty($values['item_id']), fn (Builder $q) => $q->where('item_id', $values['item_id']))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('description', 'like', "%{$search}%")->orWhereHas('purchaseOrder', fn (Builder $q) => $q->where('document_number', 'like', "%{$search}%"))))
            ->orderByDesc('id')->forPage($page, 31)->get();

        return response()->json([
            'results' => $lines->take(30)->map(fn (PurchaseOrderLine $line) => [
                'id' => $line->id,
                'text' => $line->purchaseOrder->document_number.' · #'.$line->line_number.' · '.($line->item?->code ?? $line->description),
                'ordered_quantity' => (string) $line->quantity, 'unit_price' => (string) $line->unit_price,
                'item_id' => $line->item_id, 'uom_id' => $line->uom_id,
            ])->values(),
            'pagination' => ['more' => $lines->count() > 30],
        ]);
    }

    public function goodsReceiptLineOptions(Request $request): JsonResponse
    {
        $values = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'purchase_order_line_id' => ['required', 'integer', 'min:1'], 'supplier_id' => ['required', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($values['q'] ?? ''));
        $page = (int) ($values['page'] ?? 1);
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $lines = GoodsReceiptLine::query()->with(['goodsReceipt:id,receipt_number,warehouse_id,status', 'item:id,code,name', 'purchaseUom:id,code,name'])
            ->where('purchase_order_line_id', $values['purchase_order_line_id'])
            ->whereHas('goodsReceipt', fn (Builder $q) => $q->where('warehouse_id', $warehouseId)->where('supplier_id', $values['supplier_id'])->where('status', 'APPROVED'))
            ->when($search !== '', fn (Builder $q) => $q->whereHas('goodsReceipt', fn (Builder $q) => $q->where('receipt_number', 'like', "%{$search}%")))
            ->orderByDesc('id')->forPage($page, 31)->get();

        return response()->json([
            'results' => $lines->take(30)->map(fn (GoodsReceiptLine $line) => [
                'id' => $line->id,
                'text' => $line->goodsReceipt->receipt_number.' · '.($line->item?->code ?? 'สินค้า').' · รับ '.WmsDecimal::format($line->purchase_quantity),
                'received_quantity' => (string) $line->purchase_quantity, 'total_cost' => (string) $line->total_cost,
            ])->values(),
            'pagination' => ['more' => $lines->count() > 30],
        ]);
    }

    public function goodsReceiptOptions(Request $request): JsonResponse
    {
        $values = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'supplier_id' => ['required', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($values['q'] ?? ''));
        $page = (int) ($values['page'] ?? 1);
        $receipts = GoodsReceipt::query()
            ->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)
            ->where('supplier_id', $values['supplier_id'])->where('status', 'APPROVED')
            ->when($search !== '', fn (Builder $query) => $query->where('receipt_number', 'like', "%{$search}%"))
            ->orderByDesc('business_date')->orderByDesc('id')->forPage($page, 31)->get(['id', 'receipt_number', 'business_date', 'purchase_order_id']);

        return response()->json([
            'results' => $receipts->take(30)->map(fn (GoodsReceipt $receipt) => [
                'id' => $receipt->id,
                'text' => $receipt->receipt_number.' · '.optional($receipt->business_date)->format('d/m/Y'),
                'purchase_order_id' => $receipt->purchase_order_id,
            ])->values(),
            'pagination' => ['more' => $receipts->count() > 30],
        ]);
    }

    public function goodsReceiptLines(Request $request): JsonResponse
    {
        $values = $request->validate(['goods_receipt_id' => ['required', 'integer', 'min:1'], 'supplier_id' => ['required', 'integer', 'min:1']]);
        $lines = GoodsReceiptLine::query()->with(['item:id,code,name,inventory_account_id', 'item.inventoryAccount:id,code,name', 'purchaseUom:id,code,name', 'purchaseOrderLine:id,unit_price,description,purchase_order_id', 'purchaseOrderLine.purchaseOrder:id,document_number'])
            ->where('goods_receipt_id', $values['goods_receipt_id'])
            ->whereHas('goodsReceipt', fn (Builder $query) => $query->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->where('supplier_id', $values['supplier_id'])->where('status', 'APPROVED'))
            ->orderBy('id')->get();

        return response()->json(['lines' => $lines->map(fn (GoodsReceiptLine $line) => [
            'receipt_line_id' => $line->id,
            'purchase_order_line_id' => $line->purchase_order_line_id,
            'item_id' => $line->item_id,
            'item_text' => $line->item?->code.' · '.$line->item?->name,
            'uom_id' => $line->purchase_uom_id,
            'uom_text' => $line->purchaseUom?->code.' · '.$line->purchaseUom?->name,
            'account_id' => $line->item?->inventory_account_id,
            'account_text' => $line->item?->inventoryAccount?->code.' · '.$line->item?->inventoryAccount?->name,
            'quantity' => (string) $line->purchase_quantity,
            'unit_price' => (string) ($line->purchaseOrderLine?->unit_price ?? '0.0000'),
            'description' => $line->purchaseOrderLine?->description ?: ($line->item?->name ?: 'สินค้า'),
        ])->values()]);
    }

    public function create(Request $request): View
    {
        $type = strtoupper((string) $request->query('document_type', 'INVOICE'));
        $type = in_array($type, ['INVOICE', 'CREDIT_NOTE'], true) ? $type : 'INVOICE';
        $data = $this->formData(new PurchaseDocument([
            'warehouse_id' => $request->attributes->get('selectedWarehouse')->id,
            'document_type' => $type, 'document_date' => today(), 'tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false, 'status' => 'DRAFT',
        ]));
        $data['documentTypeLocked'] = true;

        $data['moduleRoutePrefix'] = $this->moduleRoutePrefix();

        return view($this->moduleViewPrefix().'::purchase-documents.form', $data);
    }

    public function store(SavePurchaseDocumentRequest $request, DocumentSequenceService $sequences, GlobalSettings $settings, AuditLogger $audit): JsonResponse
    {
        $values = $request->validated();
        $values['prices_include_vat'] = (bool) $values['prices_include_vat'];
        $this->hydrateTaxRates($values);
        $decimalPlaces = (int) $settings->value('tax_decimal_places');
        $calculation = PurchaseDocumentCalculator::calculate($values['lines'], $values['tax_treatment'], $values['prices_include_vat'], $decimalPlaces, $decimalPlaces);
        $calculation = [...$calculation, ...$this->withholdingSnapshot($values, $calculation)];
        $warehouse = $request->attributes->get('selectedWarehouse');
        $warehouse->loadMissing('branch');
        if (! $warehouse->branch) {
            throw ValidationException::withMessages(['warehouse_id' => 'คลังที่เลือกไม่มีสาขา']);
        }
        $document = DB::transaction(function () use ($request, $values, $calculation, $warehouse, $sequences, $settings, $audit) {
            $sequenceType = $values['document_type'] === 'INVOICE' ? 'PURCHASE_INVOICE' : 'PURCHASE_CREDIT_NOTE';
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', $sequenceType)->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_type' => 'ยังไม่ได้ตั้งค่าเลขเอกสารประเภทนี้']);
            }
            $number = $sequences->issueForBranch($sequence, $warehouse->branch, Carbon::parse($values['document_date']));
            [$supplier, $term] = $this->assertReferences($values, (int) $warehouse->id);
            $this->assertOriginalCredit($values, $calculation['gross_amount'], $warehouse->id);
            $this->assertCreditLineCaps($values, (int) $warehouse->id);
            $document = PurchaseDocument::query()->create([
                ...$this->headerValues($values, $calculation, $term, (int) $settings->value('tax_decimal_places')),
                'warehouse_id' => $warehouse->id, 'document_number' => $number, 'supplier_id' => $supplier->id,
                ...$this->supplierSnapshot($supplier),
                'status' => 'DRAFT', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $this->replaceLines($document, $calculation['lines']);
            $sequences->recordIssued($sequence, $document->document_number, 'purchase_documents', (int) $document->id, Carbon::parse($document->document_date), $request->user()->id);
            $audit->record('wms.purchase_document.created', $document, [], $this->auditValues($document), $request->user(), $request);

            return $document;
        }, 3);

        return response()->json(['status' => true, 'msg' => "สร้างร่าง {$document->document_number} แล้ว", 'redirect' => route('wms.purchase-documents.show', $document)]);
    }

    public function show(Request $request, PurchaseDocument $purchaseDocument, GlobalSettings $settings, PurchaseThreeWayMatchGate $matchGate): View
    {
        $purchaseDocument = $this->scoped($request, $purchaseDocument)->load([
            'supplier', 'paymentTerm', 'originalDocument', 'lines.account', 'lines.item', 'lines.uom',
            'lines.purchaseOrderLine.purchaseOrder.purchaseRequisition',
            'lines.receiptAllocations.goodsReceiptLine.goodsReceipt',
            'varianceApprovals.actor',
        ]);
        $sourceLines = $purchaseDocument->lines->flatMap(fn ($line) => $line->receiptAllocations->map(fn ($allocation) => $allocation->goodsReceiptLine))->filter();
        $history = AuditLog::query()->with('user')->where('subject_type', $purchaseDocument->getMorphClass())->where('subject_id', $purchaseDocument->id)->latest('created_at')->latest('id')->get();

        return view($this->moduleViewPrefix().'::purchase-documents.show', [
            'document' => $purchaseDocument,
            'dateFormat' => (string) $settings->value('date_format'),
            'threeWayMatch' => $matchGate->preview($purchaseDocument),
            'varianceApproval' => $purchaseDocument->varianceApprovals->first(),
            'history' => $history,
            'referencePrs' => $purchaseDocument->lines->map(fn ($line) => $line->purchaseOrderLine?->purchaseOrder?->purchaseRequisition)->filter()->unique('id'),
            'referencePos' => $purchaseDocument->lines->map(fn ($line) => $line->purchaseOrderLine?->purchaseOrder)->filter()->unique('id'),
            'referenceGrs' => $sourceLines->map(fn ($line) => $line->goodsReceipt)->filter()->unique('id'),
            'moduleRoutePrefix' => $this->moduleRoutePrefix(),
        ]);
    }

    public function destroy(Request $request, PurchaseDocument $purchaseDocument, AuditLogger $audit): JsonResponse
    {
        $document = $this->scoped($request, $purchaseDocument);
        if ($document->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะเอกสารที่ยังเป็นร่างก่อนอนุมัติ']);
        }
        DB::transaction(function () use ($request, $document, $audit): void {
            $before = $this->auditValues($document->load('lines'));
            $audit->record('wms.purchase_document.deleted', $document, $before, [], $request->user(), $request);
            $document->delete();
        });

        return response()->json(['status' => true, 'msg' => 'ลบร่างเอกสารซื้อแล้ว']);
    }

    public function threeWayMatch(Request $request, PurchaseDocument $purchaseDocument, PurchaseThreeWayMatchGate $matchGate): JsonResponse
    {
        return response()->json(['status' => true, 'match' => $matchGate->preview($this->scoped($request, $purchaseDocument))]);
    }

    public function edit(Request $request, PurchaseDocument $purchaseDocument): View
    {
        $purchaseDocument = $this->moduleRoutePrefix() === 'purchasing' ? $this->scoped($request, $purchaseDocument) : $purchaseDocument;
        abort_unless($purchaseDocument->status === 'DRAFT', 404);

        $data = $this->formData($purchaseDocument);
        $data['moduleRoutePrefix'] = $this->moduleRoutePrefix();

        return view($this->moduleViewPrefix().'::purchase-documents.form', $data);
    }

    public function update(SavePurchaseDocumentRequest $request, PurchaseDocument $purchaseDocument, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse
    {
        $purchaseDocument = $this->moduleRoutePrefix() === 'purchasing' ? $this->scoped($request, $purchaseDocument) : $purchaseDocument;
        $values = $request->validated();
        $values['prices_include_vat'] = (bool) $values['prices_include_vat'];
        $this->hydrateTaxRates($values);
        $calculation = PurchaseDocumentCalculator::calculate($values['lines'], $values['tax_treatment'], $values['prices_include_vat'], (int) $purchaseDocument->tax_decimal_places, (int) $purchaseDocument->tax_decimal_places);
        $calculation = [...$calculation, ...$this->withholdingSnapshot($values, $calculation)];
        DB::transaction(function () use ($request, $purchaseDocument, $values, $calculation, $audit, $sequences) {
            $document = PurchaseDocument::query()->lockForUpdate()->findOrFail($purchaseDocument->id);
            if ($document->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'แก้ไขได้เฉพาะเอกสาร Draft']);
            }
            if ($document->document_type !== $values['document_type']) {
                throw ValidationException::withMessages(['document_type' => 'เอกสารที่ออกเลขแล้วเปลี่ยนประเภทไม่ได้']);
            }
            [$supplier, $term] = $this->assertReferences($values, (int) $purchaseDocument->warehouse_id);
            $this->assertOriginalCredit($values, $calculation['gross_amount'], $document->warehouse_id, $document->id);
            $this->assertCreditLineCaps($values, (int) $document->warehouse_id, $document->id);
            $before = $this->auditValues($document);
            $newNumber = $document->document_number;
            if ($document->document_date->toDateString() !== $values['document_date']) {
                $sequenceType = $document->document_type === 'INVOICE' ? 'PURCHASE_INVOICE' : 'PURCHASE_CREDIT_NOTE';
                $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', $sequenceType)->where('is_active', true)->lockForUpdate()->first();
                if (! $sequence) {
                    throw ValidationException::withMessages(['document_date' => 'ยังไม่ได้ตั้งค่าเลขเอกสารสำหรับวันที่ใหม่']);
                }
                $warehouse = Warehouse::query()->with('branch')->findOrFail($document->warehouse_id);
                if (! $warehouse->branch) {
                    throw ValidationException::withMessages(['warehouse_id' => 'คลังของเอกสารไม่มีสาขา']);
                }
                $newNumber = $sequences->replaceDraftNumberForBranch($sequence, $warehouse->branch, $document->document_number, 'purchase_documents', (int) $document->id, Carbon::parse($values['document_date']), $request->user()->id);
            }
            $document->update([
                ...$this->headerValues($values, $calculation, $term, $document->tax_decimal_places),
                'document_number' => $newNumber, 'supplier_id' => (int) $values['supplier_id'], ...$this->supplierSnapshot($supplier),
                'updated_by' => $request->user()->id,
            ]);
            $this->replaceLines($document, $calculation['lines']);
            $audit->record('wms.purchase_document.updated', $document, $before, $this->auditValues($document), $request->user(), $request);
        }, 3);

        return response()->json(['status' => true, 'msg' => 'แก้ไขร่างเอกสารแล้ว', 'redirect' => route('wms.purchase-documents.show', $purchaseDocument)]);
    }

    public function approve(ChangePurchaseDocumentStatusRequest $request, PurchaseDocument $purchaseDocument, AuditLogger $audit, PurchaseThreeWayMatchGate $matchGate): JsonResponse
    {
        $this->transition($request, $purchaseDocument, $audit, 'approve', $matchGate);

        return response()->json(['status' => true, 'msg' => "อนุมัติ {$purchaseDocument->document_number} แล้ว"]);
    }

    public function varianceApprove(PurchaseVarianceDecisionRequest $request, PurchaseDocument $purchaseDocument, PurchaseVarianceApprovalService $service, AuditLogger $audit): JsonResponse
    {
        $purchaseDocument = $this->moduleRoutePrefix() === 'purchasing' ? $this->scoped($request, $purchaseDocument) : $purchaseDocument;
        $approval = $service->approve($purchaseDocument, $request->user(), $request->validated('reason'), $audit, $request);

        return response()->json(['message' => 'บันทึกการอนุมัติผลต่างแล้ว', 'approval_id' => $approval->id, 'status' => $approval->status]);
    }

    public function varianceReject(PurchaseVarianceDecisionRequest $request, PurchaseDocument $purchaseDocument, PurchaseVarianceApprovalService $service, AuditLogger $audit): JsonResponse
    {
        $purchaseDocument = $this->scoped($request, $purchaseDocument);
        $approval = $service->reject($purchaseDocument, $request->user(), $request->validated('reason'), $audit, $request);

        return response()->json(['message' => 'บันทึกการไม่อนุมัติผลต่างแล้ว', 'approval_id' => $approval->id, 'status' => $approval->status]);
    }

    public function varianceRecover(PurchaseVarianceDecisionRequest $request, PurchaseDocument $purchaseDocument, PurchaseVarianceApprovalService $service, AuditLogger $audit): JsonResponse
    {
        $purchaseDocument = $this->scoped($request, $purchaseDocument);
        $approval = $service->recover($purchaseDocument, $request->user(), $request->validated('reason'), $audit, $request);

        return response()->json(['message' => 'เปิดให้แก้ไขและตรวจผลต่างใหม่แล้ว', 'approval_id' => $approval->id, 'status' => $approval->status]);
    }

    public function void(ChangePurchaseDocumentStatusRequest $request, PurchaseDocument $purchaseDocument, AuditLogger $audit): JsonResponse
    {
        $this->transition($request, $purchaseDocument, $audit, 'void');

        return response()->json(['status' => true, 'msg' => "ยกเลิก {$purchaseDocument->document_number} แล้ว"]);
    }

    public function post(PostPurchaseDocumentRequest $request, PurchaseDocument $purchaseDocument, PurchaseDocumentPostingService $posting): JsonResponse
    {
        $document = $posting->post(
            $this->scoped($request, $purchaseDocument),
            $request->validated('posting_date'),
            $request->user(),
            $request,
        );

        return response()->json(['status' => true, 'msg' => "Post {$document->document_number} แล้ว"]);
    }

    public function inventoryPost(PostPurchaseDocumentRequest $request, PurchaseDocument $purchaseDocument, InventoryPurchaseProductionAdapter $adapter, AuditLogger $audit): JsonResponse
    {
        abort_unless((bool) config('erp.inventory.purchase_posting_enabled', false), 404);
        $document = $this->scoped($request, $purchaseDocument);
        $before = $this->auditValues($document->load('lines'));
        $warehouse = Warehouse::query()->whereKey($document->warehouse_id)->firstOrFail();
        $posted = $adapter->post($document, $warehouse, $request->user(), null, (bool) config('erp.inventory.purchase_posting_enabled', false), $request->validated('posting_date'));
        $audit->record('wms.purchase_document.inventory_posted', $posted->load('lines'), $before, $this->auditValues($posted), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => "Post Inventory {$posted->document_number} แล้ว"]);
    }

    public function inventoryReverse(Request $request, PurchaseDocument $purchaseDocument, InventoryPurchaseLiveReversalAdapter $reversals, AuditLogger $audit): JsonResponse
    {
        abort_unless((bool) config('erp.inventory.purchase_posting_enabled', false), 404);
        $document = $this->scoped($request, $purchaseDocument);
        $before = $this->auditValues($document->load('lines'));
        $values = $request->validate(['reversal_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $reversed = $reversals->reverse($document, $values['reversal_date'], $values['reason'], $request->user(), true);
        $audit->record('wms.purchase_document.inventory_reversed', $reversed->load('lines'), $before, $this->auditValues($reversed), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => "กลับรายการ {$reversed->document_number} แล้ว"]);
    }

    public function creditInventoryReverse(Request $request, PurchaseDocument $purchaseDocument, CreditPurchaseInventoryReversalAdapter $reversals, AuditLogger $audit): JsonResponse
    {
        abort_unless((bool) config('erp.inventory.purchase_posting_enabled', false), 404);
        $document = $this->scoped($request, $purchaseDocument);
        $before = $this->auditValues($document->load('lines'));
        $values = $request->validate(['reversal_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $reversed = $reversals->reverse($document, $values['reversal_date'], $values['reason'], $request->user(), true);
        $audit->record('wms.purchase_document.credit_inventory_reversed', $reversed->load('lines'), $before, $this->auditValues($reversed), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => "กลับรายการสินค้าจาก {$reversed->document_number} แล้ว"]);
    }

    private function transition(ChangePurchaseDocumentStatusRequest $request, PurchaseDocument $purchaseDocument, AuditLogger $audit, string $transition, ?PurchaseThreeWayMatchGate $matchGate = null): void
    {
        $this->scoped($request, $purchaseDocument);
        DB::transaction(function () use ($request, $purchaseDocument, $audit, $transition, $matchGate) {
            $document = PurchaseDocument::query()->with('lines.receiptAllocations', 'lines.item')->lockForUpdate()->findOrFail($purchaseDocument->id);
            try {
                $status = PurchaseDocumentState::{$transition}($document->status);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['status' => $exception->getMessage()]);
            }
            if ($transition === 'void' && $document->lines->contains(fn ($line): bool => $line->receiptAllocations->isNotEmpty())) {
                throw ValidationException::withMessages([
                    'status' => 'ยกเลิกใบตั้งหนี้ไม่ได้เมื่อมีการเชื่อมรับสินค้าแล้ว ให้ยกเลิกหรือกลับรายการเอกสารปลายทางก่อน',
                ]);
            }
            if ($transition === 'approve') {
                $matchGate?->assertReady($document);
                $values = [
                    'document_type' => $document->document_type,
                    'tax_treatment' => $document->tax_treatment,
                    'prices_include_vat' => $document->prices_include_vat,
                    'supplier_id' => $document->supplier_id,
                    'payment_term_id' => $document->payment_term_id,
                    'withholding_tax_code_id' => $document->withholding_tax_code_id, 'withholding_base' => $document->withholding_base,
                    'purchase_mode' => collect($document->lines)->contains(fn ($line) => $line->receiptAllocations->isNotEmpty() || $line->item?->item_type === 'GOODS') ? 'INVENTORY' : 'EXPENSE',
                    'lines' => $document->lines->map(function ($line): array {
                        $values = $line->only(['description', 'account_id', 'item_id', 'uom_id', 'quantity', 'unit_price', 'discount_amount', 'tax_code_id', 'purchase_order_line_id']);
                        $values['receipt_allocations'] = $line->receiptAllocations->map->only(['goods_receipt_line_id', 'allocated_quantity'])->all();

                        return $values;
                    })->all(),
                ];
                [$supplier] = $this->assertReferences($values, (int) $document->warehouse_id);
                $this->hydrateTaxRates($values);
                $calculation = PurchaseDocumentCalculator::calculate($values['lines'], $document->tax_treatment, (bool) $document->prices_include_vat, (int) $document->tax_decimal_places, (int) $document->tax_decimal_places);
                $calculation = [...$calculation, ...$this->withholdingSnapshot($values, $calculation)];
                $this->assertStoredTotals($document, $calculation);
                $this->assertOriginalCredit([
                    'document_type' => $document->document_type, 'supplier_id' => $document->supplier_id, 'original_document_id' => $document->original_document_id,
                    'document_date' => $document->document_date->format('Y-m-d'),
                ], $document->gross_amount, $document->warehouse_id, $document->id);
            }
            $before = $this->auditValues($document);
            $document->update($transition === 'approve' ? [
                'status' => $status, 'approved_by' => $request->user()->id, 'approved_at' => now(),
                'approval_reason' => $request->validated('reason'), ...$this->supplierSnapshot($supplier),
                'updated_by' => $request->user()->id,
            ] : [
                'status' => $status, 'voided_by' => $request->user()->id, 'voided_at' => now(),
                'void_reason' => $request->validated('reason'), 'updated_by' => $request->user()->id,
            ]);
            $action = $transition === 'approve' ? 'approved' : 'voided';
            $audit->record("wms.purchase_document.{$action}", $document, $before, $this->auditValues($document), $request->user(), $request);
        }, 3);
    }

    private function assertReferences(array $values, int $warehouseId): array
    {
        $supplier = Party::query()->whereKey($values['supplier_id'])->where('is_active', true)->sharedLock()->first();
        $role = $supplier ? PartyRole::query()->where('party_id', $supplier->id)->where('role', 'SUPPLIER')->where('is_active', true)->sharedLock()->first() : null;
        if (! $supplier || ! $role) {
            throw ValidationException::withMessages(['supplier_id' => 'Supplier และบทบาทต้องเปิดใช้งาน']);
        }
        $term = null;
        if (! empty($values['payment_term_id'])) {
            $term = PaymentTerm::query()->whereKey($values['payment_term_id'])->where('is_active', true)->sharedLock()->first();
            if (! $term) {
                throw ValidationException::withMessages(['payment_term_id' => 'เงื่อนไขการชำระเงินต้องเปิดใช้งาน']);
            }
        }
        if (($values['document_type'] ?? null) === 'INVOICE' && ! $term) {
            throw ValidationException::withMessages(['payment_term_id' => 'Invoice ต้องมีเงื่อนไขการชำระเงิน']);
        }
        $this->assertPurchaseMode($values['lines'] ?? [], $values['purchase_mode'] ?? null);
        $this->assertAccounts($values['lines'] ?? []);
        $this->assertInventoryLineReferences($values['lines'] ?? [], $warehouseId);
        $this->assertTaxCodes($values);

        return [$supplier, $term];
    }

    private function assertInventoryLineReferences(array $lines, int $warehouseId): void
    {
        $itemIds = collect($lines)->pluck('item_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        $uomIds = collect($lines)->pluck('uom_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        if ($itemIds->isEmpty() && $uomIds->isEmpty()) {
            return;
        }

        $items = Item::query()->whereIn('id', $itemIds)->where('is_active', true)->sharedLock()->get(['id', 'item_type', 'is_stock_item', 'base_uom_id'])->keyBy('id');
        $uoms = Uom::query()->whereIn('id', $uomIds)->where('is_active', true)->sharedLock()->get(['id'])->keyBy('id');
        $conversions = UomConversion::query()->whereIn('from_uom_id', $uomIds)->whereIn('to_uom_id', $uomIds)->sharedLock()->get(['from_uom_id', 'to_uom_id'])->mapWithKeys(fn (UomConversion $conversion): array => [
            $conversion->from_uom_id.':'.$conversion->to_uom_id => true,
        ]);
        foreach ($lines as $index => $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $uomId = (int) ($line['uom_id'] ?? 0);
            if (! $itemId && ! $uomId) {
                continue;
            }
            $item = $items->get($itemId);
            if (! $item) {
                throw ValidationException::withMessages(["lines.{$index}.item_id" => 'รายการต้องเป็นสินค้าหรือบริการที่เปิดใช้งาน']);
            }
            if ($item->item_type === 'GOODS' && ! $item->is_stock_item) {
                throw ValidationException::withMessages(["lines.{$index}.item_id" => 'สินค้าต้องเป็นสินค้าคงเหลือ']);
            }
            if (! $uoms->has($uomId)) {
                throw ValidationException::withMessages(["lines.{$index}.uom_id" => 'หน่วยสินค้าต้อง active']);
            }
            if ($item->item_type === 'SERVICE') {
                continue;
            }
            $baseUomId = (int) ($item->base_uom_id ?? 0);
            $hasConversion = $conversions->has($uomId.':'.$baseUomId) || $conversions->has($baseUomId.':'.$uomId);
            if ($baseUomId > 0 && $uomId !== $baseUomId && ! $hasConversion) {
                throw ValidationException::withMessages(["lines.{$index}.uom_id" => 'หน่วยสินค้าต้องเป็นหน่วยหลักหรือมี UOM Conversion ของสินค้านี้']);
            }
            if ($warehouseId < 1) {
                throw ValidationException::withMessages(["lines.{$index}.item_id" => 'ไม่พบ Warehouse scope ของเอกสาร']);
            }
        }
    }

    private function hydrateTaxRates(array &$values): void
    {
        $ids = collect($values['lines'] ?? [])->pluck('tax_code_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $codes = TaxCode::query()->whereIn('id', $ids)->where('kind', 'VAT_IN')->where('is_active', true)->sharedLock()->get()->keyBy('id');
        foreach ($values['lines'] ?? [] as $index => &$line) {
            $code = $line['tax_code_id'] ?? null;
            $line['tax_rate'] = $code && $codes->has((int) $code) ? (string) $codes->get((int) $code)->rate : '0';
        }
        unset($line);
    }

    private function assertTaxCodes(array $values): void
    {
        $vat = ($values['tax_treatment'] ?? 'NONE_VAT') === 'VAT_IN';
        $ids = collect($values['lines'] ?? [])->pluck('tax_code_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $count = TaxCode::query()->whereIn('id', $ids)->where('kind', 'VAT_IN')->where('is_active', true)->count();
        $missing = collect($values['lines'] ?? [])->contains(fn (array $line) => empty($line['tax_code_id']));
        if ($vat && ($missing || $count !== $ids->count())) {
            throw ValidationException::withMessages(['lines' => 'VAT IN ต้องเลือก Tax Code ที่เปิดใช้งานครบทุกบรรทัด']);
        }
        if (! $vat && $ids->isNotEmpty()) {
            throw ValidationException::withMessages(['tax_treatment' => 'NONE VAT ห้ามระบุ Tax Code']);
        }
    }

    private function assertAccounts(array $lines): void
    {
        $accountIds = collect($lines)->pluck('account_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $itemTypes = Item::query()->whereIn('id', collect($lines)->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->unique())->get(['id', 'item_type', 'inventory_account_id'])->keyBy('id');
        $accounts = Account::query()->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->whereKey($accountIds)->sharedLock()->get([
                'accounts.id', 'accounts.is_active', 'accounts.is_postable', 'accounts.control_account_type', 'account_types.code as type_code',
            ])->keyBy('id');
        foreach ($lines as $index => $line) {
            $account = $accounts->get((int) ($line['account_id'] ?? 0));
            $item = $itemTypes->get((int) ($line['item_id'] ?? 0));
            $isInventoryLine = $item?->item_type === 'GOODS';
            $validInventoryAccount = $isInventoryLine
                && $account?->type_code === 'ASSET'
                && $account?->control_account_type === 'INVENTORY'
                && (int) ($item?->inventory_account_id ?? 0) === (int) ($line['account_id'] ?? 0);
            $validExpenseAccount = $account?->control_account_type === null
                && in_array($account?->type_code, ['ASSET', 'EXPENSE'], true);
            if (! $account || ! $account->is_active || ! $account->is_postable || (! $validInventoryAccount && ! $validExpenseAccount)) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => 'บัญชีรายการต้องเป็นบัญชีย่อย Asset/Expense ที่เปิดใช้งานและไม่ใช่บัญชีคุม']);
            }
        }
    }

    private function assertPurchaseMode(array $lines, ?string $mode = null): void
    {
        $mode ??= collect($lines)->contains(fn (array $line) => ! empty($line['item_id']) || ! empty($line['receipt_allocations'])) ? 'INVENTORY' : 'EXPENSE';
        if (! in_array($mode, ['INVENTORY', 'EXPENSE'], true)) {
            throw ValidationException::withMessages(['purchase_mode' => 'กรุณาเลือกประเภทการซื้อ']);
        }
        foreach ($lines as $index => $line) {
            $hasItem = ! empty($line['item_id']);
            $hasLink = ! empty($line['purchase_order_line_id']) || ! empty($line['receipt_allocations']);
            if ($mode === 'INVENTORY' && (! $hasItem || ! $hasLink)) {
                throw ValidationException::withMessages(["lines.{$index}.item_id" => 'ซื้อสินค้า/วัตถุดิบต้องเลือกสินค้าและอ้างอิง PO หรือ Goods Receipt']);
            }
            if ($mode === 'EXPENSE' && $hasLink) {
                throw ValidationException::withMessages(["lines.{$index}.purchase_order_line_id" => 'ค่าใช้จ่ายทั่วไปไม่สามารถผูก PO หรือ Goods Receipt ได้']);
            }
        }
        if ($mode === 'EXPENSE') {
            $ids = collect($lines)->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->unique();
            if ($ids->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'ค่าใช้จ่ายทั่วไปต้องเลือกรายการประเภทบริการ']);
            }
            $serviceCount = Item::query()->whereIn('id', $ids)->where('item_type', 'SERVICE')->where('is_active', true)->count();
            if ($serviceCount !== $ids->count()) {
                throw ValidationException::withMessages(['lines' => 'ค่าใช้จ่ายทั่วไปเลือกได้เฉพาะรายการประเภทบริการที่เปิดใช้งาน']);
            }
        } else {
            $ids = collect($lines)->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->unique();
            $goodsCount = Item::query()->whereIn('id', $ids)->where('item_type', 'GOODS')->where('is_stock_item', true)->where('is_active', true)->count();
            if ($goodsCount !== $ids->count()) {
                throw ValidationException::withMessages(['lines' => 'ซื้อสินค้า/วัตถุดิบเลือกได้เฉพาะสินค้าคงเหลือประเภทสินค้า']);
            }
        }
    }

    private function assertOriginalCredit(array $values, string $grossAmount, int $warehouseId, ?int $ignoreId = null): void
    {
        if (($values['document_type'] ?? null) !== 'CREDIT_NOTE') {
            return;
        }
        $original = PurchaseDocument::query()->whereKey($values['original_document_id'])->where('warehouse_id', $warehouseId)
            ->where('supplier_id', $values['supplier_id'])->where('document_type', 'INVOICE')->where('status', 'POSTED')->lockForUpdate()->first();
        if (! $original) {
            throw ValidationException::withMessages(['original_document_id' => 'ใบลดหนี้ต้องอ้าง invoice ที่ Post แล้วของ Supplier และ Warehouse เดียวกัน']);
        }
        if ($values['document_date'] < $original->document_date->format('Y-m-d')) {
            throw ValidationException::withMessages(['document_date' => 'วันที่ใบลดหนี้ต้องไม่ก่อน invoice ต้นทาง']);
        }
        $credits = PurchaseDocument::query()->where('original_document_id', $original->id)->where('document_type', 'CREDIT_NOTE')
            ->where('status', '!=', 'VOID')->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->orderBy('id')->lockForUpdate()->get(['gross_amount']);
        $used = JournalBalance::totals($credits->map(fn (PurchaseDocument $credit) => ['debit' => $credit->gross_amount, 'credit' => 0])->all())['debit'];
        $candidate = JournalBalance::totals([['debit' => $grossAmount, 'credit' => 0]])['debit'];
        $limit = JournalBalance::totals([['debit' => $original->gross_amount, 'credit' => 0]])['debit'];
        if ($used + $candidate > $limit) {
            throw ValidationException::withMessages(['lines' => 'ยอดใบลดหนี้สะสมเกินยอด invoice ต้นทาง']);
        }
    }

    /**
     * Keep Credit Purchase inventory lines inside the exact GR quantity
     * received on the original invoice. A credit may split across many GR
     * lines, but may not introduce a new GR, duplicate a GR line, or exceed
     * the remaining quantity after earlier credit notes.
     */
    private function assertCreditLineCaps(array $values, int $warehouseId, ?int $ignoreId = null): void
    {
        if (($values['document_type'] ?? null) !== 'CREDIT_NOTE') {
            return;
        }

        $original = PurchaseDocument::query()->with('lines.receiptAllocations')
            ->whereKey($values['original_document_id'] ?? 0)
            ->where('warehouse_id', $warehouseId)
            ->where('supplier_id', $values['supplier_id'] ?? 0)
            ->where('document_type', 'INVOICE')
            ->where('status', 'POSTED')
            ->lockForUpdate()->first();
        if (! $original) {
            throw ValidationException::withMessages(['original_document_id' => 'ไม่พบ Invoice ต้นทางสำหรับตรวจสอบจำนวนสินค้า']);
        }

        $sourceAllocations = collect();
        foreach ($original->lines as $line) {
            foreach ($line->receiptAllocations as $allocation) {
                $sourceAllocations->put((int) $allocation->goods_receipt_line_id, [
                    'line_id' => (int) $line->id,
                    'item_id' => (int) $line->item_id,
                    'uom_id' => (int) $line->uom_id,
                    'quantity' => BigDecimal::of((string) $allocation->allocated_quantity),
                ]);
            }
        }

        $existing = PurchaseDocument::query()->with('lines.receiptAllocations')
            ->where('original_document_id', $original->id)
            ->where('document_type', 'CREDIT_NOTE')
            ->where('status', '!=', 'VOID')
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->lockForUpdate()->get()
            ->flatMap(fn (PurchaseDocument $credit) => $credit->lines->flatMap(fn ($line) => $line->receiptAllocations))
            ->groupBy(fn ($allocation) => (int) $allocation->goods_receipt_line_id)
            ->map(fn ($allocations) => $allocations->reduce(
                fn (BigDecimal $sum, $allocation): BigDecimal => $sum->plus((string) $allocation->allocated_quantity),
                BigDecimal::zero(),
            ));

        $requested = collect();
        foreach (($values['lines'] ?? []) as $index => $line) {
            $allocations = collect($line['receipt_allocations'] ?? []);
            if ($allocations->isEmpty()) {
                continue;
            }
            $lineQuantity = BigDecimal::of((string) ($line['quantity'] ?? '0'));
            $allocatedForLine = BigDecimal::zero();
            foreach ($allocations as $allocation) {
                $receiptLineId = (int) ($allocation['goods_receipt_line_id'] ?? 0);
                $source = $sourceAllocations->get($receiptLineId);
                if (! $source) {
                    throw ValidationException::withMessages(["lines.{$index}.receipt_allocations" => 'ใบลดหนี้ต้องอ้าง GR line ที่อยู่ใน Invoice ต้นทางเท่านั้น']);
                }
                if ((int) ($line['item_id'] ?? 0) !== $source['item_id'] || (int) ($line['uom_id'] ?? 0) !== $source['uom_id']) {
                    throw ValidationException::withMessages(["lines.{$index}.receipt_allocations" => 'สินค้า/หน่วยของ Credit Purchase ไม่ตรงกับ GR line ต้นทาง']);
                }
                $quantity = BigDecimal::of((string) ($allocation['allocated_quantity'] ?? '0'));
                $allocatedForLine = $allocatedForLine->plus($quantity);
                $requested->put($receiptLineId, $requested->get($receiptLineId, BigDecimal::zero())->plus($quantity));
            }
            if ($allocatedForLine->isNotEqualTo($lineQuantity)) {
                throw ValidationException::withMessages(["lines.{$index}.receipt_allocations" => 'จำนวนที่อ้างจาก GR ต้องเท่ากับจำนวนในบรรทัด Credit Purchase']);
            }
        }

        foreach ($requested as $receiptLineId => $quantity) {
            $source = $sourceAllocations->get((int) $receiptLineId);
            $used = $existing->get((int) $receiptLineId, BigDecimal::zero());
            if ($used->plus($quantity)->isGreaterThan($source['quantity'])) {
                throw ValidationException::withMessages(['lines' => 'จำนวน Credit Purchase สะสมเกินจำนวนที่รับจริงจาก GR line #'.$receiptLineId]);
            }
        }
    }

    private function headerValues(array $values, array $calculation, ?PaymentTerm $term, int $taxDecimals): array
    {
        $isInvoice = $values['document_type'] === 'INVOICE';

        return [
            'document_type' => $values['document_type'], 'original_document_id' => $values['document_type'] === 'CREDIT_NOTE' ? (int) $values['original_document_id'] : null,
            'document_date' => $values['document_date'], 'payment_term_id' => $isInvoice ? $term?->id : null,
            'due_date' => $isInvoice && $term ? PaymentDueDate::calculate($values['document_date'], $term->due_rule, $term->credit_days) : null,
            'tax_treatment' => $values['tax_treatment'], 'prices_include_vat' => (bool) $values['prices_include_vat'], 'tax_decimal_places' => $taxDecimals,
            'subtotal' => $calculation['subtotal'], 'tax_amount' => $calculation['tax_amount'], 'gross_amount' => $calculation['gross_amount'],
            'rounding_amount' => '0.00', 'description' => $values['description'] ?? null,
            'withholding_tax_code_id' => $calculation['withholding_tax_code_id'], 'withholding_rate' => $calculation['withholding_rate'],
            'withholding_base' => $calculation['withholding_base'], 'withholding_amount' => $calculation['withholding_amount'],
        ];
    }

    private function withholdingSnapshot(array $values, array $calculation): array
    {
        $id = (int) ($values['withholding_tax_code_id'] ?? 0);
        $base = BigDecimal::of((string) ($values['withholding_base'] ?? '0') ?: '0');
        if (($values['document_type'] ?? 'INVOICE') !== 'INVOICE') {
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
        $limit = BigDecimal::of((string) $calculation['subtotal']);
        if ($base->isNegative() || $base->isGreaterThan($limit)) {
            throw ValidationException::withMessages(['withholding_base' => 'ฐานหัก ณ ที่จ่ายต้องไม่เกินยอดก่อน VAT']);
        }
        $amount = $base->multipliedBy(BigDecimal::of((string) $tax->rate))->dividedBy(100, 2, RoundingMode::HALF_UP);

        return ['withholding_tax_code_id' => $tax->id, 'withholding_rate' => (string) $tax->rate, 'withholding_base' => $base->toScale(2, RoundingMode::HALF_UP)->__toString(), 'withholding_amount' => $amount->toScale(2, RoundingMode::HALF_UP)->__toString()];
    }

    private function replaceLines(PurchaseDocument $document, array $lines): void
    {
        $document->lines()->delete();
        collect($lines)->values()->each(function (array $line, int $index) use ($document): void {
            $allocations = $line['receipt_allocations'] ?? [];
            unset($line['receipt_allocations']);
            $savedLine = $document->lines()->create([...$line, 'line_number' => $index + 1]);
            foreach ($allocations as $allocation) {
                $savedLine->receiptAllocations()->create([
                    'goods_receipt_line_id' => (int) $allocation['goods_receipt_line_id'],
                    'allocated_quantity' => $allocation['allocated_quantity'],
                    'allocated_amount' => '0.00000000',
                    'idempotency_key' => 'pdl-'.$savedLine->id.'-grl-'.(int) $allocation['goods_receipt_line_id'],
                ]);
            }
        });
    }

    private function supplierSnapshot(Party $supplier): array
    {
        return [
            'supplier_code' => $supplier->code,
            'supplier_name' => $supplier->name,
            'supplier_tax_id' => $supplier->tax_id,
            'supplier_branch_code' => $supplier->branch_code,
            'supplier_address' => $supplier->address,
        ];
    }

    private function assertStoredTotals(PurchaseDocument $document, array $calculation): void
    {
        if ($document->subtotal !== $calculation['subtotal'] || $document->tax_amount !== $calculation['tax_amount']
            || $document->gross_amount !== $calculation['gross_amount'] || $document->rounding_amount !== '0.00'
            || (int) $document->withholding_tax_code_id !== (int) ($calculation['withholding_tax_code_id'] ?? 0)
            || $document->withholding_base !== $calculation['withholding_base']
            || $document->withholding_rate !== $calculation['withholding_rate']
            || $document->withholding_amount !== $calculation['withholding_amount']) {
            throw ValidationException::withMessages(['lines' => 'ยอดเอกสารไม่ตรงกับบรรทัด กรุณาบันทึกร่างใหม่']);
        }
    }

    private function formData(PurchaseDocument $document): array
    {
        $document->loadMissing(['supplier', 'paymentTerm', 'originalDocument', 'lines.account', 'lines.taxCode', 'lines.purchaseOrderLine.purchaseOrder', 'lines.receiptAllocations.goodsReceiptLine.goodsReceipt']);
        $oldLines = old('lines', []);
        $accountIds = collect($oldLines)->pluck('account_id')->merge($document->lines->pluck('account_id'))->filter()->unique();
        $taxIds = collect($oldLines)->pluck('tax_code_id')->merge($document->lines->pluck('tax_code_id'))->filter()->unique();
        $itemIds = collect($oldLines)->pluck('item_id')->merge($document->lines->pluck('item_id'))->filter()->unique();
        $uomIds = collect($oldLines)->pluck('uom_id')->merge($document->lines->pluck('uom_id'))->filter()->unique();
        $poLineIds = collect($oldLines)->pluck('purchase_order_line_id')->merge($document->lines->pluck('purchase_order_line_id'))->filter()->unique();
        $receiptLineIds = collect($oldLines)->flatMap(fn ($line) => $line['receipt_allocations'] ?? [])->pluck('goods_receipt_line_id')->merge($document->lines->flatMap(fn ($line) => $line->receiptAllocations->pluck('goods_receipt_line_id')))->filter()->unique();
        $selectedSupplierId = old('supplier_id', $document->supplier_id);
        $selectedOriginalId = old('original_document_id', $document->original_document_id);
        $selectedGoodsReceiptIds = GoodsReceiptLine::query()
            ->whereKey($receiptLineIds)
            ->pluck('goods_receipt_id')
            ->filter()
            ->unique()
            ->values();

        return [
            'document' => $document,
            'paymentTerms' => PaymentTerm::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'selectedSupplier' => $selectedSupplierId ? Party::query()->find($selectedSupplierId) : null,
            'selectedOriginal' => $selectedOriginalId ? PurchaseDocument::query()->where('warehouse_id', $document->warehouse_id)->find($selectedOriginalId) : null,
            'withholdingTaxCode' => ($id = old('withholding_tax_code_id', $document->withholding_tax_code_id)) ? TaxCode::query()->whereKey($id)->where('kind', 'WHT')->first(['id', 'code', 'name', 'rate']) : null,
            'lineAccounts' => Account::query()->whereKey($accountIds)->get(['id', 'code', 'name'])->keyBy('id'),
            'lineTaxCodes' => TaxCode::query()->whereKey($taxIds)->get(['id', 'code', 'name', 'rate'])->keyBy('id'),
            'lineItems' => Item::query()->whereKey($itemIds)->where('is_active', true)->get(['id', 'code', 'name'])->keyBy('id'),
            'lineUoms' => Uom::query()->whereKey($uomIds)->where('is_active', true)->get(['id', 'code', 'name'])->keyBy('id'),
            'linePurchaseOrderLines' => PurchaseOrderLine::query()->with('purchaseOrder:id,document_number')->whereKey($poLineIds)->get()->keyBy('id'),
            'lineGoodsReceiptLines' => GoodsReceiptLine::query()->with('goodsReceipt:id,receipt_number')->whereKey($receiptLineIds)->get()->keyBy('id'),
            'selectedGoodsReceiptIds' => $selectedGoodsReceiptIds,
        ];
    }

    private function scoped(Request $request, PurchaseDocument $document): PurchaseDocument
    {
        abort_unless((int) $document->{$this->purchasingScopeColumn()} === $this->purchasingScopeId($request) && in_array((int) $document->warehouse_id, $this->authorizedWarehouseIds($request), true), 404);

        return $document;
    }

    private function documentsQuery(Request $request): Builder
    {
        return PurchaseDocument::query()->leftJoin('purchase_documents as originals', 'originals.id', '=', 'purchase_documents.original_document_id')
            ->where('purchase_documents.'.$this->purchasingScopeColumn(), $this->purchasingScopeId($request))
            ->whereIn('purchase_documents.warehouse_id', $this->authorizedWarehouseIds($request))
            ->when(in_array($request->query('document_type'), ['INVOICE', 'CREDIT_NOTE'], true), fn (Builder $query) => $query->where('purchase_documents.document_type', $request->query('document_type')))
            ->select(['purchase_documents.*', 'originals.document_number as original_number'])
            ->withCount('lines')
            ->with(['lines.item:id,item_type', 'lines.receiptAllocations:id,purchase_document_line_id']);
    }

    private function isInventoryPurchase(PurchaseDocument $document): bool
    {
        return $document->lines->contains(fn ($line) => $line->receiptAllocations->isNotEmpty() || $line->item?->item_type === 'GOODS');
    }

    private function purchasingScopeColumn(): string
    {
        return $this->moduleRoutePrefix() === 'purchasing' ? 'branch_id' : 'warehouse_id';
    }

    private function purchasingScopeId(Request $request): int
    {
        return (int) ($this->moduleRoutePrefix() === 'purchasing'
            ? $request->attributes->get('selectedBranch')->id
            : $request->attributes->get('selectedWarehouse')->id);
    }

    /** @return list<int> */
    protected function authorizedWarehouseIds(Request $request): array
    {
        return [(int) $request->attributes->get('selectedWarehouse')->id];
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('purchase_documents.document_number', 'like', "%{$search}%")
                ->orWhere('purchase_documents.supplier_code', 'like', "%{$search}%")->orWhere('purchase_documents.supplier_name', 'like', "%{$search}%")
                ->orWhere('purchase_documents.supplier_tax_id', 'like', "%{$search}%")
                ->orWhere('originals.document_number', 'like', "%{$search}%")->orWhere('purchase_documents.status', 'like', "%{$search}%"));
        }
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'purchase_documents.document_number', 1 => 'purchase_documents.document_type', 2 => 'purchase_documents.document_date',
            3 => 'purchase_documents.supplier_code', 4 => 'originals.document_number', 5 => 'purchase_documents.due_date',
            6 => 'purchase_documents.gross_amount', 7 => 'purchase_documents.status',
        ];
        $column = $columns[(int) $request->input('order.0.column', 2)] ?? 'purchase_documents.document_date';
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';
        $query->reorder($column, $direction)->orderByDesc('purchase_documents.id');
    }

    private function auditValues(PurchaseDocument $document): array
    {
        return $document->only([
            'warehouse_id', 'document_type', 'original_document_id', 'document_number', 'document_date', 'supplier_id',
            'supplier_code', 'supplier_name', 'supplier_tax_id', 'supplier_branch_code', 'supplier_address',
            'payment_term_id', 'due_date', 'tax_treatment', 'subtotal', 'tax_amount', 'gross_amount',
            'withholding_tax_code_id', 'withholding_rate', 'withholding_base', 'withholding_amount', 'status',
            'approved_by', 'approved_at', 'voided_by', 'voided_at', 'description',
        ]);
    }
}
