<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\BillingNote;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesDocument;
use App\Modules\Pos\Requests\SaveBillingNoteRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class BillingNoteController extends Controller
{
    public function index(): View { return view('Pos::billing-notes.index'); }

    public function data(Request $request): JsonResponse
    {
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $query = BillingNote::query()->with('party')->withCount('lines')->where('branch_id', $branchId)->latest('document_date')->latest('id');
        $query->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()));
        $query->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('document_date', '>=', $request->date('date_from')));
        $query->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('document_date', '<=', $request->date('date_to')));

        return DataTables::eloquent($query)
            ->addColumn('party_label', fn (BillingNote $note) => trim(($note->party?->code ?: '').' · '.($note->party?->name ?: ''), ' ·') ?: '-')
            ->addColumn('status_label', fn (BillingNote $note) => ['DRAFT' => 'ร่าง', 'ISSUED' => 'ออกใบวางบิลแล้ว', 'CANCELLED' => 'ยกเลิก'][$note->status] ?? $note->status)
            ->addColumn('show_url', fn (BillingNote $note) => route('pos.billing-notes.show', $note))
            ->addColumn('can_issue', fn (BillingNote $note) => $note->status === 'DRAFT' && $request->user()->hasPermission('pos.billing-notes.issue'))
            ->addColumn('can_cancel', fn (BillingNote $note) => in_array($note->status, ['DRAFT', 'ISSUED'], true) && $request->user()->hasPermission('pos.billing-notes.cancel'))
            ->toJson();
    }

    public function create(Request $request): View
    {
        return view('Pos::billing-notes.form', ['parties' => collect(), 'documentDate' => today()->toDateString()]);
    }

    public function partyOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $rows = Party::query()->join('party_roles', fn ($join) => $join->on('party_roles.party_id', '=', 'parties.id')->where('party_roles.role', 'CUSTOMER')->where('party_roles.is_active', true))
            ->where('parties.is_active', true)
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('parties.code', 'like', "%{$q}%")->orWhere('parties.name', 'like', "%{$q}%")))
            ->orderBy('parties.code')->limit(31)->get(['parties.id', 'parties.code', 'parties.name']);

        return response()->json(['results' => $rows->take(30)->map(fn (Party $party) => ['id' => $party->id, 'text' => $party->code.' · '.$party->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function invoiceOptions(Request $request, OpenItemService $openItems): JsonResponse
    {
        $partyId = $request->integer('party_id');
        abort_unless($partyId > 0, 422, 'กรุณาเลือกลูกค้าก่อน');
        $term = trim((string) $request->input('q', ''));
        $invoices = $this->eligibleInvoices($request, $partyId, $term)->limit(31)->get();
        $physicalSales = $this->eligiblePhysicalSales($request, $partyId, $term)->limit(31)->get();
        $results = collect($invoices->map(function (SalesDocument $invoice) use ($openItems): array {
            $openItem = $this->openItemFor($invoice);
            $remaining = $openItem ? $openItems->remainingAt($openItem, today()->toDateString()) : '0.00';

            return ['id' => 'SALES_DOCUMENT:'.$invoice->id, 'text' => 'Invoice '.$invoice->document_number.' · '.$invoice->document_date->format('d/m/Y').' · คงเหลือ '.number_format((float) $remaining, 2), 'amount' => $remaining];
        })->all())->merge($physicalSales->map(function (PhysicalSale $sale) use ($openItems): array {
            $openItem = $this->openItemFor($sale);
            $remaining = $openItem ? $openItems->remainingAt($openItem, today()->toDateString()) : '0.00';

            return ['id' => 'PHYSICAL_SALE:'.$sale->id, 'text' => 'ใบขายเชื่อ '.$sale->document_number.' · '.$sale->document_date->format('d/m/Y').' · คงเหลือ '.number_format((float) $remaining, 2), 'amount' => $remaining];
        }))->filter(fn (array $row) => (float) $row['amount'] > 0)->sortByDesc('id')->take(30)->values();

        return response()->json(['results' => $results, 'pagination' => ['more' => $invoices->count() > 30 || $physicalSales->count() > 30]]);
    }

    public function store(SaveBillingNoteRequest $request, DocumentSequenceService $sequences, OpenItemService $openItems, AuditLogger $audit): JsonResponse
    {
        $note = DB::transaction(function () use ($request, $sequences, $openItems, $audit): BillingNote {
            $values = $request->validated();
            $keys = $values['billing_source_keys'];
            $salesDocumentIds = collect($keys)->filter(fn (string $key) => str_starts_with($key, 'SALES_DOCUMENT:'))->map(fn (string $key) => (int) substr($key, strpos($key, ':') + 1))->values();
            $physicalSaleIds = collect($keys)->filter(fn (string $key) => str_starts_with($key, 'PHYSICAL_SALE:'))->map(fn (string $key) => (int) substr($key, strpos($key, ':') + 1))->values();
            $invoices = $this->eligibleInvoices($request, (int) $values['party_id'])->whereIn('id', $salesDocumentIds)->lockForUpdate()->get();
            $physicalSales = $this->eligiblePhysicalSales($request, (int) $values['party_id'])->whereIn('id', $physicalSaleIds)->lockForUpdate()->get();
            if ($invoices->count() + $physicalSales->count() !== count($keys)) {
                throw ValidationException::withMessages(['billing_source_keys' => 'มีเอกสารที่ไม่ใช่ของลูกค้าหรือถูกวางบิลไปแล้ว']);
            }
            $lines = collect($invoices->map(function (SalesDocument $invoice) use ($openItems): array {
                $openItem = $this->openItemFor($invoice);
                $amount = $openItem ? $openItems->remainingAt($openItem, today()->toDateString()) : '0.00';
                if ((float) $amount <= 0) throw ValidationException::withMessages(['billing_source_keys' => 'Invoice ต้องมียอดคงค้างมากกว่า 0']);
                return ['sales_document_id' => $invoice->id, 'amount' => $amount];
            })->all())->merge($physicalSales->map(function (PhysicalSale $sale) use ($openItems): array {
                $openItem = $this->openItemFor($sale);
                $amount = $openItem ? $openItems->remainingAt($openItem, today()->toDateString()) : '0.00';
                if ((float) $amount <= 0) throw ValidationException::withMessages(['billing_source_keys' => 'ใบขายเชื่อต้องมียอดคงค้างมากกว่า 0']);
                return ['physical_sale_id' => $sale->id, 'amount' => $amount];
            }));
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'BILLING_NOTE')->where('is_active', true)->first();
            if (! $sequence) throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบวางบิล']);
            $branch = $request->attributes->get('selectedBranch');
            $note = BillingNote::query()->create(['branch_id' => $branch->id, 'party_id' => $values['party_id'], 'document_number' => $sequences->issueForBranch($sequence, $branch, Carbon::parse($values['document_date'])), 'document_date' => $values['document_date'], 'due_date' => $values['due_date'] ?? null, 'total_amount' => $lines->sum('amount'), 'description' => $values['description'] ?? null, 'status' => 'DRAFT', 'created_by' => $request->user()->id]);
            $note->lines()->createMany($lines->all());
            $sequences->recordIssued($sequence, $note->document_number, 'pos_billing_notes', (int) $note->id, Carbon::parse($note->document_date), $request->user()->id);
            $audit->record('pos.billing_note.created', $note->load('lines'), [], $note->toArray(), $request->user(), $request);
            return $note;
        });

        return response()->json(['status' => true, 'msg' => "สร้างร่างใบวางบิล {$note->document_number} แล้ว", 'redirect' => route('pos.billing-notes.show', $note)]);
    }

    public function show(Request $request, BillingNote $billingNote): View
    {
        $this->scope($request, $billingNote);
        return view('Pos::billing-notes.show', ['billingNote' => $billingNote->load('party', 'lines.salesDocument', 'lines.physicalSale'), 'dateFormat' => 'd/m/Y']);
    }

    public function issue(Request $request, BillingNote $billingNote, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $billingNote);
        abort_unless($billingNote->status === 'DRAFT', 422, 'ออกใบวางบิลได้เฉพาะเอกสารร่าง');
        $before = $billingNote->toArray();
        $billingNote->update(['status' => 'ISSUED', 'issued_by' => $request->user()->id, 'issued_at' => now()]);
        $audit->record('pos.billing_note.issued', $billingNote, $before, $billingNote->fresh()->toArray(), $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'ออกใบวางบิลแล้ว']);
    }

    public function cancel(Request $request, BillingNote $billingNote, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $billingNote);
        abort_unless(in_array($billingNote->status, ['DRAFT', 'ISSUED'], true), 422, 'ยกเลิกใบวางบิลสถานะนี้ไม่ได้');
        $reason = trim((string) $request->input('reason'));
        abort_if(mb_strlen($reason) < 5, 422, 'กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร');
        $before = $billingNote->toArray();
        $billingNote->update(['status' => 'CANCELLED', 'cancelled_by' => $request->user()->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
        $audit->record('pos.billing_note.cancelled', $billingNote, $before, $billingNote->fresh()->toArray(), $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'ยกเลิกใบวางบิลแล้ว']);
    }

    private function eligibleInvoices(Request $request, int $partyId, string $term = '')
    {
        return SalesDocument::query()->where('branch_id', $request->attributes->get('selectedBranch')->id)->where('party_id', $partyId)->where('document_type', 'INVOICE')->where('status', 'POSTED')->when($term !== '', fn (Builder $query) => $query->where('document_number', 'like', "%{$term}%"))->whereDoesntHave('billingNoteLines', fn (Builder $query) => $query->whereHas('billingNote', fn (Builder $note) => $note->whereIn('status', ['DRAFT', 'ISSUED'])))->orderByDesc('document_date')->orderByDesc('id');
    }

    private function eligiblePhysicalSales(Request $request, int $partyId, string $term = '')
    {
        return PhysicalSale::query()->where('branch_id', $request->attributes->get('selectedBranch')->id)->where('party_id', $partyId)->where('document_type', 'IV')->where('status', 'POSTED')->when($term !== '', fn (Builder $query) => $query->where('document_number', 'like', "%{$term}%"))->whereDoesntHave('billingNoteLines', fn (Builder $query) => $query->whereHas('billingNote', fn (Builder $note) => $note->whereIn('status', ['DRAFT', 'ISSUED'])))->orderByDesc('document_date')->orderByDesc('id');
    }

    private function openItemFor(SalesDocument|PhysicalSale $invoice): ?OpenItem
    {
        return OpenItem::query()->where('warehouse_id', $invoice->warehouse_id)->where('party_id', $invoice->party_id)->where('ledger_type', 'AR')->where('party_type', 'CUSTOMER')->where('balance_side', 'DEBIT')->where('document_type', 'INVOICE')->where('document_number', $invoice->document_number)->whereHas('journalEntryLine', fn (Builder $query) => $query->where('journal_entry_id', $invoice->journal_entry_id))->first();
    }

    private function scope(Request $request, BillingNote $billingNote): void
    {
        abort_unless((int) $billingNote->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
    }
}
