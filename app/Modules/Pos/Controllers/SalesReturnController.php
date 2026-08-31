<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\PhysicalSaleLine;
use App\Modules\Pos\Models\SalesReturn;
use App\Modules\Pos\Requests\SaveSalesReturnRequest;
use App\Modules\Pos\Services\SalesReturnPostingService;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Support\WmsDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class SalesReturnController extends Controller
{
    public function index(): View
    {
        return view('Pos::sales-returns.index');
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $branch = $request->attributes->get('selectedBranch');
        $format = (string) ($settings->value('date_format') ?: 'd/m/Y');

        $query = SalesReturn::query()->where('branch_id', $branch->id)
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('document_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('document_date', '<=', $request->date('date_to')))
            ->when($request->filled('status') && $request->input('status') !== 'ALL', fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('physical_sale_id'), fn (Builder $q) => $q->where('physical_sale_id', (int) $request->input('physical_sale_id')))
            ->with('sale:id,document_number,document_type');

        return DataTables::eloquent($query)
            ->order(fn (Builder $q) => $q->orderByDesc('document_date')->orderByDesc('id'))
            ->addColumn('source_label', fn (SalesReturn $x) => $x->sale?->document_number ?: '—')
            ->addColumn('document_date_label', fn (SalesReturn $x) => $x->document_date?->format($format) ?: '—')
            ->addColumn('status_label', fn (SalesReturn $x) => ['DRAFT' => 'ร่าง', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก'][$x->status] ?? $x->status)
            ->addColumn('show_url', fn (SalesReturn $x) => route('pos.sales-returns.show', $x))
            ->addColumn('pdf_url', fn (SalesReturn $x) => $request->user()?->can('pos.sales-returns.print') ? route('pos.sales-returns.pdf', $x) : null)
            ->addColumn('post_url', fn (SalesReturn $x) => $x->status === 'DRAFT' && $x->sale?->document_type !== 'HS' && $request->user()?->hasPermission('pos.sales-returns.post') ? route('pos.sales-returns.post', $x) : null)
            ->addColumn('cancel_url', fn (SalesReturn $x) => $x->status === 'DRAFT' && $request->user()?->hasPermission('pos.sales-returns.cancel') ? route('pos.sales-returns.cancel', $x) : null)
            ->toJson();
    }

    public function create(Request $request): View
    {
        return view('Pos::sales-returns.form', ['returnDocument' => new SalesReturn(['document_date' => today()]), 'quantityDecimalPlaces' => WmsDecimal::places()]);
    }

    public function saleOptions(Request $request): JsonResponse
    {
        $branch = $request->attributes->get('selectedBranch');
        $page = max(1, (int) $request->input('page', 1));
        $term = trim((string) $request->input('q', ''));
        $query = PhysicalSale::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'POSTED')
            ->where('reversal_status', 'NONE')
            ->whereNull('cancellation_return_id')
            ->when($term !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($term) {
                $inner->where('document_number', 'like', "%{$term}%")
                    ->orWhere('party_name', 'like', "%{$term}%");
            }))
            ->orderByDesc('document_date')->orderByDesc('id');
        $sales = $query->forPage($page, 31)->get(['id', 'document_number', 'document_date', 'party_name', 'total_amount']);
        $more = $sales->count() > 30;
        $results = $sales->take(30)->map(fn (PhysicalSale $sale) => [
            'id' => $sale->id,
            'text' => sprintf('%s · %s · %s · %s', $sale->document_number, $sale->party_name ?: 'ไม่ระบุลูกค้า', $sale->document_date?->format('d/m/Y') ?: '—', number_format((float) $sale->total_amount, 2)),
        ])->values();

        return response()->json(['results' => $results, 'pagination' => ['more' => $more]]);
    }

    public function sourceLineOptions(Request $request, PhysicalSale $physicalSale): JsonResponse
    {
        abort_unless((int) $physicalSale->branch_id === $this->branchId($request) && $physicalSale->status === 'POSTED' && $physicalSale->reversal_status === 'NONE' && ! $physicalSale->cancellation_return_id, 404);
        $page = max(1, (int) $request->input('page', 1));
        $lines = $physicalSale->lines()->with(['item:id,code,name', 'saleUom:id,code,name'])->forPage($page, 31)->get();
        $more = $lines->count() > 30;
        $results = $lines->take(30)->map(function (PhysicalSaleLine $line) {
            $item = $line->item_snapshot ?: [];
            $code = $item['code'] ?? $line->item?->code ?? '—';
            $name = $item['name'] ?? $line->item?->name ?? 'สินค้า';
            $uom = $line->saleUom?->code ?? $line->saleUom?->name ?? 'หน่วย';

            return [
                'id' => $line->id,
                'text' => sprintf('#%s · %s · %s · ขาย %s %s', $line->line_number, $code, $name, WmsDecimal::format($line->quantity), $uom),
                'quantity' => number_format((float) $line->quantity, WmsDecimal::places(), '.', ''),
                'unit_price' => (float) $line->unit_price,
            ];
        })->values();

        return response()->json(['document_type' => $physicalSale->document_type, 'results' => $results, 'pagination' => ['more' => $more]]);
    }

    public function store(SaveSalesReturnRequest $request, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $values = $request->validated();
        $document = DB::transaction(function () use ($values, $request, $sequences, $audit) {
            $sale = PhysicalSale::query()->where('branch_id', $this->branchId($request))->where('status', 'POSTED')->where('reversal_status', 'NONE')->whereNull('cancellation_return_id')->with('lines')->lockForUpdate()->find((int) $values['physical_sale_id']);
            if (! $sale) {
                throw ValidationException::withMessages(['physical_sale_id' => 'ต้องเลือกเอกสาร HS/IV ที่ลงบัญชีแล้ว']);
            }
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => 'SALES_RETURN', 'is_active' => true])->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['physical_sale_id' => 'ยังไม่ได้ตั้งค่าเลขที่ใบรับคืน/ใบลดหนี้']);
            }
            $sourceLines = $sale->lines->keyBy('id');
            $requested = collect($values['lines'])->groupBy('physical_sale_line_id')->map(fn ($rows) => (float) collect($rows)->sum('quantity'));
            $already = SalesReturn::query()->where('physical_sale_id', $sale->id)->whereIn('status', ['DRAFT', 'POSTED'])->with('lines')->get()->flatMap(fn (SalesReturn $return) => $return->lines)->groupBy('physical_sale_line_id')->map(fn ($rows) => (float) $rows->sum('quantity'));
            foreach ($requested as $lineId => $qty) {
                $line = $sourceLines->get((int) $lineId);
                if (! $line || $qty > ((float) $line->quantity - (float) ($already->get($line->id) ?? 0))) {
                    throw ValidationException::withMessages(['lines' => 'จำนวนคืนต้องไม่เกินจำนวนสินค้าที่ขายและคืนไปแล้ว']);
                }
            }
            $document = SalesReturn::query()->create(['warehouse_id' => $sale->warehouse_id, 'physical_sale_id' => $sale->id, 'document_number' => $sequences->issueForBranch($sequence, $request->attributes->get('selectedBranch'), Carbon::parse($values['document_date'])), 'document_date' => $values['document_date'], 'reason' => $values['reason'], 'party_code' => $sale->party_code, 'party_name' => $sale->party_name, 'party_address' => $sale->party_address, 'status' => 'DRAFT', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $total = 0.0;
            foreach ($requested as $lineId => $qty) {
                $line = $sourceLines->get((int) $lineId);
                $amount = $qty * (float) $line->unit_price;
                $stockQuantity = $qty * (float) $line->uom_factor;
                $total += $amount;
                $document->lines()->create(['physical_sale_line_id' => $line->id, 'line_number' => $line->line_number, 'item_id' => $line->item_id, 'uom_id' => $line->sale_uom_id, 'stock_uom_id' => $line->stock_uom_id, 'quantity' => $qty, 'stock_quantity' => $stockQuantity, 'uom_factor' => $line->uom_factor, 'unit_price' => $line->unit_price, 'line_total' => $amount, 'item_snapshot' => $line->item_snapshot, 'conversion_snapshot' => $line->conversion_snapshot]);
            }
            $document->update(['total_amount' => $total]);
            $sequences->recordIssued($sequence, $document->document_number, 'pos_sales_returns', $document->id, Carbon::parse($document->document_date), $request->user()->id);
            $audit->record('pos.sales-return.created', $document, [], $document->fresh()->toArray(), $request->user(), $request);

            return $document;
        });

        return response()->json(['status' => true, 'msg' => "สร้างร่าง {$document->document_number} แล้ว", 'redirect' => route('pos.sales-returns.show', $document)]);
    }

    public function show(Request $request, SalesReturn $salesReturn, GlobalSettings $settings): View
    {
        $this->ensureCurrentBranch($request, $salesReturn);
        $salesReturn->load(['sale', 'lines.item', 'lines.uom', 'refundBankAccount']);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $salesReturn->getMorphClass())->where('subject_id', $salesReturn->id)->latest('created_at')->latest('id')->get();

        return view('Pos::sales-returns.show', [
            'returnDocument' => $salesReturn,
            'history' => $history,
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
            'decimalPlaces' => (int) ($settings->value('tax_decimal_places') ?? 2),
            'bankAccounts' => $salesReturn->status === 'DRAFT' && $salesReturn->sale?->document_type === 'HS'
                ? BankAccount::query()->where('warehouse_id', $salesReturn->warehouse_id)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
                : collect(),
        ]);
    }

    public function post(Request $request, SalesReturn $salesReturn, SalesReturnPostingService $posting): JsonResponse
    {
        $values = $request->validate(['posting_date' => ['required', 'date_format:Y-m-d'], 'refund_bank_account_id' => ['nullable', 'integer', 'min:1']]);
        $this->ensureCurrentBranch($request, $salesReturn);
        $warehouse = $salesReturn->warehouse;
        $posted = $posting->post($salesReturn, $values['posting_date'], $warehouse, $request->user(), $request, $values['refund_bank_account_id'] ?? null);

        return response()->json(['status' => true, 'msg' => "Post {$posted->document_number} แล้ว"]);
    }

    public function cancel(Request $request, SalesReturn $salesReturn, AuditLogger $audit): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $this->ensureCurrentBranch($request, $salesReturn);

        DB::transaction(function () use ($salesReturn, $request, $audit): void {
            $document = SalesReturn::query()->lockForUpdate()->findOrFail($salesReturn->id);
            if ($document->status !== 'DRAFT') {
                throw ValidationException::withMessages(['sales_return' => 'ยกเลิกได้เฉพาะใบรับคืน/ลดหนี้ฉบับร่าง']);
            }
            $before = $document->only(['status', 'void_reason', 'updated_by']);
            $document->forceFill(['status' => 'VOID', 'void_reason' => $request->input('reason'), 'updated_by' => $request->user()->id])->save();
            $audit->record('pos.sales-return.voided', $document, $before, $document->only(array_keys($before)), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => "ยกเลิก {$salesReturn->document_number} แล้ว"]);
    }

    public function pdf(Request $request, SalesReturn $salesReturn, DocumentPdfRenderer $renderer, GlobalSettings $settings)
    {
        $this->ensureCurrentBranch($request, $salesReturn);
        $salesReturn->load(['sale', 'lines.item', 'lines.uom']);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $salesReturn->getMorphClass())->where('subject_id', $salesReturn->id)->latest('created_at')->latest('id')->get();
        $logoPath = $settings->value('logo_path');
        $logo = $logoPath && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->path($logoPath) : null;
        $bytes = $renderer->renderView('Pos::pdf.sales-return', [
            'returnDocument' => $salesReturn,
            'history' => $history,
            'logo' => $logo,
            'companyName' => $settings->value('company_name') ?: 'บริษัท',
            'companyAddress' => $settings->value('company_address'),
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
            'decimalPlaces' => (int) ($settings->value('tax_decimal_places') ?? 2),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($salesReturn->document_number).'.pdf"',
        ]);
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }

    private function ensureCurrentBranch(Request $request, SalesReturn $salesReturn): void
    {
        abort_unless((int) $salesReturn->branch_id === $this->branchId($request), 404);
    }
}
