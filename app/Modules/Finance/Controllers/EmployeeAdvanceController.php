<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Requests\PettyCashActionRequest;
use App\Modules\Finance\Requests\SaveEmployeeAdvanceRequest;
use App\Modules\Finance\Services\EmployeeAdvanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class EmployeeAdvanceController extends Controller
{
    public function index(Request $request): View { return view('Finance::employee-advances.index'); }

    public function data(Request $request): JsonResponse
    {
        $query = EmployeeAdvance::query()->with(['employee', 'bankAccount'])->where('warehouse_id', $this->warehouse($request)->id)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')));

        return DataTables::eloquent($query)->order(fn (Builder $q) => $q->reorder('document_date', 'desc')->orderByDesc('id'))
            ->addColumn('employee_label', fn (EmployeeAdvance $a) => ($a->employee?->employee_code ?: '—').' · '.($a->employee?->name ?: '—'))
            ->addColumn('document_date_label', fn (EmployeeAdvance $a) => $a->document_date?->format('d/m/Y'))
            ->addColumn('bank_account_label', fn (EmployeeAdvance $a) => ($a->bankAccount?->code ?: '—').' · '.($a->bankAccount?->name ?: '—'))
            ->addColumn('status_label', fn (EmployeeAdvance $a) => $this->statusLabel($a->status))
            ->addColumn('status_class', fn (EmployeeAdvance $a) => $this->statusClass($a->status))
            ->addColumn('show_url', fn (EmployeeAdvance $a) => route('finance.employee-advances.show', $a))
            ->addColumn('edit_url', fn (EmployeeAdvance $a) => $a->status === 'DRAFT' && $request->user()->hasPermission('finance.employee-advances.update') ? route('finance.employee-advances.edit', $a) : null)
            ->toJson();
    }

    public function create(Request $request): View { return view('Finance::employee-advances.form', [...$this->options($request), 'advance' => new EmployeeAdvance(['document_date' => today()])]); }

    public function edit(Request $request, EmployeeAdvance $advance): View
    {
        $this->scope($request, $advance);
        abort_unless($advance->status === 'DRAFT', 422, 'แก้ไขได้เฉพาะเอกสาร Draft');

        return view('Finance::employee-advances.form', [...$this->options($request), 'advance' => $advance]);
    }

    public function show(Request $request, EmployeeAdvance $advance): View
    {
        $this->scope($request, $advance);

        return view('Finance::employee-advances.show', ['advance' => $advance->load(['employee', 'bankAccount', 'journalEntry', 'reversalJournalEntry']), 'history' => AuditLog::query()->with('user')->where('subject_type', $advance->getMorphClass())->where('subject_id', $advance->id)->latest('created_at')->latest('id')->get(), 'labels' => $this->statusLabels(), 'classes' => $this->statusClasses()]);
    }

    public function store(SaveEmployeeAdvanceRequest $request, EmployeeAdvanceService $service): JsonResponse
    {
        $advance = $service->create($request->validated(), $this->warehouse($request), $this->sequence($request), $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'สร้างใบเงินทดรองจ่ายแล้ว', 'data' => $advance, 'redirect' => route('finance.employee-advances.show', $advance)], 201);
    }

    public function update(SaveEmployeeAdvanceRequest $request, EmployeeAdvance $advance, EmployeeAdvanceService $service): JsonResponse
    {
        $this->scope($request, $advance);
        $advance = $service->update($advance, $request->validated(), $this->warehouse($request), $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'บันทึกใบเงินทดรองจ่ายแล้ว', 'data' => $advance, 'redirect' => route('finance.employee-advances.show', $advance)]);
    }

    public function submit(PettyCashActionRequest $request, EmployeeAdvance $advance, EmployeeAdvanceService $service): JsonResponse { return $this->action($request, $advance, $service, 'submit'); }
    public function approve(PettyCashActionRequest $request, EmployeeAdvance $advance, EmployeeAdvanceService $service): JsonResponse { return $this->action($request, $advance, $service, 'approve'); }
    public function reject(PettyCashActionRequest $request, EmployeeAdvance $advance, EmployeeAdvanceService $service): JsonResponse { $this->scope($request, $advance); $advance = $service->reject($advance, $this->warehouse($request), (string) $request->validated()['reason'], $request->user(), $request); return response()->json(['status' => true, 'msg' => 'ไม่อนุมัติใบเงินทดรองจ่ายแล้ว', 'data' => $advance]); }
    public function destroy(Request $request, EmployeeAdvance $advance, EmployeeAdvanceService $service): JsonResponse { $this->scope($request, $advance); $service->deleteDraft($advance, $this->warehouse($request), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'ลบเอกสาร Draft แล้ว', 'redirect' => route('finance.employee-advances.index')]); }
    public function void(PettyCashActionRequest $request, EmployeeAdvance $advance, EmployeeAdvanceService $service): JsonResponse { return $this->action($request, $advance, $service, 'void'); }
    public function post(PettyCashActionRequest $request, EmployeeAdvance $advance, EmployeeAdvanceService $service): JsonResponse { return $this->action($request, $advance, $service, 'post'); }
    public function reverse(PettyCashActionRequest $request, EmployeeAdvance $advance, EmployeeAdvanceService $service): JsonResponse { return $this->action($request, $advance, $service, 'reverse'); }

    private function action(PettyCashActionRequest $request, EmployeeAdvance $advance, EmployeeAdvanceService $service, string $action): JsonResponse
    {
        $this->scope($request, $advance);
        $advance = match ($action) {
            'submit' => $service->submit($advance, $this->warehouse($request), $request->user(), $request),
            'approve' => $service->approve($advance, $this->warehouse($request), $request->user(), $request),
            'void' => $service->void($advance, $this->warehouse($request), (string) ($request->validated()['reason'] ?? ''), $request->user(), $request),
            'post' => $service->post($advance, $this->warehouse($request), $request->user(), $request),
            'reverse' => $service->reverse($advance, $this->warehouse($request), (string) $request->validated()['reversal_date'], (string) ($request->validated()['reason'] ?? ''), $request->user(), $request),
        };
        return response()->json(['status' => true, 'msg' => 'อัปเดตสถานะใบเงินทดรองจ่ายแล้ว', 'data' => $advance]);
    }

    private function options(Request $request): array
    {
        $warehouse = $this->warehouse($request);
        return ['userOptions' => User::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (User $u) => [$u->id => ($u->employee_code ?: '—').' · '.$u->name])->all(), 'bankAccountOptions' => BankAccount::query()->where('warehouse_id', $warehouse->id)->whereIn('type', ['BANK', 'CASH'])->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn (BankAccount $a) => [$a->id => $a->code.' · '.$a->name])->all()];
    }

    private function sequence(Request $request): DocumentSequence
    {
        $warehouse = $this->warehouse($request);
        return DocumentSequence::query()->where('document_type', 'EMPLOYEE_ADVANCE')->where('is_active', true)->where(fn (Builder $q) => $q->where('warehouse_id', $warehouse->id)->orWhereNull('warehouse_id'))->orderByRaw('warehouse_id IS NULL')->firstOrFail();
    }

    private function scope(Request $request, EmployeeAdvance $advance): void { abort_unless((int) $advance->warehouse_id === (int) $this->warehouse($request)->id, 404); }
    private function warehouse(Request $request) { return $request->attributes->get('selectedWarehouse'); }
    private function statusLabels(): array { return ['DRAFT' => 'ร่าง', 'SUBMITTED' => 'รออนุมัติ', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'PARTIAL' => 'ตัดบางส่วน', 'CLEARED' => 'เคลียร์แล้ว', 'VOID' => 'ยกเลิก', 'REVERSED' => 'ยกเลิกรายการแล้ว']; }
    private function statusClasses(): array { return ['DRAFT' => 'app-status-neutral', 'SUBMITTED' => 'app-status-info', 'APPROVED' => 'app-status-success', 'POSTED' => 'app-status-success', 'PARTIAL' => 'app-status-info', 'CLEARED' => 'app-status-success', 'VOID' => 'app-status-danger', 'REVERSED' => 'app-status-danger']; }
    private function statusLabel(string $status): string { return $this->statusLabels()[$status] ?? $status; }
    private function statusClass(string $status): string { return $this->statusClasses()[$status] ?? 'app-status-neutral'; }
}
