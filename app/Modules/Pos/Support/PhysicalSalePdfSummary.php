<?php

namespace App\Modules\Pos\Support;

use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Pos\Models\PhysicalSale;

final class PhysicalSalePdfSummary
{
    public static function build(PhysicalSale $sale): array
    {
        $deposits = $sale->advanceDepositApplications->whereNull('reversed_at')->values();
        $depositTotal = $deposits->reduce(fn ($sum, $row) => JournalBalance::add($sum, $row->amount), '0.00');
        $received = $sale->tenders->reduce(fn ($sum, $row) => JournalBalance::add($sum, $row->amount), '0.00');
        $net = JournalBalance::subtract(JournalBalance::subtract($sale->total_amount, $depositTotal), $sale->withholding_amount ?? '0.00');

        return [
            'deposits' => $deposits,
            'deposit_total' => $depositTotal,
            'received' => $received,
            'net' => $net,
            'remaining' => JournalBalance::subtract($net, $received),
        ];
    }
}
