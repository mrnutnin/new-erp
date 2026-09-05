<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\PettyCashFund;
use App\Modules\Finance\Requests\SavePettyCashFundRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class PettyCashFundController extends Controller
{
    public function index(): View
    {
        return view('Finance::petty-cash-funds.index');
    }

    public function data(Request $request): JsonResponse
    {
        return DataTables::eloquent($this->query($request))
            ->addColumn('name', fn (PettyCashFund $fund) => $fund->name)
            ->addColumn('bank_account_label', fn (PettyCashFund $fund) => $fund->cashBankAccount?->code.' · '.$fund->cashBankAccount?->name)
            ->addColumn('custodian_label', fn (PettyCashFund $fund) => $fund->custodian?->name ?? '—')
            ->addColumn('edit_url', fn (PettyCashFund $fund) => route('finance.petty-cash-funds.edit', $fund))
            ->addColumn('delete_url', fn (PettyCashFund $fund) => route('finance.petty-cash-funds.destroy', $fund))
            ->toJson();
    }

    public function create(Request $request): View
    {
        return view('Finance::petty-cash-funds.form', ['fund' => new PettyCashFund(['is_active' => true]), ...$this->options($request)]);
    }

    public function edit(Request $request, PettyCashFund $fund): View
    {
        $fund = $this->fund($request, $fund);

        return view('Finance::petty-cash-funds.form', [
            'fund' => $fund,
            'auditLogs' => AuditLog::query()
                ->with('user:id,name')
                ->where('subject_type', $fund->getMorphClass())
                ->where('subject_id', $fund->getKey())
                ->latest('id')
                ->get(),
            ...$this->options($request),
        ]);
    }

    public function store(SavePettyCashFundRequest $request, AuditLogger $audit): JsonResponse
    {
        $fund = DB::transaction(function () use ($request, $audit): PettyCashFund {
            $values = $this->values($request);
            $fund = PettyCashFund::query()->create([...$values, 'warehouse_id' => $request->attributes->get('selectedWarehouse')->id, 'created_by' => $request->user()->id]);
            $audit->record('finance.petty_cash_fund.created', $fund, [], $fund->only(array_keys($values)), $request->user(), $request);

            return $fund;
        });

        return response()->json(['status' => true, 'msg' => 'สร้างวงเงินสดย่อยแล้ว', 'data' => $fund, 'redirect' => route('finance.petty-cash-funds.index')], 201);
    }

    public function update(SavePettyCashFundRequest $request, PettyCashFund $fund, AuditLogger $audit): JsonResponse
    {
        $fund = $this->fund($request, $fund);
        DB::transaction(function () use ($request, $fund, $audit): void {
            $values = $this->values($request);
            if (! $values['is_active'] && $fund->vouchers()->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->exists()) {
                throw ValidationException::withMessages(['is_active' => 'ปิดวงเงินไม่ได้ขณะที่ยังมีเอกสารที่ไม่สิ้นสุด']);
            }
            $before = $fund->only(array_keys($values));
            $fund->update($values);
            $audit->record('finance.petty_cash_fund.updated', $fund, $before, $fund->only(array_keys($values)), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'บันทึกวงเงินสดย่อยแล้ว']);
    }

    public function destroy(Request $request, PettyCashFund $fund, AuditLogger $audit): JsonResponse
    {
        $fund = $this->fund($request, $fund);
        if ($fund->vouchers()->withTrashed()->exists() || $fund->topUps()->withTrashed()->exists()) {
            return response()->json(['status' => false, 'msg' => 'ลบไม่ได้ เพราะวงเงินสดย่อยนี้เคยถูกอ้างอิงในเอกสารแล้ว'], 422);
        }
        DB::transaction(function () use ($request, $fund, $audit): void {
            $before = $fund->only(['warehouse_id', 'name', 'bank_account_id', 'custodian_user_id', 'fund_limit', 'is_active']);
            $fund->delete();
            $audit->record('finance.petty_cash_fund.deleted', $fund, $before, ['deleted_at' => $fund->deleted_at], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบวงเงินสดย่อยแล้ว']);
    }

    private function query(Request $request): Builder
    {
        $query = PettyCashFund::query()->with(['cashBankAccount', 'custodian'])
            ->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)
            ->orderByDesc('is_active')->orderBy('id');

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $query;
    }

    private function options(Request $request): array
    {
        $warehouseId = $request->attributes->get('selectedWarehouse')->id;

        return [
            'cashBankAccountOptions' => BankAccount::query()->where('warehouse_id', $warehouseId)->where('type', 'CASH')->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn (BankAccount $account) => [$account->id => $account->code.' · '.$account->name])->all(),
            'userOptions' => User::query()->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    private function values(SavePettyCashFundRequest $request): array
    {
        $values = $request->validated();
        $warehouseId = $request->attributes->get('selectedWarehouse')->id;
        BankAccount::query()->whereKey($values['bank_account_id'])->where('warehouse_id', $warehouseId)->where('type', 'CASH')->where('is_active', true)->firstOrFail();
        unset($values['warehouse_id']);

        return $values;
    }

    private function fund(Request $request, PettyCashFund $fund): PettyCashFund
    {
        abort_unless((int) $fund->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);

        return $fund;
    }
}
