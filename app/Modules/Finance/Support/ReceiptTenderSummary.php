<?php

namespace App\Modules\Finance\Support;

use App\Modules\Accounting\Support\JournalBalance;
use Illuminate\Validation\ValidationException;

final class ReceiptTenderSummary
{
    /** @param list<array{amount:mixed}> $tenders */
    public static function forCashSale(string $amountDue, array $tenders, string $withholdingAmount = '0.00'): array
    {
        $due = JournalBalance::decimal($amountDue);
        $withholding = JournalBalance::decimal($withholdingAmount);
        $received = collect($tenders)->reduce(
            fn (string $total, array $tender): string => JournalBalance::add($total, JournalBalance::decimal($tender['amount'] ?? '0')),
            '0.00',
        );

        if ($due === '0.00' || $received === '0.00') {
            throw ValidationException::withMessages(['tenders' => 'ขายสดต้องระบุช่องทางและยอดรับชำระ']);
        }
        if ($withholding > $due) {
            throw ValidationException::withMessages(['withholding_amount' => 'ยอดหัก ณ ที่จ่ายต้องไม่เกินยอดขาย']);
        }
        $cashDue = JournalBalance::subtract($due, $withholding);
        if ($received < $cashDue) {
            throw ValidationException::withMessages(['tenders' => 'ยอดรับชำระไม่ครบ กรุณารับเพิ่มหรือเปลี่ยนเป็นขายเชื่อ']);
        }

        return ['due_amount' => $due, 'withholding_amount' => $withholding, 'cash_due_amount' => $cashDue, 'received_amount' => $received, 'allocated_amount' => $due, 'advance_amount' => JournalBalance::subtract($received, $cashDue)];
    }
}
