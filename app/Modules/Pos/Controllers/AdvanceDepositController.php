<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\AdvanceDeposit;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Requests\RefundAdvanceDepositRequest;
use App\Modules\Pos\Services\AdvanceDepositPostingService;
use App\Modules\Pos\Services\AdvanceDepositRefundService;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class AdvanceDepositController extends Controller
{
    public function index(): View
    {
        return view('Pos::advance-deposits.index');
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $values = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'status' => ['nullable', Rule::in(['DRAFT', 'POSTED', 'PARTIAL', 'APPLIED', 'VOID'])], 'party_id' => ['nullable', 'integer']]);
        $format = (string) ($settings->value('date_format') ?: 'd/m/Y');
        $rows = $this->aiQuery((int) $request->attributes->get('selectedBranch')->id)->with([
            'party',
            'journalEntry',
            'applications.physicalSale',
            'applications.journalEntry',
        ])
            ->when($values['date_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('document_date', '>=', $date))
            ->when($values['date_to'] ?? null, fn (Builder $q, string $date) => $q->whereDate('document_date', '<=', $date))
            ->when($values['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($values['party_id'] ?? null, fn (Builder $q, int $id) => $q->where('party_id', $id));

        return DataTables::eloquent($rows)->order(fn (Builder $q) => $q->orderByDesc('document_date')->orderByDesc('id'))
            ->addColumn('document_date_label', fn (AdvanceDeposit $x) => $x->document_date?->format($format) ?: '—')
            ->addColumn('party_label', fn (AdvanceDeposit $x) => ($x->party?->code ?: '—').' · '.($x->party?->name ?: '—'))
            ->addColumn('remaining_amount', fn (AdvanceDeposit $x) => $this->remaining($x))
            ->addColumn('used_hs_label', function (AdvanceDeposit $x): string {
                return $x->applications->whereNull('reversed_at')->map(fn ($application) => ($application->physicalSale?->document_number ?: '—').' · '.number_format((float) $application->amount, 2))->implode("\n") ?: '—';
            })
            ->addColumn('gl_reference_label', function (AdvanceDeposit $x): string {
                return collect([$x->journalEntry?->entry_number])
                    ->merge($x->applications->whereNull('reversed_at')->map(fn ($application) => $application->journalEntry?->entry_number))
                    ->filter()
                    ->unique()
                    ->implode("\n") ?: '—';
            })
            ->addColumn('tax_treatment_label', fn (AdvanceDeposit $x) => $x->tax_treatment === 'NONE_VAT' ? 'ไม่มี VAT' : ($x->prices_include_vat ? 'รวม VAT' : 'VAT นอก'))
            ->addColumn('status_label', fn (AdvanceDeposit $x) => ['DRAFT' => 'ร่าง', 'POSTED' => 'พร้อมใช้', 'PARTIAL' => 'ใช้บางส่วน', 'APPLIED' => 'ใช้ครบ', 'VOID' => 'ยกเลิก'][$x->status] ?? $x->status)
            ->addColumn('show_url', fn (AdvanceDeposit $x) => route('pos.advance-deposits.show', $x))
            ->addColumn('pdf_url', fn (AdvanceDeposit $x) => $request->user()->hasPermission('pos.advance-deposits.print') ? route('pos.advance-deposits.pdf', $x) : null)->toJson();
    }

    public function create(Request $request): View
    {
        $warehouse = $request->attributes->get('selectedWarehouse');

        return view('Pos::advance-deposits.form', ['bankAccounts' => BankAccount::query()->where('warehouse_id', $warehouse->id)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']), 'vatTaxCodes' => TaxCode::query()->where('kind', 'VAT_OUT')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'rate']), 'whtTaxCodes' => TaxCode::query()->where('kind', 'WHT')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'rate'])]);
    }

    public function store(Request $request, AdvanceDepositPostingService $service, AuditLogger $audit): JsonResponse
    {
        $values = $request->validate(['party_id' => ['required', 'integer'], 'document_date' => ['required', 'date_format:Y-m-d'], 'receipt_date' => ['nullable', 'date_format:Y-m-d'], 'tax_treatment' => ['required', Rule::in(['VAT_INCLUSIVE', 'VAT_EXCLUSIVE', 'NONE'])], 'tax_code_id' => [Rule::requiredIf(fn (): bool => $request->input('tax_treatment') !== 'NONE'), 'nullable', 'integer', Rule::exists('tax_codes', 'id')->where(fn ($query) => $query->where('kind', 'VAT_OUT')->where('is_active', true))], 'gross_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'], 'withholding_tax_code_id' => ['nullable', 'integer'], 'withholding_base' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'], 'withholding_certificate_reference' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'], 'tenders' => ['required', 'array', 'min:1', 'max:20'], 'tenders.*.bank_account_id' => ['required', 'integer'], 'tenders.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'], 'tenders.*.reference' => ['nullable', 'string', 'max:100']]);
        if ($values['tax_treatment'] === 'NONE') {
            $values['tax_code_id'] = null;
        }
        $warehouse = $request->attributes->get('selectedWarehouse');
        $input = [...$values, 'original_amount' => $values['gross_amount'], 'tax_treatment' => $values['tax_treatment'] === 'NONE' ? 'NONE_VAT' : 'VAT_OUT', 'prices_include_vat' => $values['tax_treatment'] === 'VAT_INCLUSIVE'];
        $deposit = DB::transaction(function () use ($service, $input, $warehouse, $request, $audit): AdvanceDeposit {
            $deposit = $service->createDraft($input, $warehouse, $request->user());
            $deposit = $service->post($deposit, $input['receipt_date'] ?? $input['document_date'], $warehouse, $request->user(), $request);
            $audit->record('pos.advance-deposit.posted', $deposit, [], $deposit->fresh('tenders')->toArray(), $request->user(), $request);

            return $deposit;
        }, 3);

        return response()->json(['status' => true, 'msg' => "บันทึกใบรับเงินล่วงหน้า {$deposit->document_number} แล้ว", 'redirect' => route('pos.advance-deposits.show', $deposit)]);
    }

    public function show(Request $request, AdvanceDeposit $advanceDeposit, GlobalSettings $settings): View
    {
        $this->scope($request, $advanceDeposit);

        return view('Pos::advance-deposits.show', ['advanceDeposit' => $advanceDeposit->load(['party', 'tenders.bankAccount', 'journalEntry', 'applications.physicalSale', 'applications.journalEntry', 'applications.reversalJournalEntry']), 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'), 'remainingAmount' => $this->remaining($advanceDeposit)]);
    }

    public function refund(RefundAdvanceDepositRequest $request, AdvanceDeposit $advanceDeposit, AdvanceDepositRefundService $refunds): JsonResponse
    {
        $this->scope($request, $advanceDeposit);
        $deposit = $refunds->refund($advanceDeposit, $request->string('refund_date')->toString(), $request->string('reason')->toString(), $advanceDeposit->warehouse, $request->user(), $request);

        return response()->json(['status' => true, 'msg' => "คืนเงินใบรับเงินล่วงหน้า {$deposit->document_number} แล้ว", 'redirect' => route('pos.advance-deposits.show', $deposit)]);
    }

    public function pdf(Request $request, AdvanceDeposit $advanceDeposit, DocumentPdfRenderer $renderer, GlobalSettings $settings)
    {
        $this->scope($request, $advanceDeposit);
        $deposit = $advanceDeposit->load(['party', 'tenders.bankAccount']);
        $logoPath = $settings->value('logo_path');
        $bytes = $renderer->renderView('Pos::pdf.advance-deposit', ['advanceDeposit' => $deposit, 'companyName' => $settings->value('company_name') ?: 'บริษัท', 'companyAddress' => $settings->value('company_address'), 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'), 'logo' => $logoPath && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->path($logoPath) : null]);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.rawurlencode($deposit->document_number).'.pdf"']);
    }

    public function eligibleForPhysicalSale(Request $request, PhysicalSale $physicalSale): JsonResponse
    {
        abort_unless((int) $physicalSale->branch_id === (int) $request->attributes->get('selectedBranch')->id && $physicalSale->status === 'DRAFT' && $physicalSale->document_type === 'HS', 404);
        $rows = $this->aiQuery((int) $physicalSale->branch_id)->where('party_id', $physicalSale->party_id)->whereIn('status', ['POSTED', 'PARTIAL'])
            ->where('tax_treatment', $physicalSale->tax_treatment)->where('prices_include_vat', $physicalSale->prices_include_vat)
            ->whereRaw('original_amount > applied_amount')->orderBy('document_date')->orderBy('id')->get();

        return response()->json(['results' => $rows->map(fn (AdvanceDeposit $x) => ['id' => $x->id, 'document_number' => $x->document_number, 'remaining_amount' => $this->remaining($x), 'text' => $x->document_number.' · คงเหลือ '.number_format((float) $this->remaining($x), 2)])->values()]);
    }

    private function aiQuery(int $branchId): Builder
    {
        return AdvanceDeposit::query()->where('branch_id', $branchId)->where('party_type', 'CUSTOMER')->where('direction', 'RECEIPT')->where('instrument_type', 'DEPOSIT')->whereNull('source_settlement_id');
    }

    private function remaining(AdvanceDeposit $deposit): string
    {
        return JournalBalance::subtract($deposit->original_amount, $deposit->applied_amount);
    }

    private function scope(Request $request, AdvanceDeposit $deposit): void
    {
        abort_unless($this->aiQuery((int) $request->attributes->get('selectedBranch')->id)->whereKey($deposit->id)->exists(), 404);
    }
}
