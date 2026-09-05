<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Warehouse;
use App\Modules\Finance\Models\PettyCashClearing;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\PettyCashFund;
use App\Modules\Finance\Requests\PettyCashActionRequest;
use App\Modules\Finance\Requests\SavePettyCashClearingRequest;
use App\Modules\Finance\Services\PettyCashClearingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class PettyCashClearingController extends Controller
{
    public function index(Request $request): View { return view('Finance::petty-cash-clearings.index', $this->options($request)); }
    public function create(Request $request): View { return view('Finance::petty-cash-clearings.form', [...$this->options($request), 'clearing' => new PettyCashClearing(['clearing_date' => today()])]); }
    public function edit(Request $request, PettyCashClearing $clearing): View { $this->scope($request, $clearing); abort_unless($clearing->status === 'DRAFT', 422); return view('Finance::petty-cash-clearings.form', [...$this->options($request), 'clearing' => $clearing]); }
    public function show(Request $request, PettyCashClearing $clearing): View { $this->scope($request, $clearing); return view('Finance::petty-cash-clearings.show', ['clearing' => $clearing->load(['fund.cashBankAccount', 'journalEntry', 'reversalJournalEntry']), 'history' => AuditLog::query()->with('user')->where('subject_type', $clearing->getMorphClass())->where('subject_id', $clearing->id)->latest('created_at')->latest('id')->get()]); }
    public function data(Request $request): JsonResponse
    {
        $query = PettyCashClearing::query()->with('fund.cashBankAccount')->where('warehouse_id', $this->warehouse($request)->id)->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))->when($request->filled('petty_cash_fund_id'), fn (Builder $q) => $q->where('petty_cash_fund_id', $request->integer('petty_cash_fund_id')));
        return DataTables::eloquent($query)->addColumn('document_number', fn (PettyCashClearing $c) => $c->document_number ?: '—')->addColumn('clearing_date_label', fn (PettyCashClearing $c) => $c->clearing_date?->format('d/m/Y'))->addColumn('fund_label', fn (PettyCashClearing $c) => $c->fund ? $c->fund->name.' · '.$c->fund->cashBankAccount?->code : '—')->addColumn('show_url', fn (PettyCashClearing $c) => route('finance.petty-cash-clearings.show', $c))->addColumn('edit_url', fn (PettyCashClearing $c) => $c->status === 'DRAFT' && $request->user()->hasPermission('finance.petty-cash-clearings.update') ? route('finance.petty-cash-clearings.edit', $c) : null)->toJson();
    }
    public function store(SavePettyCashClearingRequest $request, PettyCashClearingService $service): JsonResponse { $c = $service->save(null, $request->validated(), $this->warehouse($request), $request->user(), $request, $this->sequence($request)); return response()->json(['status' => true, 'msg' => 'สร้างร่างเอกสารเคลียร์เงินสดย่อยแล้ว', 'redirect' => route('finance.petty-cash-clearings.show', $c)], 201); }
    public function update(SavePettyCashClearingRequest $request, PettyCashClearing $clearing, PettyCashClearingService $service): JsonResponse { $this->scope($request, $clearing); $c = $service->save($clearing, $request->validated(), $this->warehouse($request), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'บันทึกเอกสารเคลียร์เงินสดย่อยแล้ว', 'redirect' => route('finance.petty-cash-clearings.show', $c)]); }
    public function submit(PettyCashActionRequest $request, PettyCashClearing $clearing, PettyCashClearingService $service): JsonResponse { return $this->action($request, $clearing, $service, 'submit'); }
    public function approve(PettyCashActionRequest $request, PettyCashClearing $clearing, PettyCashClearingService $service): JsonResponse { return $this->action($request, $clearing, $service, 'approve'); }
    public function reject(PettyCashActionRequest $request, PettyCashClearing $clearing, PettyCashClearingService $service): JsonResponse { return $this->action($request, $clearing, $service, 'reject'); }
    public function destroy(Request $request, PettyCashClearing $clearing, PettyCashClearingService $service): JsonResponse { $this->scope($request, $clearing); $service->deleteDraft($clearing, $this->warehouse($request), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'ลบเอกสาร Draft แล้ว', 'redirect' => route('finance.petty-cash-clearings.index')]); }
    public function void(PettyCashActionRequest $request, PettyCashClearing $clearing, PettyCashClearingService $service): JsonResponse { return $this->action($request, $clearing, $service, 'void'); }
    public function post(PettyCashActionRequest $request, PettyCashClearing $clearing, PettyCashClearingService $service): JsonResponse { $this->scope($request, $clearing); $c = $service->post($clearing, $this->warehouse($request), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'ลงบัญชีเอกสารเคลียร์เงินสดย่อยแล้ว', 'data' => $c]); }
    public function reverse(PettyCashActionRequest $request, PettyCashClearing $clearing, PettyCashClearingService $service): JsonResponse { $this->scope($request, $clearing); $v = $request->validated(); $c = $service->reverse($clearing, $this->warehouse($request), (string) $v['reversal_date'], (string) ($v['reason'] ?? ''), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'กลับรายการ GL ของเอกสารเคลียร์เงินสดย่อยแล้ว', 'data' => $c]); }
    private function action(PettyCashActionRequest $request, PettyCashClearing $clearing, PettyCashClearingService $service, string $action): JsonResponse { $this->scope($request, $clearing); $c = $service->transition($clearing, $this->warehouse($request), $action, (string) ($request->validated()['reason'] ?? ''), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'อัปเดตสถานะเอกสารเคลียร์เงินสดย่อยแล้ว', 'data' => $c]); }
    private function options(Request $request): array
    {
        $w = $this->warehouse($request);
        $funds = PettyCashFund::query()->with('cashBankAccount')->where('warehouse_id', $w->id)->where('is_active', true)->get();

        return [
            'fundOptions' => $funds->mapWithKeys(fn (PettyCashFund $f) => [$f->id => $f->name.' · '.$f->cashBankAccount?->code])->all(),
            'expectedOptions' => $funds->mapWithKeys(fn (PettyCashFund $f) => [$f->id => number_format((float) $f->topUps()->where('status', 'POSTED')->sum('amount') - (float) $f->vouchers()->where('status', 'POSTED')->sum('total_amount'), 2, '.', '')])->all(),
        ];
    }
    private function warehouse(Request $request): Warehouse { return $request->attributes->get('selectedWarehouse'); }
    private function sequence(Request $request): DocumentSequence { $w = $this->warehouse($request); return DocumentSequence::query()->where('document_type', 'PETTY_CASH_CLEARING')->where('is_active', true)->where(fn (Builder $q) => $q->where('warehouse_id', $w->id)->orWhereNull('warehouse_id'))->orderByRaw('warehouse_id IS NULL')->first() ?? throw ValidationException::withMessages(['document_sequence' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร PETTY_CASH_CLEARING สำหรับคลังนี้']); }
    private function scope(Request $request, PettyCashClearing $clearing): void { abort_unless((int) $clearing->warehouse_id === (int) $this->warehouse($request)->id, 404); }
}
