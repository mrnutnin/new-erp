<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Requests\SavePaymentTermRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PaymentTermController extends Controller
{
    public function index(): View
    {
        return view('Finance::payment-terms.index');
    }

    public function data(Request $request): JsonResponse
    {
        $dataTable = DataTables::eloquent($this->paymentTermsQuery())
            ->filter(fn (Builder $query) => $this->applyTableSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyTableOrder($query, $request))
            ->addColumn('due_rule_label', fn (PaymentTerm $paymentTerm) => $paymentTerm->due_rule === 'END_OF_MONTH' ? 'สิ้นเดือน' : 'นับจากวันที่เอกสาร');

        if ($request->user()->hasPermission('finance.payment-terms.update')) {
            $dataTable->addColumn('edit_url', fn (PaymentTerm $paymentTerm) => route('finance.payment-terms.edit', $paymentTerm));
        }

        if ($request->user()->hasPermission('finance.payment-terms.delete')) {
            $dataTable->addColumn('delete_url', fn (PaymentTerm $paymentTerm) => route('finance.payment-terms.destroy', $paymentTerm));
        }

        return $dataTable->toJson();
    }

    public function create(): View
    {
        return view('Finance::payment-terms.form', ['paymentTerm' => new PaymentTerm(['is_active' => true])]);
    }

    public function edit(PaymentTerm $paymentTerm): View
    {
        return view('Finance::payment-terms.form', compact('paymentTerm'));
    }

    public function store(SavePaymentTermRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $term = PaymentTerm::create([...$request->validated(), 'created_by' => $request->user()->id]);
        $audit->record('finance.payment_term.created', $term, [], $term->only(['code', 'name', 'credit_days', 'due_rule', 'is_active']), $request->user(), $request);

        return $request->expectsJson() ? response()->json(['status' => true, 'msg' => 'เพิ่มเงื่อนไขการชำระเงินแล้ว', 'redirect' => route('finance.payment-terms.index')]) : redirect()->route('finance.payment-terms.index');
    }

    public function update(SavePaymentTermRequest $request, PaymentTerm $paymentTerm, AuditLogger $audit): JsonResponse
    {
        $before = $paymentTerm->only(['code', 'name', 'credit_days', 'due_rule', 'is_active']);
        $paymentTerm->update($request->validated());
        $audit->record('finance.payment_term.updated', $paymentTerm, $before, $paymentTerm->only(array_keys($before)), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'แก้ไขเงื่อนไขการชำระเงินแล้ว']);
    }

    public function destroy(Request $request, PaymentTerm $paymentTerm, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($paymentTerm, $audit, $request) {
            $before = $paymentTerm->only(['code', 'name', 'credit_days', 'due_rule', 'is_active']);
            $paymentTerm->delete();
            $audit->record('finance.payment_term.deleted', $paymentTerm, $before, ['deleted_at' => $paymentTerm->deleted_at], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบเงื่อนไขการชำระเงินแล้ว']);
    }

    private function paymentTermsQuery(): Builder
    {
        return PaymentTerm::query()->select([
            'finance_payment_terms.id', 'finance_payment_terms.code', 'finance_payment_terms.name',
            'finance_payment_terms.credit_days', 'finance_payment_terms.due_rule', 'finance_payment_terms.is_active',
        ]);
    }

    private function applyTableSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('finance_payment_terms.code', 'like', "%{$search}%")
                ->orWhere('finance_payment_terms.name', 'like', "%{$search}%")
                ->orWhere('finance_payment_terms.credit_days', 'like', "%{$search}%")
                ->orWhere('finance_payment_terms.due_rule', 'like', "%{$search}%"));
        }
    }

    private function applyTableOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'finance_payment_terms.code',
            1 => 'finance_payment_terms.name',
            2 => 'finance_payment_terms.credit_days',
            3 => 'finance_payment_terms.due_rule',
            4 => 'finance_payment_terms.is_active',
        ];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'finance_payment_terms.code';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $query->reorder($column, $direction)->orderBy('finance_payment_terms.id');
    }
}
