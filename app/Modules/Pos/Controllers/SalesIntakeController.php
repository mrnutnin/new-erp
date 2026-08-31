<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\SalesIntake;
use App\Modules\Pos\Models\SalesIntakeLine;
use App\Modules\Pos\Models\SalesRfq;
use App\Modules\Pos\Requests\SaveSalesIntakeRequest;
use App\Modules\Pos\Services\PriceListResolver;
use App\Modules\Pos\Services\PromotionResolver;
use App\Modules\Pos\Support\DocumentPromotionDiscountAllocator;
use App\Modules\Pos\Support\PromotionSnapshot;
use App\Modules\Pos\Support\PromotionStack;
use App\Modules\Pos\Support\SalesDocumentTrail;
use App\Modules\Pos\Support\SalesIntakeCalculator;
use App\Modules\Pos\Support\SalesIntakePriceRule;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Support\WmsDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class SalesIntakeController extends Controller
{
    public function index(): View
    {
        return view('Pos::sales-intakes.index');
    }

    public function data(Request $r): JsonResponse
    {
        $q = SalesIntake::query()->where('branch_id', $this->branchId())->withCount('lines')->with(['rfq:id,source_sales_intake_id,document_number,status', 'rfq.quotation:id,sales_rfq_id,document_number,status', 'rfq.order:id,sales_rfq_id,document_number,status', 'quotation:id,source_sales_intake_id,document_number,status', 'quotation.order:id,sales_quotation_id,document_number,status', 'order:id,source_sales_intake_id,document_number,status', 'order.physicalSales:id,source_id,document_type,document_number,status', 'quotation.order.physicalSales:id,source_id,document_type,document_number,status', 'rfq.quotation.order.physicalSales:id,source_id,document_type,document_number,status', 'rfq.order.physicalSales:id,source_id,document_type,document_number,status']);
        if ($r->filled('date_from')) {
            $q->whereDate('document_date', '>=', $r->date_from);
        }if ($r->filled('date_to')) {
            $q->whereDate('document_date', '<=', $r->date_to);
        }$q->when($r->filled('status'), fn ($x) => $x->where('status', $r->status))->when($r->filled('party_id'), fn ($x) => $x->where('party_id', $r->party_id));

        return DataTables::eloquent($q)->filter(fn (Builder $x) => $this->search($x, $r))->order(fn (Builder $x) => $x->orderByDesc('document_date')->orderByDesc('id'))->addColumn('party_label', fn ($x) => $x->party_code.' · '.$x->party_name)->addColumn('status_label', fn ($x) => ['DRAFT' => 'ร่าง', 'COMPLETED' => 'เสร็จสิ้น', 'CANCELLED' => 'ยกเลิก'][$x->status] ?? $x->status)->addColumn('progress', fn (SalesIntake $x) => $this->progress($x))->addColumn('rfq_label', fn ($x) => $x->rfq?->document_number)->addColumn('rfq_url', fn ($x) => $x->rfq ? route('pos.sales-rfqs.show', $x->rfq) : null)->addColumn('show_url', fn ($x) => route('pos.sales-intakes.show', $x))->addColumn('to_rfq_url', fn ($x) => in_array($x->status, ['DRAFT', 'COMPLETED'], true) && $x->requires_rfq && ! $x->rfq && $r->user()->hasPermission('pos.sales-intakes.convert') ? route('pos.sales-intakes.to-rfq', $x) : null)->addColumn('to_quotation_url', fn (SalesIntake $x) => $r->user()->hasPermission('pos.sales-quotations.create') ? $this->quotationActionUrl($x) : null)->addColumn('to_order_url', fn (SalesIntake $x) => $r->user()->hasPermission('pos.sales-orders.create') ? $this->orderActionUrl($x) : null)->addColumn('edit_url', fn ($x) => $x->status === 'DRAFT' && $r->user()->hasPermission('pos.sales-intakes.update') ? route('pos.sales-intakes.edit', $x) : null)->addColumn('complete_url', fn ($x) => $x->status === 'DRAFT' && $r->user()->hasPermission('pos.sales-intakes.complete') ? route('pos.sales-intakes.complete', $x) : null)->addColumn('cancel_url', fn ($x) => $x->status === 'DRAFT' && $r->user()->hasPermission('pos.sales-intakes.cancel') ? route('pos.sales-intakes.cancel', $x) : null)->addColumn('delete_url', fn ($x) => $x->status === 'DRAFT' && $r->user()->hasPermission('pos.sales-intakes.delete') ? route('pos.sales-intakes.destroy', $x) : null)->toJson();
    }

    public function partyOptions(Request $r): JsonResponse
    {
        $q = trim((string) $r->input('q'));
        $rows = Party::query()->join('party_roles', fn ($j) => $j->on('party_roles.party_id', '=', 'parties.id')->where('party_roles.role', 'CUSTOMER')->where('party_roles.is_active', true))->where('parties.is_active', true)->when($q, fn ($x) => $x->where(fn ($y) => $y->where('parties.code', 'like', "%$q%")->orWhere('parties.name', 'like', "%$q%")))->select('parties.id', 'parties.code', 'parties.name')->distinct()->orderBy('parties.code')->forPage(max(1, $r->integer('page', 1)), 31)->get();

        return response()->json(['results' => $rows->take(30)->map(fn ($x) => ['id' => $x->id, 'text' => $x->code.' · '.$x->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function partyAddresses(Request $r): JsonResponse
    {
        $partyId = $r->validate(['party_id' => ['required', 'integer', 'min:1']])['party_id'];
        $party = Party::query()->where('is_active', true)->whereKey($partyId)
            ->whereHas('customerRole', fn (Builder $query) => $query->where('is_active', true))
            ->firstOrFail();
        $addresses = $party->addresses()->where('is_active', true)->whereIn('address_type', ['BILLING', 'SHIPPING'])
            ->orderByDesc('is_default')->orderBy('label')->get();

        return response()->json([
            'billing' => $this->addressOptions($addresses->where('address_type', 'BILLING'), $party->address),
            'shipping' => $this->addressOptions($addresses->where('address_type', 'SHIPPING'), $party->address),
        ]);
    }

    public function itemOptions(Request $r): JsonResponse
    {
        return $this->opts(Item::query()->where('is_active', true), $r, ['id', 'code', 'name', 'base_uom_id'], fn ($x) => ['id' => $x->id, 'text' => $x->code.' · '.$x->name, 'uom_id' => $x->base_uom_id]);
    }

    public function itemDefaults(Request $r): JsonResponse
    {
        $data = $r->validate([
            'item_id' => ['required', 'integer', 'min:1'],
            'party_id' => ['nullable', 'integer', 'min:1'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'quantity' => array_merge(['nullable'], WmsDecimal::rule(), ['gt:0']),
        ]);
        $item = Item::query()->with('baseUom')->where('is_active', true)->findOrFail($data['item_id']);
        abort_unless($item->baseUom, 422, 'สินค้ายังไม่ได้ตั้งค่าหน่วย Stock');
        $date = Carbon::parse($data['document_date'] ?? now()->toDateString());
        $party = ! empty($data['party_id']) ? Party::query()->where('is_active', true)->find($data['party_id']) : null;
        $price = app(PriceListResolver::class)->resolve($this->branchId(), $item->id, $item->baseUom->id, $party ? $this->customerGroupCode($party) : null, $date, (string) ($data['quantity'] ?? '1'));

        return response()->json([
            'item_id' => $item->id,
            'uom_id' => $item->baseUom->id,
            'uom_text' => $item->baseUom->code.' · '.$item->baseUom->name,
            'standard_price' => is_array($price) ? ($price['unit_price'] ?? null) : null,
        ]);
    }

    /** Returns eligible promotion identifiers only; commercial terms remain server-resolved on save. */
    public function promotionOptions(Request $r, PromotionResolver $resolver): JsonResponse
    {
        $data = $r->validate([
            'party_id' => ['required', 'integer', 'min:1'],
            'document_date' => ['required', 'date_format:Y-m-d'],
            'scope' => ['required', 'in:LINE,DOCUMENT'],
            'item_id' => ['required_if:scope,LINE', 'nullable', 'integer', 'min:1'],
            'quantity' => array_merge(['required_if:scope,LINE', 'nullable'], WmsDecimal::rule(), ['gt:0']),
        ]);
        $party = Party::query()->where('is_active', true)->whereKey($data['party_id'])->first();
        abort_unless($party && PartyRole::query()->where(['party_id' => $party->id, 'role' => 'CUSTOMER', 'is_active' => true])->exists(), 422, 'ลูกค้าไม่พร้อมใช้งาน');
        $date = Carbon::parse($data['document_date']);
        $promotions = $data['scope'] === 'DOCUMENT'
            ? $resolver->resolveDocumentAll($this->customerGroupCode($party), $date)
            : $this->resolveLinePromotions($resolver, (int) $data['item_id'], $date, (string) $data['quantity'], $party);

        return response()->json(['results' => collect($promotions)->map(fn (array $promotion) => [
            'id' => $promotion['promotion_id'],
            'text' => $promotion['promotion_code'],
            'unit_price' => $promotion['base_unit_price'] ?? $promotion['unit_price'] ?? null,
            'discount_amount' => $data['scope'] === 'LINE' ? PromotionSnapshot::discountAmount($promotion, (string) $data['quantity']) : null,
        ])->values()]);
    }

    public function uomOptions(Request $r): JsonResponse
    {
        return $this->opts(Uom::query()->where('is_active', true), $r, ['id', 'code', 'name'], fn ($x) => ['id' => $x->id, 'text' => $x->code.' · '.$x->name]);
    }

    private function opts(Builder $q, Request $r, array $cols, callable $map): JsonResponse
    {
        $s = trim((string) $r->input('q'));
        $rows = $q->when($s, fn ($x) => $x->where(fn ($y) => $y->where('code', 'like', "%$s%")->orWhere('name', 'like', "%$s%")))->orderBy('code')->forPage(max(1, $r->integer('page', 1)), 31)->get($cols);

        return response()->json(['results' => $rows->take(30)->map($map)->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    private function addressOptions($addresses, ?string $fallback): array
    {
        $options = $addresses->map(fn ($address) => [
            'value' => collect([$address->address_line, $address->district, $address->amphoe, $address->province, $address->postal_code])->filter()->implode(' '),
            'text' => collect([$address->label ?: 'ที่อยู่', $address->recipient_name, $address->address_line, $address->province, $address->postal_code])->filter()->implode(' · '),
            'is_default' => $address->is_default,
        ])->values();

        return $options->isNotEmpty() || blank($fallback)
            ? $options->all()
            : [['value' => $fallback, 'text' => 'ที่อยู่หลัก · '.$fallback, 'is_default' => true]];
    }

    public function create(): View
    {
        return view('Pos::sales-intakes.form', [
            'intake' => new SalesIntake(['document_date' => now()->toDateString(), 'prepared_by' => auth()->id(), 'tax_treatment' => 'VAT_OUT', 'prices_include_vat' => true]),
            'lines' => [new SalesIntakeLine(['line_number' => 1, 'quantity' => '1.0000'])],
            'party' => null, 'decimalPlaces' => WmsDecimal::places(),
            'preparedUsers' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'taxCodes' => TaxCode::query()->where('kind', 'VAT_OUT')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'rate']),
        ]);
    }

    public function edit(Request $r, SalesIntake $salesIntake): View
    {
        $x = $this->scope($r, $salesIntake)->load('lines.item.baseUom', 'lines.uom', 'party');
        abort_unless($x->status === 'DRAFT', 403);

        return view('Pos::sales-intakes.form', ['intake' => $x, 'lines' => $x->lines, 'party' => $x->party, 'decimalPlaces' => WmsDecimal::places(),
            'preparedUsers' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'taxCodes' => TaxCode::query()->where('kind', 'VAT_OUT')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'rate']),
        ]);
    }

    public function store(SaveSalesIntakeRequest $r, DocumentSequenceService $s, AuditLogger $a): JsonResponse
    {
        return $this->save($r, new SalesIntake, $s, $a);
    }

    public function update(SaveSalesIntakeRequest $r, SalesIntake $salesIntake, DocumentSequenceService $s, AuditLogger $a): JsonResponse
    {
        abort_unless($this->scope($r, $salesIntake)->status === 'DRAFT', 403);

        return $this->save($r, $salesIntake, $s, $a);
    }

    private function save(SaveSalesIntakeRequest $r, SalesIntake $x, DocumentSequenceService $seq, AuditLogger $audit): JsonResponse
    {
        $x = DB::transaction(function () use ($r, $x, $seq, $audit) {
            $d = $r->validated();
            $party = Party::query()->lockForUpdate()->findOrFail($d['party_id']);
            abort_unless($party->is_active && PartyRole::query()->where(['party_id' => $party->id, 'role' => 'CUSTOMER', 'is_active' => true])->exists(), 422);
            $preparedBy = User::query()->where('is_active', true)->find((int) ($d['prepared_by'] ?? $r->user()->id));
            abort_unless($preparedBy, 422, 'ไม่พบพนักงานที่ใช้งานได้');
            $date = Carbon::parse($d['document_date']);
            $s = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => 'SALES_INTAKE', 'is_active' => true])->lockForUpdate()->first();
            if (! $s) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบรับข้อมูล']);
            }$before = $x->exists ? $x->toArray() : [];
            if (! $x->exists) {
                $x->warehouse_id = $this->wid();
                $x->branch_id = $this->branchId();
                $x->created_by = $r->user()->id;
                $x->document_number = $seq->issueForBranch($s, $r->attributes->get('selectedBranch'), $date);
            } elseif ((string) $x->document_date !== $date->toDateString()) {
                $x->document_number = $seq->replaceDraftNumberForBranch($s, $r->attributes->get('selectedBranch'), $x->document_number, 'sales_intakes', $x->id, $date, $r->user()->id);
            }
            $requires = false;
            $preparedLines = [];
            foreach ($d['lines'] as $i => $line) {
                $item = ! empty($line['item_id']) ? Item::query()->with('baseUom')->where('is_active', true)->find($line['item_id']) : null;
                $uom = $item?->baseUom;
                if (! empty($line['item_id']) && ! $item) {
                    throw ValidationException::withMessages(["lines.$i.item_id" => 'สินค้าไม่พร้อมใช้งาน']);
                }
                $requested = $line['requested_unit_price'] ?? null;
                $standard = null;
                $promotion = null;
                $priceSnapshot = null;
                if ($item && ! $uom) {
                    throw ValidationException::withMessages(["lines.$i.item_id" => 'สินค้ายังไม่ได้ตั้งค่าหน่วย Stock']);
                }
                if ($item) {
                    if (! empty($line['promotion_id'])) {
                        $promotion = $this->resolveLinePromotion(app(PromotionResolver::class), $item->id, $date, (string) $line['quantity'], $party, (int) $line['promotion_id']);
                        if (! $promotion) {
                            throw ValidationException::withMessages(["lines.$i.promotion_id" => 'Promotion ที่เลือกไม่เข้าเงื่อนไขหรือหมดอายุแล้ว']);
                        }
                        $standard = (string) ($promotion['base_unit_price'] ?? $promotion['unit_price']);
                        $requested = null;
                    } else {
                        $priceSnapshot = app(PriceListResolver::class)->resolve($this->branchId(), $item->id, $uom->id, $this->customerGroupCode($party), $date, (string) $line['quantity']);
                        $standard = is_array($priceSnapshot) ? ($priceSnapshot['unit_price'] ?? null) : null;
                    }
                    if ($requested !== null && $standard !== null && SalesIntakePriceRule::requiresRfq((string) $requested, (string) $standard)) {
                        $requires = true;
                    }
                }
                $taxCode = null;
                $taxRate = '0';
                if (($d['tax_treatment'] ?? 'NONE_VAT') === 'VAT_OUT') {
                    $taxCode = TaxCode::query()->where('kind', 'VAT_OUT')->where('is_active', true)->lockForUpdate()->find($line['tax_code_id'] ?? null);
                    abort_unless($taxCode, 422, 'กรุณาเลือก Tax Code ภาษีขายที่ใช้งานได้');
                    $taxRate = (string) $taxCode->rate;
                }
                $preparedLines[] = [
                    'line_number' => $i + 1, 'item_id' => $item?->id, 'uom_id' => $uom?->id,
                    'description' => $line['description'], 'quantity' => $line['quantity'],
                    'standard_unit_price' => $standard, 'requested_unit_price' => $requested,
                    'unit_price' => $requested ?? $standard ?? '0', 'discount_amount' => $promotion ? PromotionSnapshot::discountAmount($promotion, (string) $line['quantity']) : ($line['discount_amount'] ?? '0'),
                    'promotion_discount_amount' => $promotion ? PromotionSnapshot::discountAmount($promotion, (string) $line['quantity']) : '0',
                    'tax_code_id' => $taxCode?->id, 'tax_rate' => $taxRate,
                    'pricing_snapshot' => $promotion ?? $priceSnapshot, 'item_snapshot' => $item?->only(['code', 'name']), 'uom_snapshot' => $uom?->only(['code', 'name']),
                ];
            }
            $documentPromotion = $this->resolveDocumentPromotion($d['document_promotion_id'] ?? null, $party, $date);
            try {
                PromotionStack::assertValid(array_filter([
                    $documentPromotion,
                    ...array_column($preparedLines, 'pricing_snapshot'),
                ]));
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['document_promotion_id' => 'Promotion ที่เลือกไม่อนุญาตให้ใช้ร่วมกัน']);
            }
            [$preparedLines, $documentPromotionDiscount] = $this->applyDocumentPromotionDiscount($preparedLines, $documentPromotion);
            $taxTreatment = $d['tax_treatment'] ?? 'NONE_VAT';
            $calc = app(SalesIntakeCalculator::class)->calculate($preparedLines, $taxTreatment, (bool) ($d['prices_include_vat'] ?? false), WmsDecimal::places());
            $x->fill([
                'party_id' => $party->id, 'party_code' => $party->code, 'party_name' => $party->name,
                'party_tax_id' => $party->tax_id, 'party_branch_code' => $party->branch_code, 'party_address' => $party->address,
                'document_date' => $date, 'prepared_by' => $preparedBy->id, 'source' => $d['source'] ?? null,
                'order_method' => $d['order_method'] ?? null, 'delivery_method' => $d['delivery_method'] ?? null,
                'appointment_date' => $d['appointment_date'] ?? null, 'tax_treatment' => $taxTreatment,
                'prices_include_vat' => (bool) ($d['prices_include_vat'] ?? false),
                'billing_address' => $d['billing_address'] ?? $party->address, 'shipping_address' => $d['shipping_address'] ?? $party->address,
                'tax_decimal_places' => WmsDecimal::places(), 'subtotal' => $calc['subtotal'], 'discount_amount' => $calc['discount_amount'],
                'promotion_snapshot' => $documentPromotion, 'promotion_discount_amount' => $documentPromotionDiscount,
                'tax_base' => $calc['tax_base'], 'tax_amount' => $calc['tax_amount'], 'grand_total' => $calc['grand_total'],
                'requires_rfq' => $requires, 'description' => $d['description'] ?? null, 'updated_by' => $r->user()->id,
            ])->save();
            if ($x->wasRecentlyCreated) {
                $seq->recordIssued($s, $x->document_number, 'sales_intakes', $x->id, $date, $r->user()->id);
            }
            $x->lines()->delete();
            foreach ($calc['lines'] as $line) {
                $x->lines()->create([
                    'line_number' => $line['line_number'], 'item_id' => $line['item_id'], 'uom_id' => $line['uom_id'],
                    'description' => $line['description'], 'quantity' => $line['quantity'],
                    'standard_unit_price' => $line['standard_unit_price'], 'requested_unit_price' => $line['requested_unit_price'],
                    'discount_amount' => $line['discount_amount'], 'promotion_discount_amount' => $line['promotion_discount_amount'], 'pricing_snapshot' => $line['pricing_snapshot'], 'tax_code_id' => $line['tax_code_id'],
                    'tax_rate' => $line['tax_rate'], 'tax_base' => $line['tax_base'], 'tax_amount' => $line['tax_amount'],
                    'line_total' => $line['line_total'], 'item_snapshot' => $line['item_snapshot'], 'uom_snapshot' => $line['uom_snapshot'],
                ]);
            }
            $audit->record($before ? 'pos.sales-intake.updated' : 'pos.sales-intake.created', $x, $before, $x->fresh()->toArray(), $r->user(), $r);

            return $x->fresh();
        });

        return response()->json(['status' => true, 'redirect' => route('pos.sales-intakes.show', $x)]);
    }

    public function show(Request $r, SalesIntake $salesIntake): View
    {
        $x = $this->scope($r, $salesIntake)->load('lines.item', 'lines.uom', 'party', 'rfq.quotation.order.physicalSales', 'rfq.order.physicalSales', 'quotation.order.physicalSales', 'order.physicalSales', 'preparedBy');
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $x->getMorphClass())->where('subject_id', $x->id)->latest()->get();

        return view('Pos::sales-intakes.show', ['x' => $x, 'history' => $history, 'decimalPlaces' => (int) ($x->tax_decimal_places ?? WmsDecimal::places()), 'flowDocuments' => SalesDocumentTrail::for($x)]);
    }

    public function toRfq(Request $r, SalesIntake $salesIntake, DocumentSequenceService $seq, AuditLogger $audit): JsonResponse
    {
        $rfq = DB::transaction(function () use ($r, $salesIntake, $seq, $audit) {
            $intake = SalesIntake::query()->with('lines')->lockForUpdate()->findOrFail($salesIntake->id);
            abort_unless((int) $intake->branch_id === $this->branchId(), 404);
            $existing = $intake->rfq()->first();
            if ($existing) {
                return $existing;
            }
            abort_unless(in_array($intake->status, ['DRAFT', 'COMPLETED'], true) && $intake->requires_rfq, 422);
            if ($intake->status === 'DRAFT') {
                $before = $intake->toArray();
                $intake->status = 'COMPLETED';
                $intake->save();
                $audit->record('pos.sales-intake.completed', $intake, $before, $intake->toArray(), $r->user(), $r);
            }
            $party = Party::query()->lockForUpdate()->findOrFail($intake->party_id);
            abort_unless($party->is_active && PartyRole::query()->where(['party_id' => $party->id, 'role' => 'CUSTOMER', 'is_active' => true])->exists(), 422);
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => 'SALES_RFQ', 'is_active' => true])->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร RFQ']);
            }
            $date = $intake->document_date instanceof Carbon ? $intake->document_date : Carbon::parse($intake->document_date);
            $rfq = SalesRfq::create([
                'warehouse_id' => $intake->warehouse_id,
                'document_number' => $seq->issueAvailableForBranch($sequence, $r->attributes->get('selectedBranch'), $date, fn (string $number): bool => SalesRfq::query()
                    ->where('document_number', $number)->exists()),
                'source_sales_intake_id' => $intake->id,
                'party_id' => $intake->party_id,
                'party_code' => $intake->party_code,
                'party_name' => $intake->party_name,
                'party_tax_id' => $intake->party_tax_id,
                'party_branch_code' => $intake->party_branch_code,
                'party_address' => $intake->party_address,
                'document_date' => $date,
                'valid_until' => $date->copy()->addDays(30),
                'status' => 'WAIT',
                'subtotal' => $intake->subtotal,
                'discount_amount' => $intake->discount_amount,
                'promotion_snapshot' => $intake->promotion_snapshot,
                'promotion_discount_amount' => $intake->promotion_discount_amount,
                'total_amount' => $intake->grand_total,
                'description' => $intake->description,
                'created_by' => $r->user()->id,
                'updated_by' => $r->user()->id,
            ]);
            $seq->recordIssued($sequence, $rfq->document_number, 'sales_rfqs', (int) $rfq->id, $date, $r->user()->id);
            foreach ($intake->lines as $line) {
                $rfq->lines()->create([
                    'line_number' => $line->line_number,
                    'item_id' => $line->item_id,
                    'uom_id' => $line->uom_id,
                    'description' => $line->description ?: (data_get($line->item_snapshot, 'name') ?: 'รายการสินค้า'),
                    'quantity' => $line->quantity,
                    'proposed_unit_price' => $line->requested_unit_price ?? $line->standard_unit_price ?? '0',
                    'proposed_discount_amount' => $line->discount_amount ?? '0',
                    'line_total' => $line->line_total,
                    'pricing_snapshot' => $line->pricing_snapshot,
                    'promotion_discount_amount' => $line->promotion_discount_amount,
                    'item_snapshot' => $line->item_snapshot,
                    'uom_snapshot' => $line->uom_snapshot,
                ]);
            }
            $audit->record('pos.sales-intake.converted-to-rfq', $intake, $intake->toArray(), $rfq->fresh()->toArray(), $r->user(), $r);

            return $rfq;
        });

        return response()->json(['status' => true, 'redirect' => route('pos.sales-rfqs.show', $rfq)]);
    }

    public function complete(Request $r, SalesIntake $salesIntake, AuditLogger $a): JsonResponse
    {
        return $this->transition($r, $salesIntake, 'COMPLETED', $a);
    }

    public function cancel(Request $r, SalesIntake $salesIntake, AuditLogger $a): JsonResponse
    {
        $r->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->transition($r, $salesIntake, 'CANCELLED', $a);
    }

    private function transition(Request $r, SalesIntake $x, string $status, AuditLogger $a): JsonResponse
    {
        $x = $this->scope($r, $x);
        DB::transaction(function () use ($r, $x, $status, $a) {
            $x = SalesIntake::query()->lockForUpdate()->findOrFail($x->id);
            abort_unless($x->status === 'DRAFT', 403);
            $before = $x->toArray();
            $x->status = $status;
            $x->save();
            $a->record('pos.sales-intake.'.strtolower($status), $x, $before, $x->toArray(), $r->user(), $r);
        });

        return response()->json(['status' => true]);
    }

    public function destroy(Request $r, SalesIntake $salesIntake, AuditLogger $a): JsonResponse
    {
        $salesIntake = $this->scope($r, $salesIntake);
        DB::transaction(function () use ($r, $salesIntake, $a) {
            $x = SalesIntake::query()->lockForUpdate()->findOrFail($salesIntake->id);
            abort_unless($x->status === 'DRAFT', 403);
            $before = $x->toArray();
            $x->delete();
            $a->record('pos.sales-intake.deleted', $x, $before, [], $r->user(), $r);
        });

        return response()->json(['status' => true]);
    }

    private function resolveLinePromotion(PromotionResolver $resolver, int $itemId, Carbon $date, string $quantity, Party $party, ?int $promotionId = null): ?array
    {
        $item = Item::query()->with('baseUom')->where('is_active', true)->findOrFail($itemId);
        abort_unless($item->baseUom, 422, 'สินค้ายังไม่ได้ตั้งค่าหน่วย Stock');

        return $resolver->resolve($item->id, $item->baseUom->id, $this->customerGroupCode($party), $date, $quantity, 'THB', $promotionId);
    }

    /** @return array<int, array> */
    private function resolveLinePromotions(PromotionResolver $resolver, int $itemId, Carbon $date, string $quantity, Party $party): array
    {
        $item = Item::query()->with('baseUom')->where('is_active', true)->findOrFail($itemId);
        abort_unless($item->baseUom, 422, 'สินค้ายังไม่ได้ตั้งค่าหน่วย Stock');

        return $resolver->resolveAll($item->id, $item->baseUom->id, $this->customerGroupCode($party), $date, $quantity, 'THB');
    }

    private function resolveDocumentPromotion(?int $promotionId, Party $party, Carbon $date): ?array
    {
        if (! $promotionId) {
            return null;
        }
        $promotion = app(PromotionResolver::class)->resolveDocument($this->customerGroupCode($party), $date, 'THB', $promotionId);
        if (! $promotion) {
            throw ValidationException::withMessages(['document_promotion_id' => 'Promotion ท้ายบิลที่เลือกไม่เข้าเงื่อนไขหรือหมดอายุแล้ว']);
        }

        return $promotion;
    }

    /** Applies the document promotion after every line-level price and discount, before VAT is calculated. */
    private function applyDocumentPromotionDiscount(array $lines, ?array $promotion): array
    {
        if (! $promotion) {
            return [$lines, '0.00'];
        }

        $eligible = [];
        foreach ($lines as $line) {
            $net = BigDecimal::of((string) $line['quantity'])
                ->multipliedBy((string) $line['unit_price'])
                ->minus((string) $line['discount_amount']);
            if ($net->isPositive()) {
                $eligible[] = ['line_number' => $line['line_number'], 'amount' => $net->toScale(2, RoundingMode::HALF_UP)->__toString()];
            }
        }

        try {
            $allocation = DocumentPromotionDiscountAllocator::allocate($eligible, $promotion);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['document_promotion_id' => $exception->getMessage()]);
        }
        $amounts = collect($allocation['allocations'])->pluck('discount_amount', 'line_number');
        foreach ($lines as &$line) {
            $discount = (string) ($amounts[$line['line_number']] ?? '0.00');
            $line['discount_amount'] = BigDecimal::of((string) $line['discount_amount'])->plus($discount)->__toString();
            $line['promotion_discount_amount'] = BigDecimal::of((string) $line['promotion_discount_amount'])->plus($discount)->__toString();
            $line['pricing_snapshot'] = [...($line['pricing_snapshot'] ?? []), 'document_promotion' => [
                'promotion_id' => $promotion['promotion_id'], 'promotion_code' => $promotion['promotion_code'], 'discount_amount' => $discount,
            ]];
        }
        unset($line);

        return [$lines, $allocation['discount_amount']];
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

    private function scope(Request $r, SalesIntake $x): SalesIntake
    {
        abort_unless((int) $x->branch_id === $this->branchId(), 404);

        return $x;
    }

    private function wid(): int
    {
        return (int) request()->attributes->get('selectedWarehouse')->id;
    }

    private function branchId(): int
    {
        return (int) request()->attributes->get('selectedBranch')->id;
    }

    private function progress(SalesIntake $intake): array
    {
        if ($intake->status === 'CANCELLED') {
            return ['label' => 'ยกเลิก', 'badge' => 'danger'];
        }

        $order = $intake->order ?? $intake->quotation?->order ?? $intake->rfq?->quotation?->order ?? $intake->rfq?->order;
        if ($sale = $order?->physicalSales?->sortByDesc('id')->first()) {
            return $this->documentProgress($sale->document_type, $sale->status);
        }
        if ($order) {
            return $this->documentProgress('SO', $order->status);
        }
        if ($quotation = $intake->quotation ?? $intake->rfq?->quotation) {
            return $this->documentProgress('ใบเสนอราคา', $quotation->status);
        }
        if ($rfq = $intake->rfq) {
            return $this->documentProgress('RFQ', $rfq->status);
        }

        return ['label' => $intake->requires_rfq ? 'รอสร้าง RFQ' : 'พร้อมสร้างเอกสาร', 'badge' => $intake->requires_rfq ? 'warning' : 'info'];
    }

    private function quotationActionUrl(SalesIntake $intake): ?string
    {
        if (! in_array($intake->status, ['DRAFT', 'COMPLETED'], true)) {
            return null;
        }
        if (! $intake->requires_rfq && ! $intake->quotation && ! $intake->order) {
            return route('pos.sales-quotations.from-intake', $intake);
        }
        if ($intake->requires_rfq && $intake->rfq?->status === 'APPROVED' && ! $intake->rfq->quotation && ! $intake->rfq->order) {
            return route('pos.sales-quotations.from-rfq', $intake->rfq);
        }

        return null;
    }

    private function orderActionUrl(SalesIntake $intake): ?string
    {
        if (! in_array($intake->status, ['DRAFT', 'COMPLETED'], true)) {
            return null;
        }
        if (! $intake->requires_rfq && ! $intake->quotation && ! $intake->order) {
            return route('pos.sales-orders.from-intake', $intake);
        }
        if ($intake->requires_rfq && $intake->rfq?->status === 'APPROVED' && ! $intake->rfq->quotation && ! $intake->rfq->order) {
            return route('pos.sales-orders.from-rfq', $intake->rfq);
        }

        return null;
    }

    private function documentProgress(string $document, string $status): array
    {
        $labels = ['DRAFT' => 'ร่าง', 'WAIT' => 'รออนุมัติ', 'SENT' => 'ส่งแล้ว', 'APPROVED' => 'อนุมัติแล้ว', 'ACCEPTED' => 'ตอบรับแล้ว', 'CONFIRMED' => 'ยืนยันแล้ว', 'FULFILLED' => 'ดำเนินการแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'REJECTED' => 'ไม่อนุมัติ', 'CANCELLED' => 'ยกเลิก', 'VOID' => 'ยกเลิก'];
        $badges = ['DRAFT' => 'soft', 'WAIT' => 'warning', 'SENT' => 'info', 'APPROVED' => 'success', 'ACCEPTED' => 'success', 'CONFIRMED' => 'success', 'FULFILLED' => 'success', 'POSTED' => 'success', 'REJECTED' => 'danger', 'CANCELLED' => 'danger', 'VOID' => 'danger'];

        return ['label' => $document.' · '.($labels[$status] ?? $status), 'badge' => $badges[$status] ?? 'soft'];
    }

    private function search(Builder $q, Request $r): void
    {
        $s = trim((string) $r->input('search.value'));
        if ($s) {
            $q->where(fn ($x) => $x->where('document_number', 'like', "%$s%")->orWhere('party_code', 'like', "%$s%")->orWhere('party_name', 'like', "%$s%"));
        }
    }
}
