<?php

namespace App\Modules\Pos\Services;

use App\Models\PartyRole;
use App\Modules\Pos\Support\CreditLimitPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreditLimitService
{
    public function assertInvoiceWithinLimit(int $partyId, string $invoiceAmount): void
    {
        $role = PartyRole::query()
            ->where('party_id', $partyId)
            ->where('role', 'CUSTOMER')
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $role) {
            throw ValidationException::withMessages(['party_id' => 'ลูกค้าและบทบาทต้องเปิดใช้งาน']);
        }

        $asOf = now()->toDateString();
        $active = fn ($query) => $query
            ->where('allocation_date', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf));

        $debitAllocations = DB::table('finance_allocations')
            ->selectRaw('debit_open_item_id AS open_item_id, SUM(amount) AS allocated')
            ->groupBy('debit_open_item_id');
        $creditAllocations = DB::table('finance_allocations')
            ->selectRaw('credit_open_item_id AS open_item_id, SUM(amount) AS allocated')
            ->groupBy('credit_open_item_id');
        $active($debitAllocations);
        $active($creditAllocations);

        $exposure = DB::table('finance_open_items AS oi')
            ->leftJoinSub($debitAllocations, 'da', 'da.open_item_id', '=', 'oi.id')
            ->leftJoinSub($creditAllocations, 'ca', 'ca.open_item_id', '=', 'oi.id')
            ->where('oi.ledger_type', 'AR')
            ->where('oi.party_type', 'CUSTOMER')
            ->where('oi.party_id', $partyId)
            ->selectRaw("COALESCE(SUM(CASE WHEN oi.balance_side = 'DEBIT' THEN oi.original_amount - COALESCE(da.allocated, 0) ELSE -(oi.original_amount - COALESCE(ca.allocated, 0)) END), 0) AS exposure")
            ->value('exposure') ?? '0.00';

        try {
            CreditLimitPolicy::assertWithinLimit((string) $role->credit_limit, (string) $exposure, $invoiceAmount);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['party_id' => $exception->getMessage()]);
        }
    }
}
