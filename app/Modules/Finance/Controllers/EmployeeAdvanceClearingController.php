<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Models\EmployeeAdvanceClearing;
use App\Modules\Finance\Models\OtherCategory;
use App\Modules\Finance\Requests\PettyCashActionRequest;
use App\Modules\Finance\Requests\SaveEmployeeAdvanceClearingRequest;
use App\Modules\Finance\Services\EmployeeAdvanceClearingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class EmployeeAdvanceClearingController extends Controller
{
    public function index(): View { return view('Finance::employee-advance-clearings.index'); }
    public function data(Request $request): JsonResponse
    {
        $query = EmployeeAdvanceClearing::query()->with('advance.employee')->where('warehouse_id', $this->warehouse($request)->id)->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')));
        return DataTables::eloquent($query)->order(fn (Builder $q) => $q->reorder('document_date', 'desc')->orderByDesc('id'))->addColumn('advance_label', fn (EmployeeAdvanceClearing $c) => ($c->advance?->document_number ?: '—').' · '.($c->advance?->employee?->name ?: '—'))->addColumn('date_label', fn (EmployeeAdvanceClearing $c) => $c->document_date?->format('d/m/Y'))->addColumn('show_url', fn (EmployeeAdvanceClearing $c) => route('finance.employee-advance-clearings.show', $c))->toJson();
    }
    public function create(Request $request): View { return view('Finance::employee-advance-clearings.form', [...$this->options($request), 'clearing' => new EmployeeAdvanceClearing(['document_date' => today()]), 'lines' => [['amount' => null]]]); }
    public function store(SaveEmployeeAdvanceClearingRequest $request, EmployeeAdvanceClearingService $service): JsonResponse { $c = $service->save(null, $request->validated(), $this->warehouse($request), $this->sequence($request), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'สร้างใบเคลียร์เงินทดรองแล้ว', 'data' => $c, 'redirect' => route('finance.employee-advance-clearings.show', $c)], 201); }
    public function edit(Request $request, EmployeeAdvanceClearing $clearing): View { $this->scope($request, $clearing); abort_unless($clearing->status === 'DRAFT', 404); return view('Finance::employee-advance-clearings.form', [...$this->options($request), 'clearing' => $clearing, 'lines' => $clearing->load('lines')->lines->map(fn ($line) => $line->toArray())->all()]); }
    public function update(SaveEmployeeAdvanceClearingRequest $request, EmployeeAdvanceClearing $clearing, EmployeeAdvanceClearingService $service): JsonResponse { $this->scope($request, $clearing); $c = $service->save($clearing, $request->validated(), $this->warehouse($request), $this->sequence($request), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'บันทึกแก้ไขใบเคลียร์เงินทดรองแล้ว', 'data' => $c, 'redirect' => route('finance.employee-advance-clearings.show', $c)]); }
    public function show(Request $request, EmployeeAdvanceClearing $clearing): View { $this->scope($request, $clearing); return view('Finance::employee-advance-clearings.show', ['clearing' => $clearing->load(['advance.employee', 'lines', 'journalEntry', 'reversalJournalEntry']), 'history' => AuditLog::query()->with('user')->where('subject_type', $clearing->getMorphClass())->where('subject_id', $clearing->id)->latest('created_at')->latest('id')->get(), 'labels' => $this->labels(), 'classes' => $this->classes()]); }
    public function submit(PettyCashActionRequest $request, EmployeeAdvanceClearing $clearing, EmployeeAdvanceClearingService $service): JsonResponse { return $this->action($request, $clearing, $service, 'submit'); }
    public function approve(PettyCashActionRequest $request, EmployeeAdvanceClearing $clearing, EmployeeAdvanceClearingService $service): JsonResponse { return $this->action($request, $clearing, $service, 'approve'); }
    public function reject(PettyCashActionRequest $request, EmployeeAdvanceClearing $clearing, EmployeeAdvanceClearingService $service): JsonResponse { return $this->action($request, $clearing, $service, 'reject'); }
    public function destroy(Request $request, EmployeeAdvanceClearing $clearing, EmployeeAdvanceClearingService $service): JsonResponse { $this->scope($request, $clearing); $service->deleteDraft($clearing, $this->warehouse($request), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'ลบเอกสาร Draft แล้ว', 'redirect' => route('finance.employee-advance-clearings.index')]); }
    public function void(PettyCashActionRequest $request, EmployeeAdvanceClearing $clearing, EmployeeAdvanceClearingService $service): JsonResponse { return $this->action($request, $clearing, $service, 'void'); }
    public function post(PettyCashActionRequest $request, EmployeeAdvanceClearing $clearing, EmployeeAdvanceClearingService $service): JsonResponse { $this->scope($request, $clearing); $c = $service->post($clearing, $this->warehouse($request), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'ลงบัญชีใบเคลียร์เงินทดรองแล้ว', 'data' => $c]); }
    public function reverse(PettyCashActionRequest $request, EmployeeAdvanceClearing $clearing, EmployeeAdvanceClearingService $service): JsonResponse { $this->scope($request, $clearing); $c = $service->reverse($clearing, $this->warehouse($request), (string) $request->validated()['reversal_date'], (string) ($request->validated()['reason'] ?? ''), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'ยกเลิก GL ใบเคลียร์เงินทดรองแล้ว', 'data' => $c]); }
    private function action(PettyCashActionRequest $request, EmployeeAdvanceClearing $clearing, EmployeeAdvanceClearingService $service, string $action): JsonResponse { $this->scope($request, $clearing); $c = $service->transition($clearing, $this->warehouse($request), $action, $request->user(), $request); return response()->json(['status' => true, 'msg' => 'อัปเดตสถานะใบเคลียร์เงินทดรองแล้ว', 'data' => $c]); }
    private function options(Request $request): array { return ['categoryOptions' => OtherCategory::query()->with('account')->where('kind', 'EXPENSE')->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($c) => [$c->id => $c->code.' · '.$c->name])->all(), 'taxOptions' => TaxCode::query()->where('kind', 'VAT_IN')->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($t) => [$t->id => $t->code.' · '.$t->name])->all(), 'whtOptions' => TaxCode::query()->where('kind', 'WHT')->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($t) => [$t->id => $t->code.' · '.$t->name])->all(), 'advances' => EmployeeAdvance::query()->with('employee')->where('warehouse_id', $this->warehouse($request)->id)->whereIn('status', ['POSTED', 'PARTIAL'])->orderByDesc('document_date')->get()]; }
    private function sequence(Request $request): DocumentSequence { $w = $this->warehouse($request); return DocumentSequence::query()->where('document_type', 'EMPLOYEE_ADVANCE_CLEARING')->where('is_active', true)->where(fn (Builder $q) => $q->where('warehouse_id', $w->id)->orWhereNull('warehouse_id'))->orderByRaw('warehouse_id IS NULL')->firstOrFail(); }
    private function scope(Request $request, EmployeeAdvanceClearing $c): void { abort_unless((int) $c->warehouse_id === (int) $this->warehouse($request)->id, 404); }
    private function warehouse(Request $request) { return $request->attributes->get('selectedWarehouse'); }
    private function labels(): array { return ['DRAFT' => 'ร่าง', 'SUBMITTED' => 'รออนุมัติ', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'CLEARED' => 'เคลียร์แล้ว', 'VOID' => 'ยกเลิก', 'REVERSED' => 'ยกเลิกรายการแล้ว']; }
    private function classes(): array { return ['DRAFT' => 'app-status-neutral', 'SUBMITTED' => 'app-status-info', 'APPROVED' => 'app-status-success', 'POSTED' => 'app-status-success', 'CLEARED' => 'app-status-success', 'VOID' => 'app-status-danger', 'REVERSED' => 'app-status-danger']; }
}
