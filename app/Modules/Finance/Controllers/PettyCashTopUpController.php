<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Warehouse;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\PettyCashFund;
use App\Modules\Finance\Models\PettyCashTopUp;
use App\Modules\Finance\Requests\PettyCashActionRequest;
use App\Modules\Finance\Requests\SavePettyCashTopUpRequest;
use App\Modules\Finance\Services\PettyCashTopUpService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class PettyCashTopUpController extends Controller
{
    public function index(Request $request): View
    {
        return view('Finance::petty-cash.top-ups.index', $this->options($request));
    }

    public function create(Request $request): View
    {
        return view('Finance::petty-cash.top-ups.form', [...$this->options($request), 'topUp' => new PettyCashTopUp(['document_date' => today()])]);
    }

    public function edit(Request $request, PettyCashTopUp $topUp): View
    {
        $this->scope($request, $topUp);
        abort_unless($topUp->status === 'DRAFT', 422, 'แก้ไขได้เฉพาะเอกสาร Draft');

        return view('Finance::petty-cash.top-ups.form', [...$this->options($request), 'topUp' => $topUp]);
    }

    public function data(Request $request): JsonResponse
    {
        $query = PettyCashTopUp::query()->with(['fund.cashBankAccount', 'sourceBankAccount'])
            ->where('warehouse_id', $this->warehouse($request)->id)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('petty_cash_fund_id'), fn (Builder $q) => $q->where('petty_cash_fund_id', $request->integer('petty_cash_fund_id')));
        $table = DataTables::eloquent($query)
            ->addColumn('document_date_label', fn (PettyCashTopUp $topUp) => $topUp->document_date?->format('d/m/Y'))
            ->addColumn('fund_label', fn (PettyCashTopUp $topUp) => $topUp->fund ? $topUp->fund->name.' · '.$topUp->fund->cashBankAccount?->code : '—')
            ->addColumn('source_label', fn (PettyCashTopUp $topUp) => $topUp->source_bank_account_code.' · '.$topUp->source_bank_account_name)
            ->addColumn('show_url', fn (PettyCashTopUp $topUp) => route('finance.petty-cash-top-ups.show', $topUp));
        if ($request->user()->hasPermission('finance.petty-cash-top-ups.update')) {
            $table->addColumn('edit_url', fn (PettyCashTopUp $topUp) => $topUp->status === 'DRAFT' ? route('finance.petty-cash-top-ups.edit', $topUp) : null);
        }
        foreach (['submit', 'approve', 'void', 'post', 'reverse'] as $action) {
            if ($request->user()->hasPermission("finance.petty-cash-top-ups.{$action}")) {
                $table->addColumn("{$action}_url", fn (PettyCashTopUp $topUp) => $this->allowed($topUp, $action) ? route("finance.petty-cash-top-ups.{$action}", $topUp) : null);
            }
        }

        return $table->toJson();
    }

    public function show(Request $request, PettyCashTopUp $topUp): View
    {
        $this->scope($request, $topUp);

        return view('Finance::petty-cash.top-ups.show', ['topUp' => $topUp->load(['fund.cashBankAccount', 'sourceBankAccount', 'journalEntry', 'reversalJournalEntry']), 'history' => AuditLog::query()->with('user')->where('subject_type', $topUp->getMorphClass())->where('subject_id', $topUp->id)->latest('created_at')->latest('id')->get()]);
    }

    public function store(SavePettyCashTopUpRequest $request, PettyCashTopUpService $service): JsonResponse
    {
        $topUp = $service->create($request->validated(), $this->warehouse($request), $this->sequence($request), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'สร้างเอกสารเติมเงินสดย่อยแล้ว', 'data' => $topUp, 'redirect' => route('finance.petty-cash-top-ups.show', $topUp)], 201);
    }

    public function update(SavePettyCashTopUpRequest $request, PettyCashTopUp $topUp, PettyCashTopUpService $service): JsonResponse
    {
        $this->scope($request, $topUp);

        $topUp = $service->update($topUp, $request->validated(), $this->warehouse($request), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'บันทึกเอกสารเติมเงินสดย่อยแล้ว', 'data' => $topUp, 'redirect' => route('finance.petty-cash-top-ups.show', $topUp)]);
    }

    public function submit(PettyCashActionRequest $r, PettyCashTopUp $topUp, PettyCashTopUpService $s): JsonResponse
    {
        return $this->action($r, $topUp, $s, 'submit');
    }

    public function approve(PettyCashActionRequest $r, PettyCashTopUp $topUp, PettyCashTopUpService $s): JsonResponse
    {
        return $this->action($r, $topUp, $s, 'approve');
    }
    public function reject(PettyCashActionRequest $r, PettyCashTopUp $topUp, PettyCashTopUpService $s): JsonResponse { $this->scope($r, $topUp); $topUp = $s->reject($topUp, $this->warehouse($r), (string) $r->validated()['reason'], $r->user(), $r); return response()->json(['status' => true, 'msg' => 'ไม่อนุมัติเอกสารเติมเงินแล้ว', 'data' => $topUp]); }
    public function destroy(Request $r, PettyCashTopUp $topUp, PettyCashTopUpService $s): JsonResponse { $this->scope($r, $topUp); $s->deleteDraft($topUp, $this->warehouse($r), $r->user(), $r); return response()->json(['status' => true, 'msg' => 'ลบเอกสาร Draft แล้ว', 'redirect' => route('finance.petty-cash-top-ups.index')]); }

    public function void(PettyCashActionRequest $r, PettyCashTopUp $topUp, PettyCashTopUpService $s): JsonResponse
    {
        return $this->action($r, $topUp, $s, 'void');
    }

    public function post(PettyCashActionRequest $r, PettyCashTopUp $topUp, PettyCashTopUpService $s): JsonResponse
    {
        return $this->action($r, $topUp, $s, 'post');
    }

    public function reverse(PettyCashActionRequest $r, PettyCashTopUp $topUp, PettyCashTopUpService $s): JsonResponse
    {
        return $this->action($r, $topUp, $s, 'reverse');
    }

    private function action(PettyCashActionRequest $r, PettyCashTopUp $topUp, PettyCashTopUpService $s, string $action): JsonResponse
    {
        $this->scope($r, $topUp);
        $v = $r->validated();
        $w = $this->warehouse($r);
        $topUp = match ($action) {
            'submit' => $s->submit($topUp, $w, $r->user(), $r), 'approve' => $s->approve($topUp, $w, $r->user(), $r), 'void' => $s->void($topUp, $w, (string) ($v['reason'] ?? ''), $r->user(), $r), 'post' => $s->post($topUp, $w, $r->user(), $r), 'reverse' => $s->reverse($topUp, $w, (string) $v['reversal_date'], (string) ($v['reason'] ?? ''), $r->user(), $r)
        };

        return response()->json(['status' => true, 'msg' => 'อัปเดตสถานะเอกสารเติมเงินสดย่อยแล้ว', 'data' => $topUp]);
    }

    private function options(Request $r): array
    {
        $w = $this->warehouse($r);

        return ['fundOptions' => PettyCashFund::query()->with('cashBankAccount')->where('warehouse_id', $w->id)->where('is_active', true)->get()->mapWithKeys(fn (PettyCashFund $f) => [$f->id => $f->name.' · '.$f->cashBankAccount?->code])->all(), 'sourceBankAccountOptions' => BankAccount::query()->where('warehouse_id', $w->id)->where('type', 'BANK')->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn (BankAccount $a) => [$a->id => $a->code.' · '.$a->name])->all()];
    }

    private function sequence(Request $r): DocumentSequence
    {
        $w = $this->warehouse($r);

        return DocumentSequence::query()->where('document_type', 'PETTY_CASH_TOP_UP')->where('is_active', true)->where(fn (Builder $q) => $q->where('warehouse_id', $w->id)->orWhereNull('warehouse_id'))->orderByRaw('warehouse_id IS NULL')->first() ?? throw ValidationException::withMessages(['document_sequence' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร PETTY_CASH_TOP_UP สำหรับคลังนี้']);
    }

    private function warehouse(Request $r): Warehouse
    {
        return $r->attributes->get('selectedWarehouse');
    }

    private function scope(Request $r, PettyCashTopUp $topUp): void
    {
        abort_unless((int) $topUp->warehouse_id === (int) $this->warehouse($r)->id, 404);
    }

    private function allowed(PettyCashTopUp $t, string $a): bool
    {
        return match ($a) {
            'submit' => $t->status === 'DRAFT', 'approve' => $t->status === 'SUBMITTED', 'void' => in_array($t->status, ['DRAFT', 'SUBMITTED', 'APPROVED'], true), 'post' => $t->status === 'APPROVED', 'reverse' => $t->status === 'POSTED'
        };
    }
}
