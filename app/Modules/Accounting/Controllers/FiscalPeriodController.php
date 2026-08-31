<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Requests\ChangeFiscalPeriodStatusRequest;
use App\Modules\Accounting\Support\FiscalPeriodState;
use App\Modules\Accounting\Support\PeriodCloseGate;
use App\Modules\Platform\Services\AuditLogger;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalPeriodController extends Controller
{
    public function softClose(ChangeFiscalPeriodStatusRequest $request, FiscalPeriod $fiscalPeriod, AuditLogger $audit): JsonResponse
    {
        $this->changeStatus($request, $fiscalPeriod, $audit, 'softClose');

        return response()->json(['status' => true, 'msg' => 'Soft close งวดบัญชีแล้ว']);
    }

    public function reopen(ChangeFiscalPeriodStatusRequest $request, FiscalPeriod $fiscalPeriod, AuditLogger $audit): JsonResponse
    {
        $this->changeStatus($request, $fiscalPeriod, $audit, 'reopen');

        return response()->json(['status' => true, 'msg' => 'เปิดงวดบัญชีอีกครั้งแล้ว']);
    }

    public function lock(ChangeFiscalPeriodStatusRequest $request, FiscalPeriod $fiscalPeriod, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $fiscalPeriod, $audit) {
            $period = FiscalPeriod::query()->lockForUpdate()->findOrFail($fiscalPeriod->id);
            $failures = PeriodCloseGate::failures($period);
            if ($failures !== []) {
                throw ValidationException::withMessages(['status' => $failures]);
            }
            try {
                $status = FiscalPeriodState::lock($period->status);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['status' => $exception->getMessage()]);
            }
            $before = $period->only(['status', 'locked_by', 'locked_at', 'lock_reason']);
            $period->update(['status' => $status, 'locked_by' => $request->user()->id, 'locked_at' => now(), 'lock_reason' => $request->validated('reason')]);
            $audit->record('accounting.fiscal_period.locked', $period, $before, $period->only(array_keys($before)), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'Lock งวดบัญชีแล้ว']);
    }

    private function changeStatus(
        ChangeFiscalPeriodStatusRequest $request,
        FiscalPeriod $fiscalPeriod,
        AuditLogger $audit,
        string $transition,
    ): void {
        DB::transaction(function () use ($request, $fiscalPeriod, $audit, $transition) {
            $period = FiscalPeriod::query()->lockForUpdate()->findOrFail($fiscalPeriod->id);
            $before = $period->only(['status', 'closed_by', 'closed_at', 'close_reason', 'reopened_by', 'reopened_at', 'reopen_reason']);
            try {
                $status = FiscalPeriodState::{$transition}($period->status);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['status' => $exception->getMessage()]);
            }
            $reason = $request->validated('reason');

            $values = $transition === 'softClose'
                ? [
                    'status' => $status,
                    'closed_by' => $request->user()->id,
                    'closed_at' => now(),
                    'close_reason' => $reason,
                    'reopened_by' => null,
                    'reopened_at' => null,
                    'reopen_reason' => null,
                ]
                : [
                    'status' => $status,
                    'reopened_by' => $request->user()->id,
                    'reopened_at' => now(),
                    'reopen_reason' => $reason,
                ];

            $period->update($values);
            $audit->record(
                'accounting.fiscal_period.'.($transition === 'softClose' ? 'soft_closed' : 'reopened'),
                $period,
                $before,
                $period->only(array_keys($before)),
                $request->user(),
                $request,
            );
        });
    }
}
