<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Party;
use App\Models\PartyRole;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\SalesRfq;
use App\Modules\Pos\Models\SalesRfqLine;
use App\Modules\Pos\Requests\SaveSalesRfqRequest;
use App\Modules\Pos\Support\SalesDocumentTrail;
use App\Modules\Pos\Support\SalesRfqState;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
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

class SalesRfqController extends Controller
{
    public function index(): View
    {
        return view('Pos::sales-rfqs.index');
    }

    public function data(Request $r): JsonResponse
    {
        $q = SalesRfq::query()->where('branch_id', $this->branchId())->withCount('lines')->with(['quotation:id,sales_rfq_id,document_number', 'quotation.order:id,sales_quotation_id,document_number', 'order:id,sales_rfq_id,document_number']);
        foreach (['date_from' => '>=', 'date_to' => '<='] as $f => $op) {
            if ($r->filled($f)) {
                $q->whereDate('document_date', $op, $r->input($f));
            }
        }$q->when($r->filled('status'), fn ($x) => $x->where('status', $r->input('status')))->when($r->filled('party_id'), fn ($x) => $x->where('party_id', $r->input('party_id')));

        return DataTables::eloquent($q)->filter(fn (Builder $x) => $this->search($x, $r))->order(fn (Builder $x) => $x->orderByDesc('document_date')->orderByDesc('id'))->addColumn('party_label', fn (SalesRfq $x) => $x->party_code.' · '.$x->party_name)->addColumn('status_label', fn (SalesRfq $x) => ['WAIT' => 'รอพิจารณา', 'APPROVED' => 'อนุมัติแล้ว', 'REJECTED' => 'ไม่อนุมัติ', 'CANCELLED' => 'ยกเลิก'][$x->status] ?? $x->status)->addColumn('show_url', fn ($x) => route('pos.sales-rfqs.show', $x))->addColumn('pdf_url', fn ($x) => route('pos.sales-rfqs.pdf', $x))->addColumn('quotation_url', fn (SalesRfq $x) => $x->quotation ? route('pos.sales-quotations.show', $x->quotation) : null)->addColumn('order_url', fn (SalesRfq $x) => $x->order ? route('pos.sales-orders.show', $x->order) : ($x->quotation?->order ? route('pos.sales-orders.show', $x->quotation->order) : null))->addColumn('cancel_url', fn ($x) => $x->status === 'WAIT' && $r->user()->hasPermission('pos.sales-rfqs.cancel') ? route('pos.sales-rfqs.cancel', $x) : null)->toJson();
    }

    public function partyOptions(Request $r): JsonResponse
    {
        $q = trim((string) $r->input('q'));
        $p = max(1, $r->integer('page', 1));
        $rows = Party::query()->join('party_roles', fn ($j) => $j->on('party_roles.party_id', '=', 'parties.id')->where('party_roles.role', 'CUSTOMER')->where('party_roles.is_active', true))->where('parties.is_active', true)->when($q, fn ($x) => $x->where(fn ($x) => $x->where('parties.code', 'like', "%$q%")->orWhere('parties.name', 'like', "%$q%")))->select('parties.id', 'parties.code', 'parties.name')->distinct()->orderBy('parties.code')->forPage($p, 31)->get();

        return response()->json(['results' => $rows->take(30)->map(fn ($x) => ['id' => $x->id, 'text' => $x->code.' · '.$x->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function itemOptions(Request $r): JsonResponse
    {
        return $this->options(Item::query()->where('is_active', true), $r, ['id', 'code', 'name', 'base_uom_id'], fn ($x) => ['id' => $x->id, 'text' => $x->code.' · '.$x->name, 'uom_id' => $x->base_uom_id]);
    }

    public function uomOptions(Request $r): JsonResponse
    {
        return $this->options(Uom::query()->where('is_active', true), $r, ['id', 'code', 'name'], fn ($x) => ['id' => $x->id, 'text' => $x->code.' · '.$x->name]);
    }

    private function options(Builder $q, Request $r, array $cols, callable $map): JsonResponse
    {
        $s = trim((string) $r->input('q'));
        $p = max(1, $r->integer('page', 1));
        $rows = $q->when($s, fn ($x) => $x->where(fn ($x) => $x->where('code', 'like', "%$s%")->orWhere('name', 'like', "%$s%")))->orderBy('code')->forPage($p, 31)->get($cols);

        return response()->json(['results' => $rows->take(30)->map($map)->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function create(): View
    {
        $d = now()->toDateString();

        return view('Pos::sales-rfqs.form', ['rfq' => new SalesRfq(['document_date' => $d, 'valid_until' => now()->addDays(30)->toDateString()]), 'lines' => [new SalesRfqLine(['line_number' => 1, 'quantity' => '1.0000'])], 'party' => null]);
    }

    public function store(SaveSalesRfqRequest $r, DocumentSequenceService $s, AuditLogger $a): JsonResponse
    {
        $x = $this->save($r, new SalesRfq, $s, $a);

        return response()->json(['status' => true, 'redirect' => route('pos.sales-rfqs.show', $x)]);
    }

    public function edit(Request $r, SalesRfq $salesRfq): View
    {
        $salesRfq = $this->scope($r, $salesRfq)->load('lines', 'party', 'quotation', 'order');
        abort_unless($salesRfq->status === 'DRAFT', 403);

        return view('Pos::sales-rfqs.form', ['rfq' => $salesRfq, 'lines' => $salesRfq->lines, 'party' => $salesRfq->party]);
    }

    public function update(SaveSalesRfqRequest $r, SalesRfq $salesRfq, DocumentSequenceService $s, AuditLogger $a): JsonResponse
    {
        $salesRfq = $this->scope($r, $salesRfq);
        abort_unless($salesRfq->status === 'DRAFT', 403);
        $salesRfq = $this->save($r, $salesRfq, $s, $a);

        return response()->json(['status' => true, 'redirect' => route('pos.sales-rfqs.show', $salesRfq)]);
    }

    public function show(Request $r, SalesRfq $salesRfq): View
    {
        $salesRfq = $this->scope($r, $salesRfq)->load('lines.item', 'lines.uom', 'party', 'sourceIntake.preparedBy', 'quotation.order.physicalSales', 'order.physicalSales');
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $salesRfq->getMorphClass())->where('subject_id', $salesRfq->id)->latest()->get();

        return view('Pos::sales-rfqs.show', ['x' => $salesRfq, 'history' => $history, 'flowDocuments' => SalesDocumentTrail::for($salesRfq)]);
    }

    public function review(Request $r, SalesRfq $salesRfq): View
    {
        $x = $this->scope($r, $salesRfq)->load('lines');
        abort_unless($x->status === 'WAIT', 403);

        return view('Pos::sales-rfqs.review', compact('x'));
    }

    public function decide(Request $r, SalesRfq $salesRfq, AuditLogger $audit): JsonResponse
    {
        $data = $r->validate(['decision' => ['required', 'in:APPROVED,REJECTED'], 'reason' => ['required', 'string', 'min:10', 'max:500'], 'lines' => ['required', 'array'], 'lines.*.id' => ['required', 'integer'], 'lines.*.estimated_unit_cost' => ['nullable', 'numeric', 'decimal:0,4', 'min:0']]);
        DB::transaction(function () use ($r, $salesRfq, $audit, $data): void {
            $x = SalesRfq::query()->with('lines')->lockForUpdate()->findOrFail($this->scope($r, $salesRfq)->id);
            $before = $x->toArray();
            $input = collect($data['lines'])->keyBy('id');
            if ($data['decision'] === 'APPROVED') {
                SalesRfqState::approve($x->status);
                foreach ($x->lines as $line) {
                    $cost = $input->get($line->id)['estimated_unit_cost'] ?? null;
                    if ($cost === null) {
                        throw ValidationException::withMessages(['lines' => 'กรุณากรอกต้นทุนประเมินให้ครบทุกรายการก่อนอนุมัติ']);
                    }
                    $sales = BigDecimal::of($line->quantity)->multipliedBy($line->proposed_unit_price)->minus($line->proposed_discount_amount)->toScale(2, RoundingMode::HALF_UP);
                    $totalCost = BigDecimal::of($line->quantity)->multipliedBy($cost)->toScale(2, RoundingMode::HALF_UP);
                    $margin = $sales->minus($totalCost)->toScale(2, RoundingMode::HALF_UP);
                    $line->update(['estimated_unit_cost' => $cost, 'estimated_cost_amount' => (string) $totalCost, 'estimated_margin_amount' => (string) $margin, 'estimated_margin_percent' => $sales->isZero() ? '0.0000' : (string) $margin->dividedBy($sales, 4, RoundingMode::HALF_UP)->multipliedBy(100)]);
                }
                $x->status = 'APPROVED';
            } else {
                SalesRfqState::reject($x->status);
                $x->status = 'REJECTED';
            }
            $x->reviewed_by = $r->user()->id;
            $x->reviewed_at = now();
            $x->review_reason = $data['reason'];
            $x->save();
            $audit->record('pos.sales-rfq.'.strtolower($x->status), $x, $before, $x->toArray(), $r->user(), $r);
        });

        return response()->json(['status' => true, 'msg' => 'บันทึกผลการพิจารณาแล้ว', 'redirect' => route('pos.sales-rfqs.show', $salesRfq)]);
    }

    public function send(Request $r, SalesRfq $salesRfq, AuditLogger $a): JsonResponse
    {
        return $this->transition($r, $salesRfq, 'send', $a);
    }

    public function close(Request $r, SalesRfq $salesRfq, AuditLogger $a): JsonResponse
    {
        return $this->transition($r, $salesRfq, 'close', $a);
    }

    public function cancel(Request $r, SalesRfq $salesRfq, AuditLogger $a): JsonResponse
    {
        $r->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->transition($r, $salesRfq, 'cancel', $a);
    }

    private function save(SaveSalesRfqRequest $r, SalesRfq $x, DocumentSequenceService $seq, AuditLogger $audit): SalesRfq
    {
        return DB::transaction(function () use ($r, $x, $seq, $audit) {
            $d = $r->validated();
            $party = Party::query()->lockForUpdate()->findOrFail($d['party_id']);
            abort_unless($party->is_active && PartyRole::query()->where(['party_id' => $party->id, 'role' => 'CUSTOMER', 'is_active' => true])->exists(), 422);
            $date = Carbon::parse($d['document_date']);
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => 'SALES_RFQ', 'is_active' => true])->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร RFQ']);
            }$before = $x->exists ? $x->toArray() : [];
            if (! $x->exists) {
                $x->warehouse_id = $this->warehouseId();
                $x->created_by = $r->user()->id;
                $x->document_number = $seq->issueAvailableForBranch($sequence, $r->attributes->get('selectedBranch'), $date, fn (string $number): bool => SalesRfq::query()
                    ->where('document_number', $number)->exists());
            } elseif ((string) $x->document_date !== (string) $d['document_date']) {
                $x->document_number = $seq->replaceDraftNumberForBranch($sequence, $r->attributes->get('selectedBranch'), $x->document_number, 'sales_rfqs', (int) $x->id, $date, $r->user()->id);
            }$x->fill(['party_id' => $party->id, 'party_code' => $party->code, 'party_name' => $party->name, 'party_tax_id' => $party->tax_id, 'party_branch_code' => $party->branch_code, 'party_address' => $party->address, 'document_date' => $d['document_date'], 'valid_until' => $d['valid_until'], 'description' => $d['description'] ?? null, 'updated_by' => $r->user()->id])->save();
            if ($x->wasRecentlyCreated) {
                $seq->recordIssued($sequence, $x->document_number, 'sales_rfqs', (int) $x->id, $date, $r->user()->id);
            }$x->lines()->delete();
            foreach ($d['lines'] as $i => $line) {
                $item = ! empty($line['item_id']) ? Item::query()->where('is_active', true)->find($line['item_id']) : null;
                $uom = ! empty($line['uom_id']) ? Uom::query()->where('is_active', true)->find($line['uom_id']) : null;
                if ($line['item_id'] && ! $item) {
                    throw ValidationException::withMessages(["lines.$i.item_id" => 'สินค้าไม่พร้อมใช้งาน']);
                }$x->lines()->create(['line_number' => $i + 1, 'item_id' => $item?->id, 'uom_id' => $uom?->id, 'description' => $line['description'] ?? null, 'quantity' => $line['quantity'], 'item_snapshot' => $item?->only(['code', 'name']), 'uom_snapshot' => $uom?->only(['code', 'name'])]);
            }$audit->record($before ? 'pos.sales-rfq.updated' : 'pos.sales-rfq.created', $x, $before, $x->fresh()->toArray(), $r->user(), $r);

            return $x->fresh();
        });
    }

    private function transition(Request $r, SalesRfq $x, string $action, AuditLogger $audit): JsonResponse
    {
        $x = $this->scope($r, $x);
        DB::transaction(function () use ($r, $x, $action, $audit) {
            $x = SalesRfq::query()->lockForUpdate()->findOrFail($x->id);
            $before = $x->toArray();
            SalesRfqState::$action($x);
            if ($action === 'cancel') {
                $x->cancelled_by = $r->user()->id;
                $x->cancelled_at = now();
                $x->cancel_reason = $r->input('reason');
            } elseif ($action === 'send') {
                $x->sent_by = $r->user()->id;
                $x->sent_at = now();
            } else {
                $x->closed_by = $r->user()->id;
                $x->closed_at = now();
            }$x->save();
            $audit->record('pos.sales-rfq.'.$action, $x, $before, $x->toArray(), $r->user(), $r);
        });

        return response()->json(['status' => true, 'msg' => 'ดำเนินการแล้ว']);
    }

    private function scope(Request $r, SalesRfq $x): SalesRfq
    {
        abort_unless((int) $x->branch_id === $this->branchId(), 404);

        return $x;
    }

    private function warehouseId(): int
    {
        return (int) request()->attributes->get('selectedWarehouse')->id;
    }

    private function branchId(): int
    {
        return (int) request()->attributes->get('selectedBranch')->id;
    }

    private function search(Builder $q, Request $r): void
    {
        $s = trim((string) $r->input('search.value'));
        if ($s) {
            $q->where(fn ($x) => $x->where('document_number', 'like', "%$s%")->orWhere('party_code', 'like', "%$s%")->orWhere('party_name', 'like', "%$s%"));
        }
    }
}
