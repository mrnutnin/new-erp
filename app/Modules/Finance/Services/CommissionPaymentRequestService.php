<?php

namespace App\Modules\Finance\Services;

use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Modules\Finance\Models\CommissionPaymentRequest;
use App\Modules\Finance\Models\EmployeeSupplier;
use App\Modules\Pos\Models\CommissionPaymentBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommissionPaymentRequestService
{
    public function create(CommissionPaymentBatch $batch, int $recipientId, User $actor): CommissionPaymentRequest
    {
        return DB::transaction(function () use ($batch, $recipientId, $actor): CommissionPaymentRequest {
            $batch = CommissionPaymentBatch::query()->with('lines.commissionRecord')->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== 'VERIFIED') {
                throw ValidationException::withMessages(['payment_batch' => 'สร้างใบขอจ่ายได้เฉพาะชุดที่ตรวจสอบแล้ว']);
            }
            $recipient = User::query()->lockForUpdate()->findOrFail($recipientId);
            $records = $batch->lines->pluck('commissionRecord')->filter(fn ($record) => $record && (int) $record->recipient_user_id === $recipientId && $record->status === 'APPROVED');
            if ($records->isEmpty()) {
                throw ValidationException::withMessages(['recipient_user_id' => 'ไม่พบรายการคอมมิชชั่นที่พร้อมสร้างใบขอจ่าย']);
            }
            $supplier = $this->supplierFor($recipient, $actor);
            $amount = $records->sum(fn ($record) => (float) $record->commission_amount);
            $request = CommissionPaymentRequest::query()->firstOrCreate(['payment_batch_id' => $batch->id, 'recipient_user_id' => $recipient->id], ['document_number' => 'CPR-TEMP-'.str()->upper(str()->random(12)), 'supplier_party_id' => $supplier->id, 'document_date' => today(), 'amount' => $amount, 'status' => 'DRAFT', 'created_by' => $actor->id]);
            if (str_starts_with($request->document_number, 'CPR-TEMP-')) {
                $request->update(['document_number' => 'CPR-'.str_pad((string) $request->id, 8, '0', STR_PAD_LEFT)]);
            }

            return $request->fresh(['supplier', 'recipient']);
        }, 3);
    }

    private function supplierFor(User $user, User $actor): Party
    {
        $code = 'EMP-'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT);
        $party = Party::query()->withTrashed()->lockForUpdate()->firstOrCreate(['code' => $code], ['name' => $user->name, 'type' => 'INDIVIDUAL', 'email' => $user->email, 'is_active' => true, 'created_by' => $actor->id]);
        if ($party->trashed()) {
            $party->restore();
        }
        $mapping = EmployeeSupplier::query()->with('party')->lockForUpdate()->firstOrCreate(['user_id' => $user->id], ['party_id' => $party->id, 'created_by' => $actor->id]);
        PartyRole::query()->updateOrCreate(['party_id' => $mapping->party_id, 'role' => 'SUPPLIER'], ['is_active' => true]);

        return $mapping->party()->firstOrFail();
    }
}
