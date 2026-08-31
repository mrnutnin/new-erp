<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\CommissionPaymentRequest;
use App\Modules\Pos\Models\CommissionPaymentBatch;
use App\Modules\Pos\Models\CommissionRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommissionPaymentBatchService
{
    public function create(int $branchId, string $from, string $to, array $recipientIds, User $actor): CommissionPaymentBatch
    {
        return DB::transaction(function () use ($branchId, $from, $to, $recipientIds, $actor): CommissionPaymentBatch {
            $records = CommissionRecord::query()->where('branch_id', $branchId)->where('status', 'APPROVED')
                ->whereBetween('calculated_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->when($recipientIds !== [], fn ($query) => $query->whereIn('recipient_user_id', $recipientIds))
                ->whereDoesntHave('paymentBatchLines.batch', fn ($query) => $query->whereIn('status', ['DRAFT', 'SUBMITTED']))
                ->lockForUpdate()->get();
            if ($records->isEmpty()) {
                throw ValidationException::withMessages(['period_from' => 'ไม่พบรายการคอมมิชชั่นที่อนุมัติแล้วตามเงื่อนไข']);
            }
            $total = $records->reduce(fn (string $sum, CommissionRecord $record): string => JournalBalance::add($sum, $record->commission_amount), '0.00');
            if ($total === '0.00' || str_starts_with($total, '-')) {
                throw ValidationException::withMessages(['period_from' => 'ยอดคอมมิชชั่นสุทธิของชุดจ่ายต้องมากกว่าศูนย์']);
            }
            $batch = CommissionPaymentBatch::query()->create(['document_number' => 'CB-TEMP-'.str()->upper(str()->random(12)), 'branch_id' => $branchId, 'period_from' => $from, 'period_to' => $to, 'total_amount' => $total, 'status' => 'DRAFT', 'created_by' => $actor->id]);
            $batch->update(['document_number' => 'CB-'.str_pad((string) $batch->id, 8, '0', STR_PAD_LEFT)]);
            foreach ($records as $record) {
                $batch->lines()->create(['commission_record_id' => $record->id, 'amount' => $record->commission_amount]);
            }

            return $batch->fresh(['lines.commissionRecord.recipient']);
        }, 3);
    }

    public function submit(CommissionPaymentBatch $batch, User $actor): CommissionPaymentBatch
    {
        return DB::transaction(function () use ($batch, $actor): CommissionPaymentBatch {
            $batch = CommissionPaymentBatch::query()->with('lines.commissionRecord')->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'SUBMITTED') {
                return $batch;
            }
            if ($batch->status !== 'DRAFT' || $batch->lines->isEmpty() || $batch->lines->contains(fn ($line) => $line->commissionRecord?->status !== 'APPROVED')) {
                throw ValidationException::withMessages(['payment_batch' => 'ส่งให้ฝ่ายการเงินได้เฉพาะชุดร่างที่มีรายการคอมมิชชั่นอนุมัติครบ']);
            }
            $batch->update(['status' => 'SUBMITTED', 'submitted_by' => $actor->id, 'submitted_at' => now()]);

            return $batch->fresh(['lines.commissionRecord.recipient']);
        }, 3);
    }

    public function cancelDraft(CommissionPaymentBatch $batch, User $actor, string $reason): CommissionPaymentBatch
    {
        return $this->cancel($batch, ['DRAFT'], $actor, $reason, 'POS');
    }

    public function cancelForFinance(CommissionPaymentBatch $batch, User $actor, string $reason): CommissionPaymentBatch
    {
        return $this->cancel($batch, ['SUBMITTED', 'VERIFIED'], $actor, $reason, 'FINANCE');
    }

    /** @param list<string> $allowedStatuses */
    private function cancel(CommissionPaymentBatch $batch, array $allowedStatuses, User $actor, string $reason, string $source): CommissionPaymentBatch
    {
        return DB::transaction(function () use ($batch, $allowedStatuses, $actor, $reason, $source): CommissionPaymentBatch {
            $batch = CommissionPaymentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! in_array($batch->status, $allowedStatuses, true)) {
                throw ValidationException::withMessages(['payment_batch' => 'สถานะเอกสารนี้ไม่สามารถยกเลิกได้']);
            }
            if (CommissionPaymentRequest::query()->where('payment_batch_id', $batch->id)->whereHas('voucher', fn ($query) => $query->where('status', '!=', 'VOID'))->exists()) {
                throw ValidationException::withMessages(['payment_batch' => 'ไม่สามารถยกเลิกชุดจ่ายได้จนกว่าจะยกเลิกใบสำคัญจ่ายทั้งหมด']);
            }
            if (CommissionPaymentRequest::query()->where('payment_batch_id', $batch->id)->where('status', '!=', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages(['payment_batch' => 'ไม่สามารถยกเลิกชุดจ่ายได้จนกว่าจะยกเลิกใบขอจ่ายคอมมิชชั่นทั้งหมด']);
            }
            $batch->update(['status' => 'CANCELLED', 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'cancellation_source' => $source]);

            return $batch->fresh();
        }, 3);
    }

    public function verify(CommissionPaymentBatch $batch): CommissionPaymentBatch
    {
        return DB::transaction(function () use ($batch): CommissionPaymentBatch {
            $batch = CommissionPaymentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'VERIFIED') {
                return $batch;
            }
            if ($batch->status !== 'SUBMITTED') {
                throw ValidationException::withMessages(['payment_batch' => 'ตรวจสอบได้เฉพาะชุดที่ POS ส่งให้ฝ่ายการเงินแล้ว']);
            }
            $batch->update(['status' => 'VERIFIED']);

            return $batch->fresh();
        }, 3);
    }
}
